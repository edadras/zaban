/**
 * A face for whoever has their camera off.
 *
 * A class where somebody is only a name on a black rectangle is a worse class.
 * Coaches turn the camera off for reasons that are not going to change - a
 * connection that will not carry video, a room they would rather not show, a
 * teacher who does not want her face streamed - and the lesson should not be
 * the thing that suffers for it.
 *
 * So: the rigged figure from the acted scenes, in the tile, with its mouth
 * moved by the audio track that is already arriving. It is not a likeness and
 * it does not claim to be one; it answers the question the black rectangle
 * cannot, which is who is talking right now.
 *
 * The renderer is imported only when somebody actually needs one. Most classes
 * have cameras on, and a coach on a slow line should not download a 3D
 * renderer to watch a lesson where nobody uses it.
 */

const avatars = new Map();

/** Loaded once, the first time a tile needs a figure. */
let stageModule = null;

async function stageClass() {
    stageModule ??= import('./speaker/stage.js');

    return (await stageModule).SpeakerStage;
}

/** A tile is showing video, so a figure would only be in the way. */
function hasVideo(tile) {
    return Boolean(tile.querySelector('video'));
}

/**
 * Put a figure in this tile, or take one away, depending on what is arriving.
 *
 * @param {HTMLElement} tile
 * @param {string} identity
 * @param {{kind:string, mediaStream?:MediaStream, mediaStreamTrack?:MediaStreamTrack}} track
 * @param {{character?:string, isLocal?:boolean}} options
 */
export async function updateAvatar(tile, identity, track, options = {}) {
    if (hasVideo(tile)) {
        remove(identity);

        return null;
    }

    // Only an audio track can drive a mouth, and only a track we can read.
    const stream = track?.mediaStream
        ?? (track?.mediaStreamTrack ? new MediaStream([track.mediaStreamTrack]) : null);

    if (!stream) return null;

    if (avatars.has(identity)) return avatars.get(identity);

    const mount = document.createElement('div');
    mount.dataset.avatar = identity;
    mount.className = 'absolute inset-0';
    tile.prepend(mount);

    try {
        const SpeakerStage = await stageClass();
        const stage = await new SpeakerStage(mount, {
            character: options.character || 'learner',
        }).load();

        /*
         * The figure is fed the track directly rather than through the
         * speakers: the media stack is already playing this audio, and routing
         * it a second time is an echo in everybody's headphones.
         *
         * The local participant is the exception in the other direction - their
         * own microphone is not played back at all, so there is a track to read
         * and nothing to echo.
         */
        await stage.resume();
        stage.listen(stream);

        avatars.set(identity, { stage, mount });

        return stage;
    } catch {
        // A renderer that will not start leaves the tile exactly as it was.
        mount.remove();

        return null;
    }
}

/**
 * Move the mouths of whoever is talking.
 *
 * Driven by the media server's own view of who is speaking rather than by the
 * loudness we measure, so a muted microphone cannot make a figure mouth along
 * to nothing - which would be a lie about who is in the conversation.
 *
 * @param {Array<{identity:string}>} speakers
 */
export function setActiveSpeakers(speakers) {
    const talking = new Set((speakers || []).map((p) => p.identity));

    for (const [identity, entry] of avatars) {
        entry.stage.setSpeaking(talking.has(identity));
    }
}

export function remove(identity) {
    const entry = avatars.get(identity);
    if (!entry) return;

    entry.stage.dispose();
    entry.mount.remove();
    avatars.delete(identity);
}

/** Whoever has left, or whose tile has gone. */
export function prune(liveIdentities) {
    const live = new Set(liveIdentities);

    for (const identity of [...avatars.keys()]) {
        if (!live.has(identity)) remove(identity);
    }
}

export function count() {
    return avatars.size;
}
