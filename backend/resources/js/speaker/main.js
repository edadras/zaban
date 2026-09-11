import { SpeakerStage } from './stage.js';

/**
 * The speaker page.
 *
 * A figure, a line, and a play button. It is opened in a web view inside the
 * app and in an iframe in the panel, handed everything it needs by a signed
 * link, and holds no credential of its own - the same arrangement the acted
 * scenes use, for the same reason.
 *
 * What it is careful about is telling the truth when there is no sound. A
 * recording that will not play leaves the figure still and says so, because a
 * mouth moving to silence teaches a learner nothing and quietly suggests their
 * own audio is broken.
 */

const data = window.__SPEAKER_PAGE__ || {};

const el = {
    stage: document.getElementById('speaker-stage'),
    play: document.querySelector('[data-play]'),
    caption: document.querySelector('[data-caption]'),
    note: document.querySelector('[data-note]'),
};

const say = (text) => {
    if (!el.note) return;
    el.note.hidden = !text;
    el.note.textContent = text || '';
};

const label = (playing) => {
    if (el.play) el.play.textContent = playing ? data.strings.playing : data.strings.play;
};

async function start() {
    const stage = await new SpeakerStage(el.stage, {
        character: data.character,
        kit: data.kit,
    }).load();

    if (data.expression) stage.setExpression(data.expression);

    let playing = false;

    const speak = async () => {
        if (playing) {
            stage.stop();
            playing = false;
            label(false);

            return;
        }

        const ready = await stage.resume();
        if (!ready) {
            say(data.strings.noAudio);

            return;
        }

        playing = true;
        label(true);
        say('');

        const how = await stage.play(data.audio, { from: data.from, to: data.to });

        playing = false;
        label(false);

        if (how === 'unavailable') say(data.strings.noRecording);
    };

    el.play?.addEventListener('click', speak);

    // The host asks for a line by posting to the frame, so a lesson or an exam
    // can move the speaker on to the next thing without reloading the page and
    // paying for the kit again.
    window.addEventListener('message', (event) => {
        const message = event.data;
        if (!message || message.speaker !== 'say') return;

        data.audio = message.audio ?? null;
        data.from = message.from ?? null;
        data.to = message.to ?? null;
        if (el.caption && 'caption' in message) el.caption.textContent = message.caption || '';
        if (message.expression) stage.setExpression(message.expression);
        if (message.play) speak();
    });

    /*
     * Autoplay is asked for, not assumed. Where the browser allows it the line
     * simply plays; where it does not, the button is still there and the note
     * explains why nothing happened.
     */
    if (data.autoplay) speak();
}

if (el.stage) {
    start().catch((error) => {
        say(error?.message || String(error));
    });
}
