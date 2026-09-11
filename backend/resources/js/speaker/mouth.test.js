/**
 * The mouth, which three features now share.
 *
 * Worth pinning precisely because it is shared: a lesson's presenter, an
 * examiner and a coach's avatar all read their mouth shape from here, and a
 * change made for one of them silently changes the other two.
 *
 *   node --test resources/js
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { visemeFor, loudnessOf, VISEME_STEPS } from './mouth.js';

test('a loud moment opens the mouth', () => {
    assert.equal(visemeFor(1), 'aa');
    assert.equal(visemeFor(0.8), 'aa');
});

test('a quiet moment nearly closes it', () => {
    assert.equal(visemeFor(0.05), 'PP');
    assert.equal(visemeFor(0), 'PP');
});

test('the shapes step down in order as it gets quieter', () => {
    const walk = [1, 0.5, 0.3, 0.1].map(visemeFor);

    assert.deepEqual(walk, ['aa', 'E', 'oh', 'PP']);
});

test('every step is reachable', () => {
    // A threshold nobody can cross is a shape the mouth never makes, which is
    // the kind of thing that only shows up as a figure that mumbles.
    const shapes = new Set(
        Array.from({ length: 101 }, (_, i) => visemeFor(i / 100)),
    );

    assert.deepEqual([...shapes].sort(), ['E', 'PP', 'aa', 'oh']);
});

test('the steps are ordered loudest first', () => {
    // visemeFor walks them in order and returns the first it clears, so an
    // unsorted list would hand back the wrong shape for every loud moment.
    const thresholds = VISEME_STEPS.map((s) => s.at);
    const sorted = [...thresholds].sort((a, b) => b - a);

    assert.deepEqual(thresholds, sorted);
});

test('a level outside 0..1 still picks a shape', () => {
    // Loudness is clamped upstream, but a mouth is not the place to throw.
    assert.equal(visemeFor(4), 'aa');
    assert.equal(visemeFor(-1), 'PP');
});

// ------------------------------------------------------------- loudness

/** An analyser stub: time-domain bytes centred on 128, as the Web Audio API gives them. */
function analyser(samples) {
    return {
        getByteTimeDomainData(buffer) {
            for (let i = 0; i < buffer.length; i++) buffer[i] = samples[i % samples.length];
        },
    };
}

test('silence measures zero', () => {
    const buffer = new Uint8Array(64);

    assert.equal(loudnessOf(analyser([128]), buffer), 0);
});

test('a full-scale square wave measures one', () => {
    const buffer = new Uint8Array(64);

    // Clamped rather than allowed past 1: the level is used as a morph
    // influence, and a mouth opened past its shape key looks broken.
    assert.equal(loudnessOf(analyser([0, 255]), buffer), 1);
});

test('a louder signal measures higher than a quieter one', () => {
    const buffer = new Uint8Array(64);
    const quiet = loudnessOf(analyser([124, 132]), buffer);
    const loud = loudnessOf(analyser([108, 148]), buffer);

    assert.ok(quiet > 0, 'a quiet signal is not silence');
    assert.ok(loud > quiet, `expected ${loud} > ${quiet}`);
});

test('it measures power over the window, not one sample', () => {
    const buffer = new Uint8Array(64);

    // The same deviation, once versus sustained. A peak follower would score
    // these the same and snap the mouth open on a single click; measuring
    // power means a sound has to actually last to open the mouth.
    const once = new Array(64).fill(128);
    once[0] = 138;
    const sustained = [138, 118];

    const spike = loudnessOf(analyser(once), buffer);
    const held = loudnessOf(analyser(sustained), buffer);

    assert.ok(spike > 0, 'a click is still heard');
    assert.ok(held > spike * 4, `a sustained sound should dominate: ${held} vs ${spike}`);
});

test('a very loud click still opens the mouth', () => {
    const buffer = new Uint8Array(64);
    const bang = new Array(64).fill(128);
    bang[0] = 255;

    // Honest about the limit: enough power in one sample does saturate. The
    // window is short enough that this is a real bang rather than a tick, and
    // a mouth that ignored it would look asleep.
    assert.ok(loudnessOf(analyser(bang), buffer) > 0.5);
});
