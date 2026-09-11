import * as THREE from 'three';

/**
 * Where the camera is.
 *
 * The shots are named by their part in the conversation - the speaker, the one
 * listening - rather than by the role, so a scene written for a clinic and a
 * scene written for a check-in desk use the same cues. Every move is eased
 * rather than cut, because a hard cut every line makes a five-line exchange
 * feel like a trailer.
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
    }

    /** @param {string} shot @param {string} speaker role of whoever is talking */
    set(shot, speaker) {
        this.speaker = speaker || this.speaker;
        const [position, target] = this.frame(shot);
        this.wantPosition.set(...position);
        this.wantTarget.set(...target);
    }

    /** Snap rather than glide: used when the scene first opens. */
    jump(shot, speaker) {
        this.set(shot, speaker);
        this.position.copy(this.wantPosition);
        this.target.copy(this.wantTarget);
        this.apply();
    }

    frame(shot) {
        const roles = Object.keys(this.characters);
        const speaker = this.characters[this.speaker] || this.characters[roles[0]];
        const other = this.characters[roles.find((r) => r !== (this.speaker || roles[0]))] || speaker;

        const s = speaker ? speaker.root.position : new THREE.Vector3();
        const o = other ? other.root.position : new THREE.Vector3();
        const middle = [(s.x + o.x) / 2, 1.25, (s.z + o.z) / 2];

        switch (shot) {
            case 'wide':
                return [[3.6, 2.9, 4.6], [middle[0] * 0.5, 1.1, -0.3]];
            case 'speaker_closeup':
                return [[s.x + (s.x > o.x ? 0.45 : -0.45), 1.72, s.z + 1.5], [s.x, 1.48, s.z]];
            case 'listener_closeup':
                return [[o.x + (o.x > s.x ? 0.45 : -0.45), 1.72, o.z + 1.5], [o.x, 1.48, o.z]];
            case 'over_shoulder':
                return [[o.x + (o.x > s.x ? 0.5 : -0.5), 1.8, o.z + 0.85], [s.x, 1.42, s.z]];
            case 'first_person':
                return [[o.x, 1.62, o.z - 0.05], [s.x, 1.45, s.z]];
            case 'two_person':
            default:
                return [[middle[0], 1.95, Math.max(s.z, o.z) + 2.9], middle];
        }
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
