/**
 * What the player actually puts on the wire.
 *
 * This exists because of a fault the PHP tests could not see: they built the
 * request body themselves, so the server was proved right about a body the
 * page never sent. The page left `beat_id` out entirely and every answer came
 * back 422 - a scene that played beautifully and could not be answered.
 *
 *   node --test resources/js
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { SceneApi } from './api.js';

const ENDPOINTS = {
    state: 'https://example.test/scene/7/state?signature=aaa',
    answer: 'https://example.test/scene/7/answer?signature=bbb',
    finish: 'https://example.test/scene/7/finish?signature=ccc',
};

/** Stand in for fetch and keep what it was handed. */
function record(reply = { data: { ok: true } }, status = 200) {
    const calls = [];
    global.fetch = async (url, init) => {
        calls.push({ url, init, body: init?.body ? JSON.parse(init.body) : null });
        return {
            ok: status < 400,
            status,
            json: async () => reply,
        };
    };
    return calls;
}

test('an answer names its beat in the body', async () => {
    const calls = record();
    await new SceneApi(ENDPOINTS).answer(42, { text: 'I have a booking' });

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, ENDPOINTS.answer, 'the signed URL is used as given');
    assert.deepEqual(calls[0].body, { beat_id: 42, text: 'I have a booking' });
});

test('a chosen reply carries the choice and the beat together', async () => {
    const calls = record();
    await new SceneApi(ENDPOINTS).answer(9, { choice: 2 });

    assert.deepEqual(calls[0].body, { beat_id: 9, choice: 2 });
});

test('the signature is never rewritten out of the URL', async () => {
    const calls = record();
    await new SceneApi(ENDPOINTS).answer(1, { text: 'hello' });

    assert.ok(calls[0].url.includes('signature=bbb'));
    assert.ok(!calls[0].url.includes('__BEAT__'));
});

test('the envelope is unwrapped to its data', async () => {
    record({ data: { verdict: { accepted: true } } });
    const result = await new SceneApi(ENDPOINTS).answer(1, { text: 'hello' });

    assert.deepEqual(result, { verdict: { accepted: true } });
});

test('a refusal is raised with the server message the learner should see', async () => {
    record({ error: { code: 'no_answer', message: 'Say or type the line first.' } }, 422);

    await assert.rejects(
        () => new SceneApi(ENDPOINTS).answer(1, {}),
        /Say or type the line first\./,
    );
});

test('finishing posts to the finish endpoint with nothing to say', async () => {
    const calls = record();
    await new SceneApi(ENDPOINTS).finish();

    assert.equal(calls[0].url, ENDPOINTS.finish);
    assert.deepEqual(calls[0].body, {});
});
