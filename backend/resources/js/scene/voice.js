/**
 * Playing a line, and moving the mouth while it plays.
 *
 * Two things make this more than an <audio> tag. A line is often a window
 * inside a longer recording - the course's audio is one track per exercise -
 * so playback seeks in and stops itself at the right millisecond. And the mouth
 * is driven by the sound itself: the analyser reports how loud the moment is,
 * which is a truer mouth than a timer guessing at syllables, and it works for a
 * recorded human voice as well as a rendered one.
 */
import { visemeFor } from '../speaker/mouth.js';

export class Voice {
    constructor() {
        this.audio = null;
        this.context = null;
        this.analyser = null;
        this.source = null;
        this.buffer = null;
        this.token = 0;
        this.speaking = false;
        this.level = 0;
        this.rate = 1;
        this.failed = false;
    }

    /** Unlocked on the first gesture; browsers will not start audio before one. */
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

    stop() {
        this.token++;
        this.speaking = false;
        this.level = 0;

        if (this.audio) {
            this.audio.onended = null;
            this.audio.onerror = null;
            this.audio.ontimeupdate = null;
            this.audio.pause();
            this.audio = null;
        }

        // Every line gets a fresh element and therefore a fresh source node.
        // Leaving the old one wired to the destination keeps it, and the
        // element behind it, alive for as long as the page is open.
        this.source?.disconnect();
        this.source = null;
        this.analyser?.disconnect();
        this.analyser = null;
        this.buffer = null;
        if (this.resolve) {
            const done = this.resolve;
            this.resolve = null;
            done('stopped');
        }
    }

    /**
     * Play one line.
     *
     * @param {{url:string,start_ms:?number,end_ms:?number}} clip
     * @param {number} rate 0.7 for the slow replay
     * @returns {Promise<'ended'|'stopped'|'unavailable'>}
     */
    play(clip, rate = 1) {
        this.stop();

        if (!clip || !clip.url) {
            return Promise.resolve('unavailable');
        }

        const token = ++this.token;
        this.rate = rate;

        return new Promise((resolve) => {
            this.resolve = resolve;

            const audio = new Audio();
            audio.crossOrigin = 'anonymous';
            audio.preload = 'auto';
            audio.src = clip.url;
            audio.playbackRate = rate;
            this.audio = audio;

            const finish = (how) => {
                if (token !== this.token) return;
                this.speaking = false;
                this.level = 0;
                this.audio = null;
                if (this.resolve) {
                    const done = this.resolve;
                    this.resolve = null;
                    done(how);
                }
            };

            const start = (Number(clip.start_ms) || 0) / 1000;
            const end = clip.end_ms != null ? Number(clip.end_ms) / 1000 : null;

            audio.onerror = () => {
                // A line that will not play is not a line that failed the
                // learner: the scene carries on and shows the words.
                this.failed = true;
                finish('unavailable');
            };
            audio.onended = () => finish('ended');

            audio.onloadedmetadata = () => {
                if (start > 0) {
                    try {
                        audio.currentTime = start;
                    } catch {
                        // Seeking before the server has served a range is not
                        // fatal; the line simply starts from the top.
                    }
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

            this.connect(audio);

            audio.play().then(() => {
                if (token === this.token) this.speaking = true;
            }).catch(() => finish('unavailable'));
        });
    }

    connect(audio) {
        if (!this.context) return;
        try {
            this.source = this.context.createMediaElementSource(audio);
            this.analyser = this.context.createAnalyser();
            this.analyser.fftSize = 1024;
            this.source.connect(this.analyser);
            this.analyser.connect(this.context.destination);
            this.buffer = new Uint8Array(this.analyser.fftSize);
        } catch {
            // Some browsers refuse a second source on one element. Without the
            // analyser the mouth falls back to a timed movement below.
            this.analyser = null;
        }
    }

    /** How loud this instant is, 0 to 1. */
    measure(t) {
        if (!this.speaking) {
            this.level = 0;
            return 0;
        }
        if (this.analyser && this.buffer) {
            this.analyser.getByteTimeDomainData(this.buffer);
            let sum = 0;
            for (let i = 0; i < this.buffer.length; i++) {
                const v = (this.buffer[i] - 128) / 128;
                sum += v * v;
            }
            this.level = Math.min(1, Math.sqrt(sum / this.buffer.length) * 6.5);
        } else {
            // No analyser: a plausible mouth rather than a still one, and the
            // interface says so rather than pretending it is lip-sync.
            this.level = 0.2 + Math.abs(Math.sin(t * 15) * Math.sin(t * 6.3)) * 0.65;
        }
        return this.level;
    }

    /**
     * A coarse mouth shape from the loudness.
     *
     * Without phoneme timings this is honest guesswork: loud is open, quiet is
     * nearly closed. It reads correctly at conversational distance and does not
     * claim to be a viseme track.
     */
    shape() {
        // Shared with the standalone speaker, so a coach's avatar and a
        // character in a scene cannot end up moving their mouths differently
        // for the same sound.
        return this.speaking ? visemeFor(this.level) : 'sil';
    }

    get analysing() {
        return Boolean(this.analyser);
    }
}
