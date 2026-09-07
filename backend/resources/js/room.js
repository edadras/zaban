/*
 * The live classroom, as the coach drives it.
 *
 * Two connections, doing different jobs. LiveKit carries faces and voices; the
 * websocket carries decisions - who may speak, what is on screen, what was
 * asked. Keeping them apart is what makes the room survive a bad network: a
 * learner whose video drops still sees the material and still answers the
 * question, and a learner who reloads comes back muted if that is how the coach
 * left them, because the permission is a row on the server rather than a state
 * in a browser.
 */

import {
    Room,
    RoomEvent,
    Track,
    ConnectionState,
} from 'livekit-client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const config = window.__ROOM__;
const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

const el = {
    stage: document.getElementById('stage'),
    askable: document.getElementById('askable'),
    tiles: document.getElementById('tiles'),
    roster: document.getElementById('roster'),
    shelf: document.getElementById('shelf'),
    question: document.getElementById('question'),
    lock: document.getElementById('lock-preview'),
    status: document.getElementById('room-status'),
    micButton: document.getElementById('toggle-mic'),
    camButton: document.getElementById('toggle-cam'),
};

const state = {
    session: null,
    isCoach: false,
    participants: [],
    materials: [],
    sharedMaterialId: null,
    openQuestion: null,
    askable: [],
    me: config.userId,
    mediaUrls: new Map(),
};

let room = null;

/*
 * A room key lasts six hours and a class can be scheduled for eight, so a
 * dropped media connection is re-made with a *fresh* key rather than the one
 * the page was handed. `leaving` stops the class's own ending from looking
 * like a network failure worth retrying.
 */
let reconnecting = false;
let leaving = false;

/*
 * A playback link is signed and lasts an hour. A class can run longer than
 * that, so the cache holds when it was fetched and lets a stale one go rather
 * than putting a dead URL on the screen an hour into the lesson.
 */
const MEDIA_URL_TTL_MS = 45 * 60 * 1000;

// ---------------------------------------------------------------- the API

async function api(path, { method = 'GET', body = null } = {}) {
    const response = await fetch(`/api/v1/class-sessions/${config.sessionId}${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${config.token}`,
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : null,
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        note(payload?.error?.message ?? 'درخواست انجام نشد.', true);
        throw new Error(payload?.error?.message ?? response.statusText);
    }

    return payload.data;
}

function note(message, isError = false) {
    el.status.textContent = message;
    el.status.className = isError
        ? 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800'
        : 'rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-600';
}

// ------------------------------------------------------------ the room state

async function refresh() {
    const data = await api('/room');

    state.session = data.session;
    state.isCoach = data.is_coach;
    state.participants = data.participants;
    state.materials = data.materials;
    state.sharedMaterialId = data.shared_material_id;
    state.openQuestion = data.open_question;

    render();
}

function render() {
    renderRoster();
    renderShelf();
    renderAskable();
    renderStage();
    renderQuestion();
    labelTiles();
    syncLocalPublishing();
}

/*
 * Tiles are created the moment a track arrives, which is often before the
 * roster row naming that person does. Relabelling on every render is what
 * stops a learner who joined mid-lesson from staying anonymous for the rest
 * of it.
 */
function labelTiles() {
    el.tiles.querySelectorAll('[data-identity]').forEach((tile) => {
        const userId = Number(String(tile.dataset.identity).replace(/^u/, ''));
        const person = state.participants.find((p) => p.user_id === userId);
        const label = tile.querySelector('[data-tile-name]');

        if (label && person) label.textContent = person.name ?? '';
    });
}

const escape = (value) => String(value ?? '').replace(/[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ------------------------------------------------------------------- roster

function renderRoster() {
    const rows = state.participants.map((p) => {
        const hand = p.hand_raised ? '<span class="chip bg-amber-100 text-amber-800">دست بالا</span>' : '';
        const away = p.is_present ? '' : '<span class="chip bg-ink-100 text-ink-500">بیرون</span>';

        const controls = state.isCoach && p.role !== 'coach' ? `
            <div class="flex items-center gap-1">
                <button class="btn-ghost !px-2" data-media="${p.id}" data-track="audio"
                        data-value="${p.can_publish_audio ? 'false' : 'true'}"
                        title="میکروفون">${p.can_publish_audio ? '🎤' : '🔇'}</button>
                <button class="btn-ghost !px-2" data-media="${p.id}" data-track="video"
                        data-value="${p.can_publish_video ? 'false' : 'true'}"
                        title="دوربین">${p.can_publish_video ? '🎥' : '📷'}</button>
                <button class="btn-danger !px-2" data-remove="${p.id}" title="بیرون کردن">✕</button>
            </div>` : '';

        return `
            <li class="flex items-center justify-between gap-2 px-4 py-2.5" data-user="${p.user_id}">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">${escape(p.name)}</p>
                    <p class="text-xs text-ink-400">
                        ${p.role === 'coach' ? 'مربی' : 'زبان‌آموز'} ${hand} ${away}
                    </p>
                </div>
                ${controls}
            </li>`;
    });

    el.roster.innerHTML = rows.join('') ||
        '<li class="px-4 py-6 text-center text-sm text-ink-400">هنوز کسی وارد نشده است.</li>';
}

// -------------------------------------------------------------------- shelf

const kindLabels = {
    video: 'ویدیو', pdf: 'پی‌دی‌اف', image: 'تصویر', audio: 'صوت',
    text: 'متن', quiz: 'آزمون', lesson: 'درس', exercise: 'تمرین',
};

function renderShelf() {
    if (!state.isCoach) {
        el.shelf.closest('[data-coach-only]')?.setAttribute('hidden', 'hidden');
        return;
    }

    el.shelf.innerHTML = state.materials.map((m) => `
        <li class="flex items-center justify-between gap-2 px-4 py-2.5">
            <div class="min-w-0">
                <p class="truncate text-sm">${escape(m.title)}</p>
                <p class="text-xs text-ink-400">${kindLabels[m.kind] ?? m.kind}</p>
            </div>
            ${m.id === state.sharedMaterialId
                ? `<button class="btn-danger" data-close-material="${m.id}">برداشتن</button>`
                : `<button class="btn-ghost" data-share-material="${m.id}">نمایش</button>`}
        </li>`).join('') ||
        '<li class="px-4 py-6 text-center text-sm text-ink-400">محتوایی افزوده نشده است.</li>';
}

/*
 * The questions today's material can be asked.
 *
 * Asking one sends only its id: the server takes the wording, the options and
 * the right answer from the corpus, so the room marks itself and nobody
 * decides twice what right looks like.
 */
function renderAskable() {
    if (!el.askable) return;

    el.askable.innerHTML = state.askable.map((exercise) => `
        <li class="flex items-start justify-between gap-2 px-4 py-2.5">
            <span class="min-w-0 flex-1 text-sm">${escape(exercise.stem)}</span>
            <button class="btn-ghost shrink-0" data-ask-exercise="${exercise.id}">بپرس</button>
        </li>`).join('') ||
        '<li class="px-4 py-4 text-center text-xs text-ink-400">'
        + 'درسی از سامانه به این جلسه اضافه نشده است.</li>';
}

// -------------------------------------------------------------------- stage

async function renderStage() {
    const shared = state.materials.find((m) => m.id === state.sharedMaterialId);

    if (!shared) {
        el.stage.innerHTML = '<p class="p-10 text-center text-sm text-ink-400">چیزی روی صفحه نیست.</p>';
        return;
    }

    if (shared.kind === 'text' || shared.kind === 'quiz') {
        el.stage.innerHTML = `
            <article class="prose-sm max-w-none whitespace-pre-wrap p-6 text-sm leading-7">
                <h3 class="mb-3 text-base font-semibold">${escape(shared.title)}</h3>
                ${escape(shared.body)}
            </article>`;
        return;
    }

    if (shared.kind === 'lesson' || shared.kind === 'exercise') {
        el.stage.innerHTML = `
            <div class="p-10 text-center">
                <p class="text-base font-semibold">${escape(shared.title)}</p>
                <p class="mt-2 text-sm text-ink-400">
                    این درس در اپلیکیشن زبان‌آموزان باز می‌شود.
                </p>
            </div>`;
        return;
    }

    if (!shared.media_asset_id) {
        el.stage.innerHTML = `<p class="p-10 text-center text-sm text-ink-400">${escape(shared.title)}</p>`;
        return;
    }

    const url = await mediaUrl(shared.media_asset_id);

    el.stage.innerHTML = shared.kind === 'image'
        ? `<img class="mx-auto max-h-[70vh]" src="${url}" alt="${escape(shared.title)}">`
        : shared.kind === 'audio'
            ? `<div class="p-8"><audio class="w-full" controls src="${url}"></audio></div>`
            : shared.kind === 'pdf'
                ? `<iframe class="h-[70vh] w-full" src="${url}" title="${escape(shared.title)}"></iframe>`
                : `<video class="max-h-[70vh] w-full bg-black" controls src="${url}"></video>`;
}

/** Media is fetched by id and served through a short-lived signed link. */
async function mediaUrl(id) {
    const cached = state.mediaUrls.get(id);
    if (cached && Date.now() - cached.at < MEDIA_URL_TTL_MS) return cached.url;

    const response = await fetch(`/api/v1/media/${id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${config.token}` },
    });
    const payload = await response.json();
    const url = payload?.data?.url ?? '';

    state.mediaUrls.set(id, { url, at: Date.now() });

    return url;
}

// ----------------------------------------------------------------- question

function renderQuestion() {
    const q = state.openQuestion;

    if (!q) {
        el.question.innerHTML =
            '<p class="px-4 py-6 text-center text-sm text-ink-400">پرسشی باز نیست.</p>';
        return;
    }

    const answers = (q.answers ?? []).map((a) => `
        <li class="flex items-start justify-between gap-2 border-t border-ink-100 px-4 py-2 text-sm">
            <span class="font-medium">${escape(a.name)}</span>
            <span class="min-w-0 flex-1 text-ink-600">
                ${escape(a.body ?? (a.selected_options ?? []).join('، '))}
            </span>
            ${a.is_correct === null || a.is_correct === undefined ? ''
                : a.is_correct ? '<span class="chip bg-emerald-100 text-emerald-800">درست</span>'
                                : '<span class="chip bg-red-100 text-red-700">نادرست</span>'}
        </li>`).join('');

    el.question.innerHTML = `
        <div class="px-4 py-3">
            <p class="text-sm font-medium">${escape(q.prompt)}</p>
            ${(q.options ?? []).length
                ? `<ol class="mt-2 list-decimal space-y-1 ps-5 text-sm text-ink-600">
                       ${q.options.map((o) => `<li>${escape(o)}</li>`).join('')}
                   </ol>`
                : ''}
            <button class="btn-ghost mt-3" data-close-question="${q.id}">بستن پرسش</button>
        </div>
        <ul>${answers}</ul>`;
}

// ---------------------------------------------------------------- the media

async function connectMedia() {
    const joined = await api('/room/join', { method: 'POST' });

    state.participants = state.participants.filter((p) => p.id !== joined.participant.id);
    state.participants.push(joined.participant);

    if (!joined.room_available || joined.room.provider === 'null') {
        note('سرور تصویر پیکربندی نشده است؛ بقیهٔ کلاس کار می‌کند.');
        render();
        return;
    }

    room = new Room({ adaptiveStream: true, dynacast: true });

    room
        .on(RoomEvent.TrackSubscribed, (track, _publication, participant) => {
            attach(track, participant.identity);
        })
        .on(RoomEvent.TrackUnsubscribed, (track) => {
            track.detach().forEach((element) => element.remove());
            pruneTiles();
        })
        .on(RoomEvent.ParticipantDisconnected, () => pruneTiles())
        .on(RoomEvent.LocalTrackPublished, (publication) => {
            if (publication.track) attach(publication.track, room.localParticipant.identity, true);
        })
        .on(RoomEvent.ConnectionStateChanged, (connectionState) => {
            if (connectionState === ConnectionState.Reconnecting) note('در حال اتصال دوباره…');
            if (connectionState === ConnectionState.Connected) note('متصل.');
        })
        .on(RoomEvent.Disconnected, () => {
            if (leaving) return;

            note('ارتباط تصویری قطع شد؛ در حال اتصال دوباره…', true);
            setTimeout(reconnectMedia, 2000);
        });

    await room.connect(joined.room.url, joined.room.token);
    note('متصل.');

    await syncLocalPublishing();
    render();
}

async function reconnectMedia() {
    if (reconnecting || leaving || room === null) return;
    if (state.session?.status !== 'live') return;

    reconnecting = true;

    try {
        const key = await api('/room/token', { method: 'POST' });

        if (key.provider === 'null' || !key.url || !key.token) return;

        await room.connect(key.url, key.token);
        note('متصل.');
        await syncLocalPublishing();
    } catch {
        note('ارتباط تصویری برقرار نشد. صفحه را تازه کنید.', true);
    } finally {
        reconnecting = false;
    }
}

/**
 * The browser publishes what the server says it may publish.
 *
 * Not what the person clicked: the coach's decision is the authority, and this
 * runs again on every room update, so revoking a microphone stops the stream
 * rather than only greying out a button.
 */
async function syncLocalPublishing() {
    if (!room || room.state !== ConnectionState.Connected) return;

    const mine = state.participants.find((p) => p.user_id === state.me);
    if (!mine) return;

    const wantMic = mine.can_publish_audio && el.micButton?.dataset.on === 'true';
    const wantCam = mine.can_publish_video && el.camButton?.dataset.on === 'true';

    if (room.localParticipant.isMicrophoneEnabled !== wantMic) {
        await room.localParticipant.setMicrophoneEnabled(wantMic);
    }
    if (room.localParticipant.isCameraEnabled !== wantCam) {
        await room.localParticipant.setCameraEnabled(wantCam);
    }

    if (el.micButton) {
        el.micButton.disabled = !mine.can_publish_audio;
        el.micButton.textContent = wantMic ? '🎤 میکروفون باز' : '🔇 میکروفون بسته';
    }
    if (el.camButton) {
        el.camButton.disabled = !mine.can_publish_video;
        el.camButton.textContent = wantCam ? '🎥 دوربین باز' : '📷 دوربین بسته';
    }
}

/** One tile per person, however many tracks they publish. */
function attach(track, identity, isLocal = false) {
    const userId = Number(String(identity).replace(/^u/, ''));
    const person = state.participants.find((p) => p.user_id === userId);

    let tile = el.tiles.querySelector(`[data-identity="${identity}"]`);

    if (!tile) {
        tile = document.createElement('div');
        tile.dataset.identity = identity;
        tile.className = 'relative overflow-hidden rounded-xl bg-ink-900 aspect-video';
        tile.innerHTML = `<span data-tile-name class="absolute bottom-2 start-2 z-10 rounded bg-black/60
                                       px-2 py-0.5 text-xs text-white">${escape(person?.name ?? '')}</span>`;
        el.tiles.append(tile);
    }

    const element = track.attach();

    if (track.kind === Track.Kind.Video) {
        element.className = 'h-full w-full object-cover';
        if (isLocal) element.style.transform = 'scaleX(-1)';   // a mirror, as a mirror behaves
        tile.querySelector('video')?.remove();
    } else {
        element.className = 'hidden';
        if (isLocal) element.muted = true;                     // never hear yourself
    }

    tile.append(element);
    layoutTiles();
}

function pruneTiles() {
    el.tiles.querySelectorAll('[data-identity]').forEach((tile) => {
        if (!tile.querySelector('video, audio')) tile.remove();
    });
    layoutTiles();
}

/**
 * Videos side by side.
 *
 * Column count from the number of tiles rather than a fixed grid, so two people
 * fill the screen and twelve stay legible.
 */
function layoutTiles() {
    const count = el.tiles.children.length;
    const columns = count <= 1 ? 1 : count <= 4 ? 2 : count <= 9 ? 3 : 4;

    el.tiles.style.gridTemplateColumns = `repeat(${columns}, minmax(0, 1fr))`;
}

// -------------------------------------------------------------- the socket

function listen() {
    if (!config.reverb.key) return;

    window.Pusher = Pusher;

    const echo = new Echo({
        broadcaster: 'reverb',
        key: config.reverb.key,
        wsHost: config.reverb.host,
        wsPort: config.reverb.port,
        wssPort: config.reverb.port,
        forceTLS: config.reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: { headers: { 'X-CSRF-TOKEN': csrf } },
    });

    echo.private(`class-session.${config.sessionId}`).listen('.classroom', (event) => {
        if (event.type === 'session.ended') {
            note('کلاس پایان یافت.');
            leaving = true;
            room?.disconnect();
        }

        // Everything else is "the room changed": ask the server what it looks
        // like now rather than trying to patch a copy of it here.
        refresh().catch(() => {});
    });
}

// ------------------------------------------------------------- the controls

document.addEventListener('click', async (event) => {
    const target = event.target.closest('[data-media],[data-remove],[data-share-material],'
        + '[data-close-material],[data-close-question],[data-mute-all],[data-lock],[data-unlock],'
        + '[data-hand],[data-ask-exercise]');

    if (!target) return;

    event.preventDefault();
    target.disabled = true;

    try {
        if (target.dataset.media) {
            await api(`/room/participants/${target.dataset.media}/media`, {
                method: 'POST',
                body: { [target.dataset.track]: target.dataset.value === 'true' },
            });
        } else if (target.dataset.remove) {
            await api(`/room/participants/${target.dataset.remove}`, { method: 'DELETE' });
        } else if (target.dataset.shareMaterial) {
            await api(`/room/materials/${target.dataset.shareMaterial}/share`, { method: 'POST' });
        } else if (target.dataset.closeMaterial) {
            await api(`/room/materials/${target.dataset.closeMaterial}/close`, { method: 'POST' });
        } else if (target.dataset.askExercise) {
            await api('/room/questions', {
                method: 'POST',
                body: {
                    kind: 'exercise',
                    exercise_id: Number(target.dataset.askExercise),
                },
            });
        } else if (target.dataset.closeQuestion) {
            await api(`/room/questions/${target.dataset.closeQuestion}/close`, { method: 'POST' });
        } else if (target.hasAttribute('data-mute-all')) {
            await api('/room/mute-all', { method: 'POST' });
        } else if (target.hasAttribute('data-lock')) {
            await lockPractice();
        } else if (target.hasAttribute('data-unlock')) {
            await api('/room/unlock', { method: 'POST' });
            note('قفل تمرین برداشته شد.');
        } else if (target.hasAttribute('data-hand')) {
            await api('/room/hand', { method: 'POST', body: { raised: true } });
        }

        await refresh();
    } catch {
        // api() has already said what went wrong.
    } finally {
        target.disabled = false;
    }
});

async function lockPractice() {
    const note_ = document.getElementById('lock-note')?.value ?? '';
    const hours = Number(document.getElementById('lock-hours')?.value ?? 24);

    const result = await api('/room/lock', {
        method: 'POST',
        body: { note: note_, hours },
    });

    note(`تمرین ${result.locked} زبان‌آموز روی ${result.concept_count} مفهوم امروز قفل شد.`);
}

/* Asking the room something. */
document.getElementById('ask')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const form = event.target;
    const options = form.options_text.value
        .split('\n').map((line) => line.trim()).filter(Boolean);

    const correct = form.correct.value
        .split(',').map((n) => Number(n.trim()) - 1).filter((n) => n >= 0);

    await api('/room/questions', {
        method: 'POST',
        body: {
            kind: options.length ? 'poll' : 'open',
            prompt: form.prompt.value,
            options: options.length ? options : null,
            correct_options: options.length && correct.length ? correct : null,
        },
    });

    form.reset();
    await refresh();
});

/* The coach's own microphone and camera. */
[el.micButton, el.camButton].forEach((button) => {
    button?.addEventListener('click', async () => {
        button.dataset.on = button.dataset.on === 'true' ? 'false' : 'true';
        await syncLocalPublishing();
    });
});

/* Leaving the page is leaving the room, so attendance is not overstated. */
window.addEventListener('pagehide', () => {
    leaving = true;

    // keepalive rather than sendBeacon: the leave endpoint authenticates by
    // bearer token, and a beacon cannot carry a header.
    fetch(`/api/v1/class-sessions/${config.sessionId}/room/leave`, {
        method: 'POST',
        keepalive: true,
        headers: { Accept: 'application/json', Authorization: `Bearer ${config.token}` },
    }).catch(() => {});

    room?.disconnect();
});

// ------------------------------------------------------------------- start

(async () => {
    try {
        await refresh();
        await connectMedia();
        listen();

        if (state.isCoach) {
            const preview = await api('/room/lock-preview');
            el.lock.textContent =
                `${preview.concept_count} مفهوم از این جلسه، برای ${preview.student_count} زبان‌آموز.`;

            state.askable = await api('/room/askable');
            renderAskable();
        }
    } catch (error) {
        note(error.message ?? 'اتاق باز نشد.', true);
    }
})();
