import * as THREE from 'three';
import { Character } from './character.js';
import { Room } from './room.js';
import { CameraDirector } from './camera.js';
import { Voice } from './voice.js';
import { Director } from './director.js';
import { SceneApi } from './api.js';
import { SceneUi } from './ui.js';
import { SceneKit, KIT_PATHS } from './kit.js';
import { RiggedActor } from './actor.js';

/**
 * The scene player.
 *
 * Drawn in real time rather than played as a video, which is the whole point:
 * a learner can stop on a line, hear it again more slowly, take a part and say
 * it themselves, and the same scene answers differently every time. A rendered
 * film could do none of that.
 *
 * The page is handed its scene and its signed endpoints by the server; it holds
 * no credential and decides nothing about whether an answer was right.
 */
class ScenePlayer {
    constructor(bootstrap, kit) {
        this.data = bootstrap;
        this.kit = kit;
        this.beatsData = bootstrap.beats || [];
        this.cleared = new Set();
        this.slow = false;

        this.stageEl = document.getElementById('scene-stage');
        this.uiEl = document.getElementById('scene-ui');

        this.api = new SceneApi(bootstrap.endpoints);
        this.voice = new Voice();

        this.buildStage();
        this.buildUi();
        this.frame = this.frame.bind(this);
        this.clock = new THREE.Clock();
        requestAnimationFrame(this.frame);
    }

    // --------------------------------------------------------------- stage

    buildStage() {
        const scene = this.data.scene;

        this.three = new THREE.Scene();
        this.three.background = new THREE.Color('#10131a');
        this.three.fog = new THREE.Fog('#10131a', 12, 26);

        this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        this.stageEl.appendChild(this.renderer.domElement);

        this.camera = new THREE.PerspectiveCamera(42, 16 / 9, 0.1, 100);

        // The modelled room when the kit has one, and the room the player
        // builds itself when it has not. The modelled one arrives furnished, so
        // the scene's own prop list is only used with the built-in room.
        const modelledRoom = this.kit.room(scene.environment);

        if (modelledRoom) {
            this.three.add(modelledRoom);
            this.room = new Room(scene.environment, scene.light);
            // Keep the lighting, drop the geometry: a room needs lights and the
            // modelled one carries none that survive glTF.
            this.three.add(this.room.lightsOnly());
        } else {
            this.room = new Room(scene.environment, scene.light);
            this.three.add(this.room.group);
            for (const prop of scene.props || []) {
                this.room.addProp(prop);
            }
        }

        this.characters = {};
        this.names = {};
        for (const member of scene.cast || []) {
            const modelled = this.kit.character(member.character || 'learner');
            const character = modelled
                ? new RiggedActor(modelled, member)
                : new Character(member);

            this.characters[member.role] = character;
            this.names[member.role] = member.name_fa || member.name || member.role;
            this.three.add(character.root);
        }

        this.cameras = new CameraDirector(this.camera, this.characters);

        /*
         * Handles for checking the stage from outside the page - what is where,
         * and what the camera can see of it. Framing is the one thing that
         * cannot be asserted from the server, and guessing at it from a
         * screenshot wastes more time than exposing two references.
         */
        window.__THREE__ = THREE;
        window.__THREE_SCENE__ = this.three;
        window.__THREE_CAMERA__ = this.camera;
        this.cameras.jump('wide', (scene.cast?.[0] || {}).role);

        this.resize();
        window.addEventListener('resize', () => this.resize());
    }

    resize() {
        const width = this.stageEl.clientWidth || window.innerWidth;
        const height = this.stageEl.clientHeight || Math.round(width * 0.5625);
        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / Math.max(1, height);
        this.camera.updateProjectionMatrix();

        // How far back the camera stands depends on the shape of the stage, so
        // a resize has to reconsider the shot rather than only the projection.
        this.cameras?.reframe();
    }

    // ------------------------------------------------------------------ ui

    buildUi() {
        this.ui = new SceneUi(this.uiEl, {
            onPlay: () => this.play(),
            onReplay: () => this.director.replay(this.director.index, this.slow),
            onSlow: (on) => { this.slow = on; },
            onAnswer: (payload) => this.answer(payload),
            onFinish: () => this.finish(),
            onRestart: () => window.location.reload(),
        });

        this.ui.describe(this.data.scene, this.data.session);
        this.ui.progress(0, this.beatsData.filter((b) => b.interaction !== 'watch').length);

        this.director = new Director({
            voice: this.voice,
            beats: () => this.beatsData,
            isCleared: (beat) => this.cleared.has(beat.id),
            stage: (beat) => this.stageBeat(beat),
            rest: () => this.restCharacters(),
            onLine: (beat) => this.ui.line(beat, this.names[beat.role]),
            onTurn: (beat, again) => this.ui.turn(beat, again),
            onPlaying: (on) => this.ui.playing(on),
            onIdle: () => this.ui.playing(false),
            onComplete: () => this.finish(),
            onError: (error) => this.ui.error(error.message),
        });

        // Anything already cleared in an earlier sitting stays cleared.
        for (const beat of this.beatsData) {
            if (beat.interaction !== 'watch' && beat.text !== null) {
                this.cleared.add(beat.id);
            }
        }
        /*
         * `position` is the 1-based number of the next line to play, so the
         * array index is one less. Using it directly would drop a line every
         * time a learner came back to a scene they had started.
         */
        this.director.index = Math.min(
            Math.max(0, (Number(this.data.session.position) || 0) - 1),
            Math.max(0, this.beatsData.length - 1),
        );
    }

    async play() {
        const ready = await this.voice.resume();
        if (!ready) {
            this.ui.note('صدا در این مرورگر فعال نشد. جمله‌ها را از زیرنویس بخوانید.');
        }
        this.director.play();
    }

    stageBeat(beat) {
        for (const [role, character] of Object.entries(this.characters)) {
            const speaking = role === beat.role;
            character.setAnimation(speaking ? beat.animation : 'listening');
            character.setGesture(speaking ? beat.gesture : 'none');
            character.setExpression(speaking ? beat.expression : 'neutral');
        }
        this.speaker = beat.role;
        this.cameras.set(beat.camera, beat.role);
    }

    restCharacters() {
        for (const character of Object.values(this.characters)) {
            character.reset();
        }
        this.speaker = null;
    }

    // -------------------------------------------------------------- answers

    async answer(payload) {
        const beat = this.director.waiting;
        if (!beat) return;

        try {
            this.ui.error('');
            const result = await this.api.answer(beat.id, payload);
            const verdict = result.verdict;

            this.ui.verdict(verdict, beat);

            if (verdict.accepted || verdict.revealed) {
                this.cleared.add(beat.id);
                if (verdict.line) beat.text = verdict.line;
                this.ui.progress(this.cleared.size, this.beatsData.filter((b) => b.interaction !== 'watch').length);
            }

            await this.director.resolveTurn(verdict);
        } catch (error) {
            this.ui.lock(false);
            this.ui.error(error.message);
        }
    }

    async finish() {
        this.director.stop();
        try {
            const session = await this.api.finish();
            this.ui.debrief(session);
        } catch (error) {
            this.ui.error(error.message);
        }
    }

    // -------------------------------------------------------------- drawing

    frame() {
        requestAnimationFrame(this.frame);

        const dt = Math.min(0.05, this.clock.getDelta());
        const t = this.clock.elapsedTime;

        const level = this.voice.measure(t);
        const shape = this.voice.shape();

        for (const [role, character] of Object.entries(this.characters)) {
            const speaking = this.voice.speaking && role === this.speaker;
            character.setSpeech(speaking, speaking ? level : 0, speaking ? shape : 'sil');
            character.update(t, dt);
        }

        this.cameras.update(dt);
        this.renderer.render(this.three, this.camera);
    }
}

const bootstrap = window.__SCENE__;
if (bootstrap) {
    // The kit is fetched before the first frame so the scene is never drawn
    // twice - once with the built-in figures and again with the modelled ones.
    // It never rejects: a kit that will not load leaves the player with the
    // geometry it builds itself, which still teaches the lesson.
    new SceneKit(bootstrap.kit || KIT_PATHS).load().then((kit) => {
        // eslint-disable-next-line no-new
        new ScenePlayer(bootstrap, kit);
    });
}
