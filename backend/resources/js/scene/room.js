import * as THREE from 'three';

/**
 * The room the scene happens in.
 *
 * Eight situations share one room built to different palettes and furnished
 * differently. That is a deliberate limit and worth being plain about: these
 * are recognisable spaces, not eight modelled interiors. A learner needs to
 * know at a glance whether they are in a surgery or a restaurant, and colour,
 * light and three pieces of furniture do that.
 */

const PALETTES = {
    clinic: { floor: '#dfe3e6', wall: '#eef2f4', accent: '#8fb8c9', warmth: 0.05 },
    restaurant: { floor: '#6d4a34', wall: '#3d2b23', accent: '#c8884a', warmth: 0.45 },
    hotel: { floor: '#6b5a49', wall: '#e7ded0', accent: '#b08d57', warmth: 0.3 },
    airport: { floor: '#b9bec4', wall: '#dde3e8', accent: '#4d7fb3', warmth: 0.0 },
    shop: { floor: '#c8c2b4', wall: '#f0ece2', accent: '#6b8f5f', warmth: 0.15 },
    office: { floor: '#8d8f94', wall: '#e4e7ea', accent: '#4f6076', warmth: 0.08 },
    classroom: { floor: '#c2a37c', wall: '#f2ece0', accent: '#7a6248', warmth: 0.2 },
    cafe: { floor: '#7a5636', wall: '#efe4d4', accent: '#a8623c', warmth: 0.4 },
};

export class Room {
    constructor(environment = 'clinic', light = 1) {
        this.group = new THREE.Group();
        this.materials = [];
        this.palette = PALETTES[environment] || PALETTES.clinic;
        this.environment = environment;

        this.build();
        this.lights = this.lighting(Number(light) || 1);
        this.props = new Map();
    }

    material(colour, rough = 0.9) {
        const m = new THREE.MeshStandardMaterial({
            color: new THREE.Color(colour),
            roughness: rough,
            metalness: 0.02,
        });
        this.materials.push(m);
        return m;
    }

    build() {
        const p = this.palette;

        const floor = new THREE.Mesh(new THREE.PlaneGeometry(12, 12), this.material(p.floor, 0.95));
        floor.rotation.x = -Math.PI / 2;
        floor.receiveShadow = true;
        this.group.add(floor);

        const wall = this.material(p.wall, 0.95);
        const back = new THREE.Mesh(new THREE.PlaneGeometry(12, 3.4), wall);
        back.position.set(0, 1.7, -3.4);
        back.receiveShadow = true;
        this.group.add(back);

        const left = new THREE.Mesh(new THREE.PlaneGeometry(7, 3.4), wall);
        left.position.set(-4.2, 1.7, 0);
        left.rotation.y = Math.PI / 2;
        left.receiveShadow = true;
        this.group.add(left);

        // A band of colour at eye height, which is what actually reads as
        // "this is a different place" once the camera is close in.
        const band = new THREE.Mesh(new THREE.PlaneGeometry(12, 0.5), this.material(p.accent, 0.8));
        band.position.set(0, 1.35, -3.38);
        this.group.add(band);

        const window = new THREE.Mesh(
            new THREE.PlaneGeometry(2.4, 1.5),
            this.material('#cfe4f2', 0.25),
        );
        window.position.set(2.4, 1.9, -3.37);
        this.group.add(window);
    }

    lighting(strength) {
        const warm = new THREE.Color().lerpColors(
            new THREE.Color('#eaf2ff'), new THREE.Color('#ffd9a8'), this.palette.warmth,
        );

        const ambient = new THREE.HemisphereLight(warm, new THREE.Color(this.palette.floor), 1.1 * strength);
        this.group.add(ambient);

        const key = new THREE.DirectionalLight(warm, 1.5 * strength);
        key.position.set(2.6, 4.2, 3.2);
        key.castShadow = true;
        key.shadow.mapSize.set(1024, 1024);
        key.shadow.camera.near = 0.5;
        key.shadow.camera.far = 20;
        key.shadow.camera.left = -6;
        key.shadow.camera.right = 6;
        key.shadow.camera.top = 6;
        key.shadow.camera.bottom = -6;
        this.group.add(key);

        const fill = new THREE.DirectionalLight('#ffffff', 0.4 * strength);
        fill.position.set(-3.5, 2.6, 2.4);
        this.group.add(fill);

        return { ambient, key, fill };
    }

    /**
     * The lights without the walls.
     *
     * A modelled room brings its own geometry but no usable lighting: lamps and
     * world settings do not survive a glTF export, so the room still has to be
     * lit from here.
     */
    lightsOnly() {
        const group = new THREE.Group();
        group.name = 'room-lighting';
        for (const light of Object.values(this.lights)) {
            group.add(light);
        }
        return group;
    }

    /** Furniture. Each prop is one small object placed by the scene data. */
    addProp({ id, type, x = 0, z = 0, rotation = 0 }) {
        const build = this.builders()[type];
        if (!build) return null;

        const prop = build();
        prop.position.set(Number(x) || 0, prop.position.y, Number(z) || 0);
        prop.rotation.y = Number(rotation) || 0;
        prop.traverse((o) => {
            if (o.isMesh) {
                o.castShadow = true;
                o.receiveShadow = true;
            }
        });
        this.group.add(prop);
        this.props.set(id, prop);
        return prop;
    }

    builders() {
        const wood = () => this.material('#6f5039', 0.85);
        const metal = () => this.material('#9aa1a8', 0.4);
        const dark = () => this.material('#2b2f36', 0.7);

        return {
            desk: () => {
                const g = new THREE.Group();
                const top = new THREE.Mesh(new THREE.BoxGeometry(1.5, 0.07, 0.75), wood());
                top.position.y = 0.74;
                g.add(top);
                for (const sx of [-0.65, 0.65]) {
                    for (const sz of [-0.3, 0.3]) {
                        const leg = new THREE.Mesh(new THREE.BoxGeometry(0.07, 0.72, 0.07), metal());
                        leg.position.set(sx, 0.36, sz);
                        g.add(leg);
                    }
                }
                return g;
            },
            chair: () => {
                const g = new THREE.Group();
                const seat = new THREE.Mesh(new THREE.BoxGeometry(0.46, 0.07, 0.46), wood());
                seat.position.y = 0.45;
                g.add(seat);
                const back = new THREE.Mesh(new THREE.BoxGeometry(0.46, 0.5, 0.06), wood());
                back.position.set(0, 0.72, -0.2);
                g.add(back);
                for (const sx of [-0.18, 0.18]) {
                    for (const sz of [-0.18, 0.18]) {
                        const leg = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.45, 0.05), metal());
                        leg.position.set(sx, 0.22, sz);
                        g.add(leg);
                    }
                }
                return g;
            },
            monitor: () => {
                const g = new THREE.Group();
                const screen = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.32, 0.03), dark());
                screen.position.y = 1.05;
                g.add(screen);
                const face = new THREE.Mesh(
                    new THREE.PlaneGeometry(0.46, 0.28), this.material('#6f9fc4', 0.3),
                );
                face.position.set(0, 1.05, 0.017);
                g.add(face);
                const stand = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.09, 0.2, 12), metal());
                stand.position.y = 0.83;
                g.add(stand);
                return g;
            },
            exam: () => {
                const g = new THREE.Group();
                const bed = new THREE.Mesh(new THREE.BoxGeometry(0.75, 0.12, 1.9), this.material('#4f7f8f', 0.7));
                bed.position.y = 0.62;
                g.add(bed);
                const frame = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.56, 1.6), metal());
                frame.position.y = 0.28;
                g.add(frame);
                return g;
            },
            counter: () => {
                const g = new THREE.Group();
                const body = new THREE.Mesh(new THREE.BoxGeometry(2.2, 1.0, 0.7), wood());
                body.position.y = 0.5;
                g.add(body);
                const top = new THREE.Mesh(new THREE.BoxGeometry(2.35, 0.08, 0.85), this.material('#d8d2c6', 0.5));
                top.position.y = 1.03;
                g.add(top);
                return g;
            },
            plant: () => {
                const g = new THREE.Group();
                const pot = new THREE.Mesh(new THREE.CylinderGeometry(0.17, 0.13, 0.28, 12),
                    this.material('#8a5b3f', 0.9));
                pot.position.y = 0.14;
                g.add(pot);
                const leaves = this.material('#3f7a45', 0.85);
                for (let i = 0; i < 6; i++) {
                    const leaf = new THREE.Mesh(new THREE.SphereGeometry(0.16, 8, 6), leaves);
                    leaf.position.set(
                        Math.cos((i / 6) * Math.PI * 2) * 0.14,
                        0.42 + (i % 3) * 0.13,
                        Math.sin((i / 6) * Math.PI * 2) * 0.14,
                    );
                    leaf.scale.set(1, 1.4, 1);
                    g.add(leaf);
                }
                return g;
            },
        };
    }

    dispose() {
        this.group.traverse((o) => {
            if (o.geometry) o.geometry.dispose();
        });
        this.materials.forEach((m) => m.dispose());
    }
}
