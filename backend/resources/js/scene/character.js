import * as THREE from 'three';

/**
 * A person on the stage.
 *
 * Built from primitives rather than shipped as a scanned human, because the
 * scene has to load over a phone connection in a country where that connection
 * is not always good: a stylised figure that arrives in a few kilobytes and
 * moves correctly teaches more than a photoreal one that never finishes
 * downloading. Where a rig has been produced for a cast member the loader swaps
 * it in and drives its clips and visemes instead; nothing else changes.
 *
 * The figure owns its own geometry and materials so two characters on the same
 * stage never share a colour by accident.
 */

const GESTURES = ['none', 'open_hands', 'pointing', 'greeting', 'thinking'];

/** Mouth shapes. The values are how open and how wide, not phonetics. */
const VISEMES = {
    sil: [0.02, 1.0],
    aa: [1.0, 1.05],
    E: [0.55, 1.35],
    oh: [0.8, 0.75],
    ou: [0.55, 0.6],
    PP: [0.05, 1.0],
    FF: [0.25, 1.15],
    CH: [0.4, 0.9],
};

const EXPRESSIONS = {
    neutral: { brow: 0, browTilt: 0, smile: 0, lids: 0 },
    happy: { brow: 0.05, browTilt: 0, smile: 0.85, lids: 0.15 },
    friendly: { brow: 0.03, browTilt: 0, smile: 0.5, lids: 0.05 },
    worried: { brow: 0.08, browTilt: 0.25, smile: -0.35, lids: 0 },
    sick: { brow: -0.02, browTilt: 0.18, smile: -0.5, lids: 0.45 },
    confused: { brow: 0.1, browTilt: -0.3, smile: -0.1, lids: 0 },
    surprised: { brow: 0.16, browTilt: 0, smile: 0.2, lids: -0.25 },
    angry: { brow: -0.07, browTilt: -0.45, smile: -0.6, lids: 0.2 },
    sad: { brow: 0.06, browTilt: 0.4, smile: -0.7, lids: 0.25 },
    thinking: { brow: 0.06, browTilt: -0.15, smile: 0, lids: 0.1 },
};

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

export class Character {
    constructor(config = {}) {
        this.config = { ...config };
        this.root = new THREE.Group();
        this.root.name = `character:${config.role || 'unknown'}`;

        this.animation = 'idle';
        this.gesture = 'none';
        this.expression = config.expression || 'neutral';
        this.speaking = false;
        this.level = 0;
        this.viseme = 'sil';
        this.blink = 0;
        this.nextBlink = 1 + Math.random() * 4;

        this.materials = [];
        this.build();
        this.place();
    }

    // ------------------------------------------------------------ building

    material(colour, rough = 0.75) {
        const m = new THREE.MeshStandardMaterial({
            color: new THREE.Color(colour),
            roughness: rough,
            metalness: 0.02,
        });
        this.materials.push(m);
        return m;
    }

    mesh(geometry, material, parent, position = [0, 0, 0]) {
        const m = new THREE.Mesh(geometry, material);
        m.position.set(...position);
        m.castShadow = true;
        m.receiveShadow = true;
        parent.add(m);
        return m;
    }

    build() {
        const cloth = this.material(this.config.colour || '#4f7cff');
        const skin = this.material(this.config.skin || '#c68642', 0.85);
        const dark = this.material('#2a2d34', 0.9);

        // Hips carry everything, so one transform moves the whole person.
        this.hips = new THREE.Group();
        this.hips.position.y = 0.92;
        this.root.add(this.hips);

        this.torso = new THREE.Group();
        this.hips.add(this.torso);
        this.mesh(new THREE.CapsuleGeometry(0.19, 0.34, 6, 14), cloth, this.torso, [0, 0.27, 0]);
        this.mesh(new THREE.CapsuleGeometry(0.2, 0.1, 5, 12), dark, this.hips, [0, -0.02, 0]);

        this.neck = new THREE.Group();
        this.neck.position.y = 0.56;
        this.torso.add(this.neck);

        this.head = new THREE.Group();
        this.neck.add(this.head);
        this.mesh(new THREE.CapsuleGeometry(0.115, 0.07, 6, 16), skin, this.head, [0, 0.12, 0]);
        this.mesh(new THREE.SphereGeometry(0.13, 18, 14, 0, Math.PI * 2, 0, Math.PI * 0.55),
            this.material(this.config.hair || '#2c2119'), this.head, [0, 0.16, 0]);

        // The face is a handful of small objects rather than a texture, so an
        // expression is a transform and costs nothing to change per frame.
        const eyeWhite = this.material('#f6f4ef', 0.35);
        const pupil = this.material('#22252b', 0.3);
        this.eyes = [-1, 1].map((side) => {
            const group = new THREE.Group();
            group.position.set(side * 0.05, 0.14, 0.1);
            this.head.add(group);
            this.mesh(new THREE.SphereGeometry(0.022, 12, 10), eyeWhite, group);
            this.mesh(new THREE.SphereGeometry(0.011, 10, 8), pupil, group, [0, 0, 0.014]);
            return group;
        });

        const browMaterial = this.material('#2c2119', 0.9);
        this.brows = [-1, 1].map((side) => {
            const brow = this.mesh(
                new THREE.BoxGeometry(0.045, 0.008, 0.012), browMaterial, this.head,
                [side * 0.05, 0.175, 0.104],
            );
            brow.userData.side = side;
            return brow;
        });

        this.lids = [-1, 1].map((side) => this.mesh(
            new THREE.BoxGeometry(0.05, 0.03, 0.01), skin, this.head, [side * 0.05, 0.162, 0.108],
        ));
        this.lids.forEach((lid) => { lid.scale.y = 0.01; });

        this.mouth = this.mesh(
            new THREE.CapsuleGeometry(0.028, 0.01, 4, 10), this.material('#6d3b38', 0.6),
            this.head, [0, 0.055, 0.105],
        );
        this.mouth.rotation.z = Math.PI / 2;
        this.mouth.scale.set(1, 0.12, 0.6);

        this.arms = [-1, 1].map((side) => this.arm(side, cloth, skin));
        this.legs = [-1, 1].map((side) => this.leg(side, dark));
    }

    arm(side, cloth, skin) {
        const shoulder = new THREE.Group();
        shoulder.position.set(side * 0.21, 0.47, 0);
        this.torso.add(shoulder);
        this.mesh(new THREE.CapsuleGeometry(0.055, 0.17, 5, 10), cloth, shoulder, [0, -0.13, 0]);

        const elbow = new THREE.Group();
        elbow.position.y = -0.25;
        shoulder.add(elbow);
        this.mesh(new THREE.CapsuleGeometry(0.048, 0.16, 5, 10), skin, elbow, [0, -0.12, 0]);

        const hand = new THREE.Group();
        hand.position.y = -0.23;
        elbow.add(hand);
        this.mesh(new THREE.SphereGeometry(0.055, 10, 8), skin, hand);

        return { side, shoulder, elbow, hand };
    }

    leg(side, cloth) {
        const hip = new THREE.Group();
        hip.position.set(side * 0.09, -0.06, 0);
        this.hips.add(hip);
        this.mesh(new THREE.CapsuleGeometry(0.072, 0.26, 5, 10), cloth, hip, [0, -0.2, 0]);

        const knee = new THREE.Group();
        knee.position.y = -0.38;
        hip.add(knee);
        this.mesh(new THREE.CapsuleGeometry(0.062, 0.24, 5, 10), cloth, knee, [0, -0.18, 0]);
        this.mesh(new THREE.BoxGeometry(0.1, 0.06, 0.2), this.material('#23262c', 0.9), knee, [0, -0.33, 0.05]);

        return { side, hip, knee };
    }

    // ------------------------------------------------------------- driving

    place() {
        const c = this.config;
        this.root.position.set(Number(c.x) || 0, 0, Number(c.z) || 0);
        this.root.rotation.y = Number(c.rotation) || 0;
    }

    setAnimation(name) {
        this.animation = name || 'idle';
    }

    setGesture(name) {
        this.gesture = GESTURES.includes(name) ? name : 'none';
    }

    setExpression(name) {
        this.expression = EXPRESSIONS[name] ? name : 'neutral';
    }

    /** What the mouth is doing this frame, handed over by the voice system. */
    setSpeech(speaking, level = 0, viseme = 'sil') {
        this.speaking = speaking;
        this.level = clamp(level, 0, 1);
        this.viseme = VISEMES[viseme] ? viseme : 'sil';
    }

    reset() {
        this.setAnimation('idle');
        this.setGesture('none');
        this.setExpression(this.config.expression || 'neutral');
        this.setSpeech(false);
    }

    update(t, dt) {
        this.pose(t);
        this.face(t, dt);
    }

    pose(t) {
        const breathe = Math.sin(t * 1.6) * 0.012;
        const sitting = this.animation === 'sitting';

        this.hips.position.y = (sitting ? 0.58 : 0.92) + breathe;
        this.torso.rotation.x = sitting ? 0.06 : Math.sin(t * 0.8) * 0.012;
        this.torso.rotation.y = Math.sin(t * 0.5) * 0.02;

        // Legs. Only walking actually swings them; sitting folds them.
        for (const leg of this.legs) {
            if (sitting) {
                leg.hip.rotation.x = -1.35;
                leg.knee.rotation.x = 1.45;
            } else if (this.animation === 'walking') {
                const phase = t * 5 + (leg.side > 0 ? Math.PI : 0);
                leg.hip.rotation.x = Math.sin(phase) * 0.5;
                leg.knee.rotation.x = Math.max(0, -Math.sin(phase)) * 0.7;
            } else {
                leg.hip.rotation.x = Math.sin(t * 0.7 + leg.side) * 0.01;
                leg.knee.rotation.x = 0.02;
            }
        }

        for (const arm of this.arms) {
            const [shoulder, elbow] = this.armTarget(arm, t);
            arm.shoulder.rotation.x = shoulder.x;
            arm.shoulder.rotation.z = shoulder.z;
            arm.elbow.rotation.x = elbow;
        }

        // The head follows the talking, which is what makes a figure look alive
        // rather than like a mannequin with a moving jaw.
        const talk = this.speaking ? this.level : 0;
        this.head.rotation.x = Math.sin(t * 2.1) * 0.02 - talk * 0.05;
        this.head.rotation.y = Math.sin(t * 0.9) * 0.05;
        this.head.rotation.z = Math.sin(t * 1.3) * 0.015;
    }

    armTarget(arm, t) {
        const side = arm.side;
        const idle = [{ x: Math.sin(t * 0.8 + side) * 0.03, z: side * 0.09 }, 0.12];

        switch (this.gesture) {
            case 'open_hands':
                return [{ x: -0.75 + Math.sin(t * 1.4) * 0.05, z: side * 0.55 }, -0.55];
            case 'pointing':
                return side > 0
                    ? [{ x: -1.25 + Math.sin(t * 2) * 0.04, z: 0.12 }, -0.2]
                    : idle;
            case 'greeting':
                return side > 0
                    ? [{ x: -1.9, z: 0.35 + Math.sin(t * 6) * 0.25 }, -0.35]
                    : idle;
            case 'thinking':
                return side > 0
                    ? [{ x: -1.65, z: -0.35 }, -1.5]
                    : idle;
            default:
                break;
        }

        if (this.animation === 'walking') {
            const phase = t * 5 + (side > 0 ? 0 : Math.PI);
            return [{ x: Math.sin(phase) * 0.35, z: side * 0.1 }, 0.2];
        }
        if (this.animation === 'listening') {
            return [{ x: -0.12, z: side * 0.14 }, 0.35];
        }
        if (this.speaking) {
            return [
                { x: -0.35 + Math.sin(t * 2.6 + side) * 0.12 * (0.3 + this.level), z: side * 0.28 },
                -0.5,
            ];
        }
        return idle;
    }

    face(t, dt) {
        const e = EXPRESSIONS[this.expression] || EXPRESSIONS.neutral;

        for (const brow of this.brows) {
            brow.position.y = 0.175 + e.brow;
            brow.rotation.z = brow.userData.side * e.browTilt;
        }

        this.blink -= dt;
        if (this.blink <= -0.12) {
            this.nextBlink = 1.5 + Math.random() * 4;
            this.blink = this.nextBlink;
        }
        const blinking = this.blink < 0;
        const lid = blinking ? 1 : clamp(e.lids, 0, 1);
        for (const l of this.lids) {
            l.scale.y = Math.max(0.01, lid);
            l.position.y = 0.162 + (1 - lid) * 0.018;
        }

        const [open, wide] = VISEMES[this.viseme] || VISEMES.sil;
        const amount = this.speaking ? clamp(this.level, 0, 1) : 0;
        const target = 0.12 + open * amount * 0.95;

        // Eased so a loud syllable does not snap the mouth open in one frame.
        this.mouth.scale.y += (target - this.mouth.scale.y) * Math.min(1, dt * 22);
        this.mouth.scale.x += ((wide + e.smile * 0.25) - this.mouth.scale.x) * Math.min(1, dt * 12);
        this.mouth.position.y = 0.055 - e.smile * 0.006;
        this.mouth.rotation.x = -e.smile * 0.35;
    }

    dispose() {
        this.root.traverse((o) => {
            if (o.geometry) o.geometry.dispose();
        });
        this.materials.forEach((m) => m.dispose());
    }
}
