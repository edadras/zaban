import * as THREE from 'three';

/**
 * Where the camera is.
 *
 * The shots are named by their part in the conversation - the speaker, the one
 * listening - rather than by the role, so a scene written for a clinic and a
 * scene written for a check-in desk use the same cues.
 *
 * A shot says what it wants in frame rather than how far away to stand. The
 * stage is whatever shape the page gives it - a tall panel on a phone held
 * upright, a shallow strip on a laptop with the keyboard open - and a fixed
 * distance that frames a face on one of those cuts the head off on the other.
 * So each shot names a height in metres and the distance is worked out from the
 * camera's own field of view, every time it is set.
 *
 * Every move is eased rather than cut, because a hard cut on every line makes a
 * five-line exchange feel like a trailer.
 */
export class CameraDirector {
    constructor(camera, characters) {
        this.camera = camera;
        this.characters = characters;
        this.position = camera.position.clone();
        this.target = new THREE.Vector3(0, 1.2, 0);
        this.wantPosition = this.position.clone();
        this.wantTarget = this.target.clone();
        this.speaker = null;
        this.shot = 'wide';
    }

    /** @param {string} shot @param {string} speaker role of whoever is talking */
    set(shot, speaker) {
        this.speaker = speaker || this.speaker;
        this.shot = shot || 'two_person';
        this.reframe();
    }

    /** Snap rather than glide: used when the scene first opens. */
    jump(shot, speaker) {
        this.set(shot, speaker);
        this.position.copy(this.wantPosition);
        this.target.copy(this.wantTarget);
        this.apply();
    }

    /**
     * How far back the camera has to stand to hold `height` metres.
     *
     * Vertical field of view is what crops a standing person, and three.js
     * keeps `fov` vertical, so this needs no aspect term - but it does need to
     * be recomputed whenever the stage is resized, which is why `reframe` is
     * called from there too.
     */
    distanceFor(height) {
        const fov = THREE.MathUtils.degToRad(this.camera.fov);
        return (height / 2) / Math.tan(fov / 2);
    }

    /**
     * Each shot as a point to look at, a direction to look from, and how much
     * of the scene to hold in frame.
     */
    plan() {
        const roles = Object.keys(this.characters);
        const speakerRole = this.speaker || roles[0];
        const listenerRole = roles.find((r) => r !== speakerRole) || speakerRole;

        const speaker = this.characters[speakerRole];
        const listener = this.characters[listenerRole];

        const s = speaker ? speaker.root.position : new THREE.Vector3();
        const o = listener ? listener.root.position : new THREE.Vector3();
        const away = s.x >= o.x ? 1 : -1;

        // Roughly eye height on these figures, which is where a shot of a
        // person talking wants its centre.
        const EYES = 1.58;
        const middle = new THREE.Vector3((s.x + o.x) / 2, 1.30, (s.z + o.z) / 2);

        switch (this.shot) {
            case 'wide':
                return { at: new THREE.Vector3(middle.x * 0.5, 1.15, -0.3),
                         from: new THREE.Vector3(0.55, 0.42, 1), height: 3.4 };
            case 'speaker_closeup':
                return { at: new THREE.Vector3(s.x, EYES, s.z),
                         from: new THREE.Vector3(away * 0.28, 0.10, 1), height: 1.35 };
            case 'listener_closeup':
                return { at: new THREE.Vector3(o.x, EYES, o.z),
                         from: new THREE.Vector3(-away * 0.28, 0.10, 1), height: 1.35 };
            case 'over_shoulder':
                return { at: new THREE.Vector3(s.x, EYES - 0.06, s.z),
                         from: new THREE.Vector3(-away * 0.42, 0.14, 1), height: 1.9 };
            case 'first_person':
                return { at: new THREE.Vector3(s.x, EYES, s.z),
                         from: new THREE.Vector3(-away * 0.05, 0.06, 1), height: 1.5 };
            case 'two_person':
            default:
                return { at: middle, from: new THREE.Vector3(0, 0.22, 1), height: 2.5 };
        }
    }

    reframe() {
        const { at, from, height } = this.plan();
        const distance = Math.max(1.2, this.distanceFor(height));

        const direction = from.clone().normalize();
        this.wantTarget.copy(at);
        this.wantPosition.copy(at).addScaledVector(direction, distance);

        // Never below the floor, and never so high it looks down on the scene.
        this.wantPosition.y = Math.min(3.2, Math.max(0.7, this.wantPosition.y));
    }

    update(dt) {
        // Time-based easing, so the move takes the same wall-clock time whether
        // the device is managing 60 frames a second or 24.
        const k = 1 - Math.pow(0.001, Math.min(dt, 0.1));
        this.position.lerp(this.wantPosition, k);
        this.target.lerp(this.wantTarget, k);
        this.apply();
    }

    apply() {
        this.camera.position.copy(this.position);
        this.camera.lookAt(this.target);
    }
}
