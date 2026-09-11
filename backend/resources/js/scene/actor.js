import * as THREE from 'three';
import { MorphFace } from './kit.js';

/**
 * A modelled person on the stage.
 *
 * Wraps a rig from the kit behind exactly the interface the built-in figure
 * offers, so the director stages a line the same way whether the scene is being
 * played with the modelled cast or with the geometry the player builds itself.
 * Everything specific to a rig — which clip a gesture maps to, how an
 * expression reaches a morph target — is decided here and nowhere else.
 */

/** A gesture is a motion, when the rig has one for it. */
const GESTURE_CLIP = {
    pointing: 'pointing',
    greeting: 'greeting',
    thinking: 'thinking',
    open_hands: 'talking',
};

/** How much of a smile each expression carries, from -1 to 1. */
const EXPRESSION_SMILE = {
    neutral: 0,
    happy: 0.9,
    friendly: 0.55,
    worried: -0.35,
    sick: -0.5,
    confused: -0.15,
    surprised: 0.2,
    angry: -0.7,
    sad: -0.75,
    thinking: 0,
};

export class RiggedActor {
    /**
     * @param {{root:THREE.Object3D, clips:THREE.AnimationClip[], morphs:Map}} model
     * @param {object} config the scene's cast entry
     */
    constructor(model, config = {}) {
        this.config = { ...config };

        /*
         * The rig goes inside a wrapper and the scene moves the wrapper.
         * The exported rig node carries the up-axis conversion and the
         * character's height in its own rotation and scale, and a scene that
         * set those directly would lay the figure on its side or make everyone
         * the same height.
         */
        this.model = model.root;
        this.root = new THREE.Group();
        this.root.name = `actor:${config.role || 'unknown'}`;
        this.root.add(this.model);

        /*
         * Turned to face the camera.
         *
         * The kit is authored with the faces towards +Y, and the up-axis
         * conversion on export turns that into -Z - the opposite of the way the
         * scene's own figures face. Without this the camera frames the back of
         * everyone's head for the whole conversation.
         */
        this.model.rotation.y += Math.PI;

        this.mixer = new THREE.AnimationMixer(this.model);
        this.face = new MorphFace(model.morphs);

        this.actions = new Map();
        for (const clip of model.clips) {
            const action = this.mixer.clipAction(clip);
            action.loop = THREE.LoopRepeat;
            // The clip name is prefixed with the character; the motion is the tail.
            this.actions.set(clip.name.split('_').pop(), action);
        }

        this.animation = 'idle';
        this.gesture = 'none';
        this.expression = config.expression || 'neutral';
        this.speaking = false;
        this.level = 0;
        this.viseme = 'sil';
        this.blink = 0;
        this.nextBlink = 1 + Math.random() * 4;
        this.current = null;

        this.place();
        this.play('idle');
    }

    place() {
        const c = this.config;
        this.root.position.set(Number(c.x) || 0, 0, Number(c.z) || 0);
        this.root.rotation.set(0, Number(c.rotation) || 0, 0);
    }

    /** Where the head is, so the camera can frame a face rather than a chest. */
    get eyeHeight() {
        return 1.62 * (this.model.scale.y || 1);
    }

    /** Cross-fade to a motion. A missing clip leaves whatever is playing alone. */
    play(motion) {
        const next = this.actions.get(motion);
        if (!next || next === this.current) return;

        next.reset().fadeIn(0.25).play();
        if (this.current) this.current.fadeOut(0.25);
        this.current = next;
    }

    setAnimation(name) {
        this.animation = name || 'idle';
        // A gesture outranks the base motion: a character who is pointing is
        // pointing, whatever the line said they were doing.
        if (this.gesture === 'none') this.play(this.animation);
    }

    setGesture(name) {
        this.gesture = name || 'none';
        this.play(GESTURE_CLIP[this.gesture] || this.animation);
    }

    setExpression(name) {
        this.expression = name in EXPRESSION_SMILE ? name : 'neutral';
    }

    setSpeech(speaking, level = 0, viseme = 'sil') {
        this.speaking = speaking;
        this.level = Math.max(0, Math.min(1, level));
        this.viseme = viseme || 'sil';
    }

    reset() {
        this.setGesture('none');
        this.setAnimation('idle');
        this.setExpression(this.config.expression || 'neutral');
        this.setSpeech(false);
    }

    update(t, dt) {
        this.mixer.update(dt);

        this.blink -= dt;
        if (this.blink <= -0.12) {
            this.nextBlink = 1.5 + Math.random() * 4;
            this.blink = this.nextBlink;
        }

        if (this.face.usable) {
            this.face.apply({
                viseme: `viseme_${this.speaking ? this.viseme : 'sil'}`,
                level: this.speaking ? Math.max(0.12, this.level) : 0,
                smile: EXPRESSION_SMILE[this.expression] ?? 0,
                blink: this.blink < 0 ? 1 : 0,
            }, dt);
        }
    }

    dispose() {
        this.mixer.stopAllAction();
        this.model.traverse((object) => {
            if (object.geometry) object.geometry.dispose();
            const material = object.material;
            if (Array.isArray(material)) material.forEach((m) => m.dispose());
            else if (material) material.dispose();
        });
    }
}
