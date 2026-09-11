import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { clone as cloneSkeleton } from 'three/addons/utils/SkeletonUtils.js';
import { AnimationClip } from 'three';

/**
 * The modelled cast and rooms.
 *
 * Two files: one holding every character rig, one holding every room. Both are
 * loaded once and cached by the browser, and each scene takes owned copies of
 * the two people and the one room it needs. Sharing the download across the
 * whole course is the point — a learner who plays four scenes fetches the kit
 * once rather than four times.
 *
 * Nothing here is required. When a file is missing or will not parse, the
 * player falls back to the figures and rooms it builds itself; a scene with
 * plain geometry still teaches, and a scene that never loads does not.
 */

/** The motions baked into each rig's timeline, in order. */
export const MOTIONS = [
    'idle', 'walking', 'sitting', 'standing', 'talking',
    'listening', 'pointing', 'thinking', 'greeting',
];

const SECONDS_PER_MOTION = 2;
const FPS = 24;

export class SceneKit {
    /**
     * @param {{cast:?string, rooms:?string}} urls
     */
    constructor(urls = {}) {
        this.urls = urls;
        this.cast = null;
        this.rooms = null;
        this.errors = [];
    }

    /** Load whatever is available. Never rejects: a missing kit is not an error. */
    async load() {
        const loader = new GLTFLoader();

        const [cast, rooms] = await Promise.all([
            this.tryLoad(loader, this.urls.cast, 'cast'),
            this.tryLoad(loader, this.urls.rooms, 'rooms'),
        ]);

        this.cast = cast;
        this.rooms = rooms;
        return this;
    }

    async tryLoad(loader, url, what) {
        if (!url) return null;
        try {
            return await loader.loadAsync(url);
        } catch (error) {
            this.errors.push(`${what}: ${error.message || error}`);
            return null;
        }
    }

    get hasCast() {
        return Boolean(this.cast);
    }

    get hasRooms() {
        return Boolean(this.rooms);
    }

    /**
     * One character, with geometry, materials and skeleton of its own.
     *
     * Two people on the same stage must not share a skeleton: posing one would
     * pose the other. SkeletonUtils.clone rebuilds the bone hierarchy, and the
     * traverse below gives each copy its own geometry and materials so a scene
     * can recolour one without touching the other.
     */
    character(slug) {
        if (!this.cast) return null;

        const template = this.cast.scene.getObjectByName(`${slug}_Rig`)
            || this.cast.scene.getObjectByName(`Character_${slug}`);
        if (!template) return null;

        const root = cloneSkeleton(template);
        this.makeOwned(root);

        /*
         * The kit lays the cast out in a row so the whole set can be seen while
         * it is being authored; that layout is a translation on the rig node.
         * Clearing it puts the figure back at its own origin. Rotation and
         * scale are left exactly as exported - the first carries the up-axis
         * conversion and the second carries how tall this person is, and the
         * scene places the figure through a wrapper rather than overwriting
         * either.
         */
        root.position.set(0, 0, 0);

        return { root, clips: this.motions(slug), morphs: this.morphTargets(root) };
    }

    room(environment) {
        if (!this.rooms) return null;

        const template = this.rooms.scene.getObjectByName(`Room_${environment}`);
        if (!template) return null;

        const root = cloneSkeleton(template);
        this.makeOwned(root);
        root.position.set(0, 0, 0);

        return root;
    }

    /** Which characters the file carries, for the player to check against. */
    characterNames() {
        if (!this.cast) return [];
        const names = [];
        this.cast.scene.traverse((object) => {
            if (object.name.endsWith('_Rig')) names.push(object.name.slice(0, -4));
        });
        return names;
    }

    /** Which rooms the file actually carries, for the player to check against. */
    roomNames() {
        if (!this.rooms) return [];
        const names = [];
        this.rooms.scene.traverse((object) => {
            if (object.name.startsWith('Room_')) names.push(object.name.slice(5));
        });
        return names;
    }

    makeOwned(root) {
        const materials = new Map();

        root.traverse((object) => {
            if (object.geometry) object.geometry = object.geometry.clone();

            const own = (material) => {
                if (!materials.has(material.uuid)) materials.set(material.uuid, material.clone());
                return materials.get(material.uuid);
            };

            if (object.material) {
                object.material = Array.isArray(object.material)
                    ? object.material.map(own)
                    : own(object.material);
            }

            if (object.isMesh || object.isSkinnedMesh) {
                object.castShadow = true;
                object.receiveShadow = true;
                // A skinned figure whose bounding box is computed from the rest
                // pose disappears the moment an arm leaves it.
                object.frustumCulled = false;
            }
        });
    }

    /**
     * Split a rig's one long timeline into the named motions.
     *
     * The exporter writes a single animation per armature, so the nine motions
     * are authored end to end and cut apart here. Each clip is resampled rather
     than referenced so it can loop on its own: the last frame is made equal to
     * the first, which is what stops a walk cycle jumping when it repeats.
     */
    motions(slug) {
        if (!this.cast) return [];

        // By exact name. The file also carries a shape-key track called
        // `Character_<slug>`, and a looser match would sometimes pick it.
        const timeline = this.cast.animations.find((clip) => clip.name === `${slug}_Rig`);

        if (!timeline || timeline.duration < MOTIONS.length * SECONDS_PER_MOTION - 1) {
            return [];
        }

        const frames = SECONDS_PER_MOTION * FPS;

        return MOTIONS.map((name, motion) => {
            const start = motion * SECONDS_PER_MOTION;

            const tracks = timeline.tracks.map((track) => {
                const copy = track.clone();
                const interpolant = track.createInterpolant();
                const times = [];
                const values = [];

                for (let frame = 0; frame <= frames; frame++) {
                    times.push(frame / FPS);
                    // The closing frame samples the opening one, so the clip
                    // meets itself when it loops.
                    const at = frame === frames ? start : start + frame / FPS;
                    values.push(...interpolant.evaluate(Math.min(at, timeline.duration)));
                }

                copy.times = new Float32Array(times);
                copy.values = new Float32Array(values);
                return copy;
            });

            return new AnimationClip(`${slug}_${name}`, SECONDS_PER_MOTION, tracks);
        });
    }

    /**
     * The face controls a rig carries, as a lookup from name to where it lives.
     *
     * The player asks for `viseme_aa` and gets told which mesh and which index,
     * so driving a mouth never involves searching the hierarchy per frame.
     */
    morphTargets(root) {
        const found = new Map();

        root.traverse((object) => {
            const dictionary = object.morphTargetDictionary;
            if (!dictionary) return;

            for (const [name, index] of Object.entries(dictionary)) {
                if (!found.has(name)) found.set(name, { mesh: object, index });
            }
        });

        return found;
    }
}

/**
 * Drives a rig's mouth and eyes from what the player knows about the audio.
 *
 * Kept apart from the figure itself because the two built-in figures and an
 * imported rig express a face completely differently — one moves objects, the
 * other moves morph targets — and the thing deciding what the face should do
 * should not have to care which it is talking to.
 */
export class MorphFace {
    constructor(morphs) {
        this.morphs = morphs;
        this.current = new Map();
    }

    get usable() {
        return this.morphs && this.morphs.size > 0;
    }

    set(name, value, dt = 1) {
        const target = this.morphs.get(name);
        if (!target) return;

        const previous = this.current.get(name) ?? 0;
        // Eased, so a loud syllable does not snap the mouth open in one frame.
        const next = previous + (value - previous) * Math.min(1, dt * 20);

        this.current.set(name, next);
        if (target.mesh.morphTargetInfluences) {
            target.mesh.morphTargetInfluences[target.index] = next;
        }
    }

    /** @param {{viseme:string, level:number, smile:number, blink:number}} state */
    apply(state, dt) {
        for (const name of [
            'viseme_sil', 'viseme_aa', 'viseme_E', 'viseme_oh',
            'viseme_ou', 'viseme_PP', 'viseme_FF', 'viseme_CH',
        ]) {
            this.set(name, name === state.viseme ? state.level : 0, dt);
        }

        this.set('mouthSmile', Math.max(0, state.smile), dt);
        this.set('mouthFrown', Math.max(0, -state.smile), dt);
        this.set('blink', state.blink, dt);
    }
}

export const KIT_PATHS = {
    cast: '/scene-kit/cast.glb',
    rooms: '/scene-kit/rooms.glb',
};
