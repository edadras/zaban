import * as THREE from 'three';
import { SceneKit, KIT_PATHS } from '../scene/kit.js';
import { RiggedActor } from '../scene/actor.js';
import { Character } from '../scene/character.js';
import { Mouth } from './mouth.js';

/**
 * One person, framed head and shoulders, talking.
 *
 * The acted scenes put two people in a room. Most of the product does not need
 * a room: a lesson wants the speaker whose recording you are about to repeat,
 * an exam wants the examiner sitting opposite, and a class wants the coach who
 * has their camera off. All three want the same thing - a face that moves when
 * there is sound - so it is built once here and the three callers differ only
 * in where the sound comes from.
 *
 * Deliberately no room. A background would be three more megabytes and a
 * decision about where a coach is sitting that nobody asked for; a figure on a
 * plain ground reads as a presenter, which is what this is.
 */

/** Roughly a head-and-shoulders shot of somebody this tall. */
const FRAMING = {
    /** How much of the figure is in frame, in metres, measured from the eyes. */
    height: 0.75,
    /** Above the eyeline, so the head is not jammed against the top edge. */
    lift: 0.06,
};

export class SpeakerStage {
    /**
     * @param {HTMLElement} mount
     * @param {{character?:string, kit?:object, background?:number}} options
     */
    constructor(mount, options = {}) {
        this.mount = mount;
        this.options = options;
        this.mouth = new Mouth();
        this.speaking = false;
        this.actor = null;
        this.running = false;
        this.clock = new THREE.Clock();
        this.frame = this.frame.bind(this);
        this.onResize = () => this.resize();
    }

    /**
     * Build the stage.
     *
     * The kit is fetched before the first frame so the figure is never drawn
     * twice - once as the built-in geometry and again as the modelled rig - and
     * a kit that will not load leaves the built-in figure, which moves its
     * mouth just as correctly and arrives in a few kilobytes.
     */
    async load() {
        const kit = await new SceneKit(this.options.kit || KIT_PATHS).load();

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(this.options.background ?? 0x10131a);

        this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.mount.appendChild(this.renderer.domElement);

        this.camera = new THREE.PerspectiveCamera(38, 1, 0.1, 50);

        this.light();

        const slug = this.options.character || 'learner';
        const modelled = kit.character(slug);
        const config = { role: 'speaker', expression: 'friendly', ...(this.options.look || {}) };

        this.actor = modelled ? new RiggedActor(modelled, config) : new Character(config);
        this.modelled = Boolean(modelled);
        this.scene.add(this.actor.root);

        this.resize();
        window.addEventListener('resize', this.onResize);

        /* Handles, for checking the stage from outside the page. */
        window.__SPEAKER__ = this;

        this.running = true;
        requestAnimationFrame(this.frame);

        return this;
    }

    /** Portrait lighting: a key at face height, a fill, and a little from above. */
    light() {
        const key = new THREE.DirectionalLight(0xffffff, 2.1);
        key.position.set(1.4, 2.1, 2.2);
        this.scene.add(key);

        const fill = new THREE.DirectionalLight(0xbcd0ff, 0.7);
        fill.position.set(-1.8, 1.4, 1.2);
        this.scene.add(fill);

        this.scene.add(new THREE.HemisphereLight(0xdfe8ff, 0x20242e, 0.75));
    }

    /**
     * Frame the face, whatever shape the page gives this.
     *
     * The same reasoning as the scene player's camera: a fixed distance that
     * frames a head on a laptop cuts it off on a phone held upright, so the
     * shot says how much it wants in frame and the distance follows from the
     * field of view and the aspect the mount actually has.
     */
    resize() {
        if (!this.renderer || !this.actor) return;

        const width = this.mount.clientWidth || 320;
        const height = this.mount.clientHeight || Math.round(width * 0.75);

        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / Math.max(1, height);

        const vertical = THREE.MathUtils.degToRad(this.camera.fov);
        // A narrow, tall mount sees less across than a wide one, so the figure
        // has to be further away to keep the same amount of them in shot.
        const visible = FRAMING.height * Math.max(1, 1 / this.camera.aspect);
        const distance = (visible / 2) / Math.tan(vertical / 2);

        const eyes = this.actor.eyeHeight ?? 1.6;
        this.camera.position.set(0, eyes + FRAMING.lift, distance);
        this.camera.lookAt(0, eyes, 0);
        this.camera.updateProjectionMatrix();
    }

    // ------------------------------------------------------------- speaking

    /** Unlock audio from a gesture. False means this browser gave us none. */
    resume() {
        return this.mouth.resume();
    }

    /**
     * Speak a recording, and resolve when it has finished.
     *
     * `from` and `to` matter more than they look. The course's audio is one
     * track per exercise - a line is a second and a half somewhere inside
     * forty seconds - so a speaker handed the whole file says its line and
     * then stands in silence for half a minute while the learner waits for
     * something that already happened. The window is the line.
     *
     * Resolves with why it stopped, so the caller can tell "it played" from
     * "there was nothing to play" and say so rather than leaving a learner
     * waiting for a sound that is never coming.
     *
     * @returns {Promise<'ended'|'stopped'|'unavailable'>}
     */
    play(url, { rate = 1, from = null, to = null } = {}) {
        this.stop();

        if (!url) return Promise.resolve('unavailable');

        const token = ++this.token;
        const start = Math.max(0, Number(from) || 0) / 1000;
        const end = to == null ? null : Number(to) / 1000;

        return new Promise((resolve) => {
            const audio = new Audio();
            audio.crossOrigin = 'anonymous';
            audio.preload = 'auto';
            audio.src = url;
            audio.playbackRate = rate;
            this.audio = audio;
            this.resolve = resolve;

            const finish = (how) => {
                if (token !== this.token) return;
                this.speaking = false;
                this.audio = null;
                const done = this.resolve;
                this.resolve = null;
                this.mouth.release();
                done?.(how);
            };

            audio.onended = () => finish('ended');
            audio.onerror = () => finish('unavailable');

            audio.onloadedmetadata = () => {
                if (start <= 0) return;
                try {
                    audio.currentTime = start;
                } catch {
                    // A server that has not served a range yet cannot be
                    // seeked; the line simply starts from the top.
                }
            };

            if (end !== null) {
                audio.ontimeupdate = () => {
                    if (audio.currentTime >= end) {
                        audio.pause();
                        finish('ended');
                    }
                };
            }

            this.mouth.listenToElement(audio);

            audio.play()
                .then(() => { if (token === this.token) this.speaking = true; })
                .catch(() => finish('unavailable'));
        });
    }

    /**
     * Move the mouth to somebody talking live, for as long as they are.
     *
     * The caller says when they are speaking, because only the media stack
     * knows whether a track is muted - and a figure that mouths along to a
     * muted microphone is a lie about who is talking.
     */
    listen(stream) {
        this.stop();

        return this.mouth.listenToStream(stream);
    }

    /** Whether the live track should currently be moving the mouth. */
    setSpeaking(on) {
        this.speaking = Boolean(on);
    }

    stop() {
        this.token = (this.token || 0) + 1;
        this.speaking = false;

        if (this.audio) {
            this.audio.onended = null;
            this.audio.onerror = null;
            this.audio.ontimeupdate = null;
            this.audio.onloadedmetadata = null;
            this.audio.pause();
            this.audio = null;
        }

        const done = this.resolve;
        this.resolve = null;
        this.mouth.release();
        done?.('stopped');
    }

    // --------------------------------------------------------------- acting

    setExpression(name) {
        this.actor?.setExpression(name);
    }

    setGesture(name) {
        this.actor?.setGesture(name);
    }

    setAnimation(name) {
        this.actor?.setAnimation(name);
    }

    // -------------------------------------------------------------- drawing

    frame() {
        if (!this.running) return;
        requestAnimationFrame(this.frame);

        const dt = Math.min(0.05, this.clock.getDelta());
        const t = this.clock.elapsedTime;

        const level = this.mouth.measure(this.speaking);
        this.actor.setSpeech(this.speaking, level, this.mouth.shape());
        this.actor.update(t, dt);

        this.renderer.render(this.scene, this.camera);
    }

    dispose() {
        this.running = false;
        window.removeEventListener('resize', this.onResize);
        this.stop();
        this.mouth.dispose();
        this.actor?.dispose?.();
        this.renderer?.dispose();
        this.renderer?.domElement?.remove();
        if (window.__SPEAKER__ === this) delete window.__SPEAKER__;
    }
}
