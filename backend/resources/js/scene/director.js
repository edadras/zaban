/**
 * Running the scene.
 *
 * The loop is small on purpose: play the line, and if the next one belongs to
 * the learner, stop and wait. Everything about whether their answer counted is
 * decided on the server - this only asks and reacts - so the worst a tampered
 * page can do is lie to the person holding it.
 *
 * A run token guards every await. Closing the scene, or jumping back to replay
 * a line, increments it, and any playback still in flight sees the change and
 * abandons quietly rather than talking over what came after it.
 */
export class Director {
    constructor(hooks) {
        this.hooks = hooks;
        this.run = 0;
        this.index = 0;
        this.playing = false;
        this.waiting = null;
    }

    get beats() {
        return this.hooks.beats();
    }

    stop() {
        this.run++;
        this.playing = false;
        this.waiting = null;
        this.hooks.voice.stop();
        this.hooks.onIdle();
    }

    /** Jump to a line and play just that one, for the replay button. */
    async replay(index, slow = false) {
        this.stop();
        const token = ++this.run;
        this.index = index;
        await this.speak(this.beats[index], token, slow);
        if (token === this.run) this.hooks.onIdle();
    }

    /** Play from wherever the run currently is until a learner line stops it. */
    async play(from = null) {
        if (this.playing) {
            this.stop();
            return;
        }

        const token = ++this.run;
        this.playing = true;
        if (from !== null) this.index = from;
        this.hooks.onPlaying(true);

        try {
            while (token === this.run && this.index < this.beats.length) {
                const beat = this.beats[this.index];

                if (beat.interaction !== 'watch' && !this.hooks.isCleared(beat)) {
                    this.waiting = beat;
                    this.hooks.onTurn(beat);
                    break;
                }

                await this.speak(beat, token);
                if (token !== this.run) return;

                this.index += 1;
            }

            if (token === this.run && this.index >= this.beats.length) {
                this.playing = false;
                await this.hooks.onComplete();
            }
        } catch (error) {
            this.hooks.onError(error);
        } finally {
            if (token === this.run) {
                this.playing = false;
                this.hooks.onPlaying(false);
            }
        }
    }

    async speak(beat, token, slow = false) {
        if (!beat) return;

        this.hooks.onLine(beat);
        this.hooks.stage(beat);

        const how = await this.hooks.voice.play(beat.audio, slow ? 0.75 : 1);

        if (token !== this.run) return;

        if (how === 'unavailable') {
            // No recording for this line: hold on it long enough to read,
            // rather than flicking past it as though nothing was said.
            await this.hold(beat);
            if (token !== this.run) return;
        }

        this.hooks.rest(beat);
    }

    /**
     * Roughly how long the line would take to say, for lines with no audio.
     *
     * The caller checks the run token when this resolves, so there is nothing
     * to cancel: a stop during the pause is noticed the moment it ends.
     */
    hold(beat) {
        const words = beat.word_count || Math.max(1, (beat.text || '').split(/\s+/).length);
        const ms = Math.min(9000, 700 + words * 380);

        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    /**
     * The learner has dealt with the line they were asked for.
     *
     * Whether they got it right or ran out of tries, the scene moves on: a
     * conversation that refuses to continue until you say the sentence
     * perfectly is not a conversation.
     */
    async resolveTurn(verdict) {
        if (!this.waiting) return;

        const beat = this.waiting;

        if (!verdict.accepted && !verdict.revealed) {
            // Still their line. The beat stays the one being waited on - drop
            // it here and the next thing they say is answered by nobody, which
            // leaves the scene stopped on a turn it will not take again.
            this.hooks.onTurn(beat, true);
            return;
        }

        this.waiting = null;

        const token = ++this.run;
        this.playing = true;
        this.hooks.onPlaying(true);

        // Play their line back in the character's voice, so they hear the model
        // straight after their own attempt - which is where the comparison is
        // worth the most.
        await this.speak(beat, token);
        if (token !== this.run) return;

        this.index += 1;
        this.playing = false;
        await this.play(this.index);
    }
}
