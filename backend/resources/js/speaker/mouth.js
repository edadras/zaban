/**
 * A mouth driven by whatever is making the sound.
 *
 * The scene player already moved a mouth from the amplitude of a recording.
 * This is the same idea with the one thing that made it reusable taken out: it
 * does not care where the sound comes from. A recorded line in a lesson, a
 * rendered question in an exam, and a coach talking live down a WebRTC track
 * all arrive here as an analyser node and leave as a viseme and a level.
 *
 * It is honest guesswork, not lip-sync. Loud is open, quiet is nearly closed.
 * At conversational distance it reads correctly, which is all a figure this
 * stylised needs; nothing in the interface claims phoneme timing, because
 * there is none.
 */

/**
 * Where each mouth shape starts, loudest first.
 *
 * One definition, used by the scene player and by the standalone speaker, so
 * a coach's avatar and a character in a scene cannot drift into moving their
 * mouths differently for the same sound.
 */
export const VISEME_STEPS = [
    { at: 0.62, shape: 'aa' },
    { at: 0.38, shape: 'E' },
    { at: 0.18, shape: 'oh' },
    { at: 0, shape: 'PP' },
];

/** The mouth shape for a loudness between 0 and 1. */
export function visemeFor(level) {
    for (const step of VISEME_STEPS) {
        if (level > step.at) return step.shape;
    }

    return 'PP';
}

/**
 * How loud this instant is, 0 to 1, from an analyser's time-domain data.
 *
 * Root mean square rather than a peak: a peak follows every click and pop in a
 * recording, and a mouth that twitches on a consonant looks broken in a way a
 * slightly lazy one does not.
 */
export function loudnessOf(analyser, buffer) {
    analyser.getByteTimeDomainData(buffer);

    let sum = 0;
    for (let i = 0; i < buffer.length; i++) {
        const v = (buffer[i] - 128) / 128;
        sum += v * v;
    }

    return Math.min(1, Math.sqrt(sum / buffer.length) * 6.5);
}

export class Mouth {
    constructor() {
        this.context = null;
        this.analyser = null;
        this.buffer = null;
        this.source = null;
        this.level = 0;
        this.open = false;
    }

    /** True once something is actually wired in and could be measured. */
    get listening() {
        return Boolean(this.analyser && this.buffer);
    }

    /**
     * Browsers will not start audio before a gesture, so this is called from
     * one. Returns false when there is no audio at all, which the caller shows
     * rather than pretending the silence is speech.
     */
    async resume() {
        if (!this.context) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return false;
            this.context = new Ctx();
        }

        if (this.context.state === 'suspended') {
            try {
                await this.context.resume();
            } catch {
                return false;
            }
        }

        return this.context.state === 'running';
    }

    /**
     * Listen to a recording.
     *
     * The element is also connected through to the speakers: a
     * MediaElementSource takes the audio out of the element's own output, so
     * an analyser that does not pass it on leaves a silent page.
     */
    listenToElement(element) {
        if (!this.context) return false;

        try {
            this.wire(this.context.createMediaElementSource(element), true);

            return true;
        } catch {
            // An element can only ever have one source node. A second attempt
            // on the same element is the caller's bug, but a silent page is
            // worse than a still mouth, so the sound is left alone.
            this.release();

            return false;
        }
    }

    /**
     * Listen to somebody talking live.
     *
     * Not connected to the speakers on purpose: the track is already being
     * played by the media stack, and routing it a second time is an echo.
     */
    listenToStream(stream) {
        if (!this.context || !stream) return false;

        try {
            this.wire(this.context.createMediaStreamSource(stream), false);

            return true;
        } catch {
            this.release();

            return false;
        }
    }

    wire(source, toSpeakers) {
        this.release();

        this.source = source;
        this.analyser = this.context.createAnalyser();
        this.analyser.fftSize = 1024;
        this.buffer = new Uint8Array(this.analyser.fftSize);

        source.connect(this.analyser);
        if (toSpeakers) this.analyser.connect(this.context.destination);
    }

    /** Called every frame. `speaking` is what the caller believes, not what it hears. */
    measure(speaking) {
        this.open = Boolean(speaking);

        if (!this.open || !this.listening) {
            this.level = 0;

            return 0;
        }

        this.level = loudnessOf(this.analyser, this.buffer);

        return this.level;
    }

    shape() {
        return this.open ? visemeFor(this.level) : 'sil';
    }

    release() {
        this.source?.disconnect();
        this.analyser?.disconnect();
        this.source = null;
        this.analyser = null;
        this.buffer = null;
        this.level = 0;
    }

    dispose() {
        this.release();
        this.context?.close().catch(() => {});
        this.context = null;
    }
}
