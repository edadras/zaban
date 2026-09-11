/**
 * The run loop, without a browser.
 *
 * The case that matters most here is the second try. A rejected answer has to
 * leave the line still waiting to be answered; the first version of this code
 * cleared it, so the learner's next attempt was handed to nobody and the scene
 * stopped dead on a turn it would never take again. It played perfectly and
 * could not be finished, which is exactly the kind of fault a server-side suite
 * cannot see.
 *
 *   node --test resources/js
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { Director } from './director.js';

const BEATS = [
    { id: 1, position: 1, role: 'agent', interaction: 'watch', text: 'Good morning.', word_count: 2 },
    { id: 2, position: 2, role: 'you', interaction: 'speak', text: 'I have a booking.', word_count: 4 },
    { id: 3, position: 3, role: 'agent', interaction: 'watch', text: 'Of course.', word_count: 2 },
];

/** A director wired to recording hooks and a voice that returns at once. */
function build(beats = BEATS) {
    const log = { turns: [], lines: [], completed: 0, errors: [], playing: [] };
    const cleared = new Set();

    const director = new Director({
        voice: { play: async () => 'ended', stop: () => {} },
        beats: () => beats,
        isCleared: (beat) => cleared.has(beat.id),
        stage: () => {},
        rest: () => {},
        onLine: (beat) => log.lines.push(beat.position),
        onTurn: (beat, again) => log.turns.push({ position: beat.position, again: Boolean(again) }),
        onPlaying: (on) => log.playing.push(on),
        onIdle: () => {},
        onComplete: async () => { log.completed += 1; },
        onError: (e) => log.errors.push(e.message),
    });

    return { director, log, cleared };
}

const miss = { accepted: false, revealed: false };
const hit = { accepted: true, revealed: false, line: 'I have a booking.' };
const given = { accepted: false, revealed: true, line: 'I have a booking.' };

test('the scene plays up to the learner line and stops there', async () => {
    const { director, log } = build();
    await director.play();

    assert.deepEqual(log.lines, [1], 'only the line before the turn is spoken');
    assert.deepEqual(log.turns, [{ position: 2, again: false }]);
    assert.equal(director.waiting?.id, 2);
});

test('a missed answer leaves the line waiting for another go', async () => {
    const { director, log } = build();
    await director.play();
    await director.resolveTurn(miss);

    assert.equal(director.waiting?.id, 2, 'the beat is still the one being answered');
    assert.deepEqual(log.turns.at(-1), { position: 2, again: true }, 'and it is asked again as a retry');
});

test('a second attempt on the same line is still graded', async () => {
    const { director, log } = build();
    await director.play();
    await director.resolveTurn(miss);
    await director.resolveTurn(miss);
    await director.resolveTurn(hit);

    assert.equal(director.waiting, null, 'the line is done with');
    assert.equal(log.turns.filter((t) => t.again).length, 2, 'two retries were asked for');
    assert.ok(log.lines.includes(3), 'and the scene carried on past it');
});

test('an accepted answer plays the line back and runs on to the end', async () => {
    const { director, log } = build();
    await director.play();
    await director.resolveTurn(hit);

    assert.deepEqual(log.lines, [1, 2, 3], 'their line is spoken back before the next one');
    assert.equal(log.completed, 1, 'and the scene finishes itself');
});

test('a line handed over after the last try also moves the scene on', async () => {
    const { director, log } = build();
    await director.play();
    await director.resolveTurn(given);

    assert.equal(director.waiting, null);
    assert.equal(log.completed, 1);
});

test('a verdict arriving with nothing waiting is ignored', async () => {
    const { director, log } = build();
    await director.resolveTurn(hit);

    assert.deepEqual(log.lines, [], 'nothing is spoken');
    assert.equal(log.completed, 0);
});

test('stopping abandons the turn rather than answering it later', async () => {
    const { director } = build();
    await director.play();
    director.stop();

    assert.equal(director.waiting, null);
    await director.resolveTurn(hit);
    assert.equal(director.waiting, null);
});

test('a line the learner already cleared is played, not asked again', async () => {
    const { director, log, cleared } = build();
    cleared.add(2);
    await director.play();

    assert.deepEqual(log.turns, [], 'no turn is asked');
    assert.deepEqual(log.lines, [1, 2, 3]);
    assert.equal(log.completed, 1);
});
