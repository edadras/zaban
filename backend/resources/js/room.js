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
    VideoPresets,
} from 'livekit-client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const config = window.__ROOM__;
const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

const el = {
    stage: document.getElementById('stage'),
    stageToolbar: document.getElementById('stage-toolbar'),
    askable: document.getElementById('askable'),
    tiles: document.getElementById('tiles'),
    roster: document.getElementById('roster'),
    shelf: document.getElementById('shelf'),
    question: document.getElementById('question'),
    lock: document.getElementById('lock-preview'),
    status: document.getElementById('room-status'),
    recordButton: document.getElementById('record'),
    recordStatus: document.getElementById('record-status'),
    micButton: document.getElementById('toggle-mic'),
    camButton: document.getElementById('toggle-cam'),
    chatLog: document.getElementById('chat-log'),
    chatForm: document.getElementById('chat-form'),
    chatInput: document.getElementById('chat-input'),
    shelfAdd: document.getElementById('shelf-add'),
    lightbox: document.getElementById('stage-lightbox'),
    lightboxBody: document.getElementById('lightbox-body'),
};

const state = {
    session: null,
    isCoach: false,
    participants: [],
    materials: [],
    sharedMaterialId: null,
    openQuestion: null,
    recording: null,
    askable: [],
    chat: [],
    stage: {
        mode: 'material',
        page: 1,
        media: { playing: false, position_ms: 0, updated_at: null },
        whiteboard: { strokes: [] },
    },
    me: config.userId,
    mediaUrls: new Map(),
    applyingMedia: false,
};

let room = null;
const STAGE_TOPIC = 'zaban.stage';
const stageEncoder = new TextEncoder();
const stageDecoder = new TextDecoder();
let draftStroke = null;
let mediaPersistTimer = null;

/*
 * A room key lasts six hours and a class can be scheduled for eight, so a
 * dropped media connection is re-made with a *fresh* key rather than the one
 * the page was handed. `leaving` stops the class's own ending from looking
 * like a network failure worth retrying.
 *
 * We deliberately do *not* auto-remake the Room on every Disconnected: that
 * fought LiveKit's own reconnect, released the camera every ~15s, and left
 * both sides staring at black tiles. Manual retry (or a full page refresh)
 * is the recovery path after the SDK has given up.
 */
let leaving = false;
let connecting = false;

/*
 * A playback link is signed and lasts an hour. A class can run longer than
 * that, so the cache holds when it was fetched and lets a stale one go rather
 * than putting a dead URL on the screen an hour into the lesson.
 */
const MEDIA_URL_TTL_MS = 45 * 60 * 1000;

// ---------------------------------------------------------------- the API

async function api(path, { method = 'GET', body = null, formData = null } = {}) {
    const headers = {
        Accept: 'application/json',
        Authorization: `Bearer ${config.token}`,
    };
    if (body && !formData) headers['Content-Type'] = 'application/json';

    const response = await fetch(`/api/v1/class-sessions/${config.sessionId}${path}`, {
        method,
        headers,
        body: formData ?? (body ? JSON.stringify(body) : null),
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
    state.recording = data.recording;
    state.stage = data.stage ?? state.stage;
    state.chat = data.chat ?? [];

    render();
}

function render() {
    renderRoster();
    renderShelf();
    renderAskable();
    renderChat();
    renderStage();
    renderQuestion();
    renderRecording();
    labelTiles();
    prefetchShelfMedia();
    // Do not call syncLocalPublishing here: every Echo tick was re-toggling
    // the camera and freezing the UI. Devices are synced on connect / click.
}

/** Resolve signed media URLs (and warm image/video buffers) ahead of a share click. */
function prefetchShelfMedia() {
    for (const m of state.materials ?? []) {
        if (!m.media_asset_id) continue;
        mediaUrl(m.media_asset_id).then((url) => {
            if (!url) return;
            if (m.kind === 'image') {
                const img = new Image();
                img.decoding = 'async';
                img.src = url;
            } else if (m.kind === 'video' || m.kind === 'audio') {
                // Hint the browser to start buffering without attaching to the stage.
                fetch(url, { method: 'HEAD', mode: 'cors' }).catch(() => {});
            }
        }).catch(() => {});
    }
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
            <div class="flex shrink-0 items-center gap-1">
                ${m.id === state.sharedMaterialId
                    ? `<button class="btn-danger" data-close-material="${m.id}">برداشتن</button>`
                    : `<button class="btn-ghost" data-share-material="${m.id}">نمایش</button>`}
                <button class="btn-ghost !px-2 text-red-700" data-delete-material="${m.id}" title="حذف">✕</button>
            </div>
        </li>`).join('') ||
        '<li class="px-4 py-6 text-center text-sm text-ink-400">محتوایی افزوده نشده است.</li>';
}

function renderChat() {
    if (!el.chatLog) return;

    el.chatLog.innerHTML = state.chat.map((message) => `
        <li class="rounded-lg bg-ink-50 px-2.5 py-1.5">
            <p class="text-[11px] font-medium text-ink-500">${escape(message.name ?? '—')}</p>
            <p class="whitespace-pre-wrap break-words text-sm">${escape(message.body)}</p>
        </li>`).join('') ||
        '<li class="py-4 text-center text-xs text-ink-400">هنوز پیامی نیست.</li>';

    el.chatLog.scrollTop = el.chatLog.scrollHeight;
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
    const mode = state.stage?.mode ?? 'material';
    const shared = state.materials.find((m) => m.id === state.sharedMaterialId);
    const stageKey = mode === 'whiteboard'
        ? `wb:${(state.stage.whiteboard?.strokes ?? []).length}`
        : shared
            ? `${shared.id}:${shared.media_asset_id ?? ''}:${shared.kind}`
            : 'empty';

    document.getElementById('mode-material')?.classList.toggle('!bg-ink-900', mode === 'material');
    document.getElementById('mode-material')?.classList.toggle('!text-white', mode === 'material');
    document.getElementById('mode-whiteboard')?.classList.toggle('!bg-ink-900', mode === 'whiteboard');
    document.getElementById('mode-whiteboard')?.classList.toggle('!text-white', mode === 'whiteboard');

    if (mode === 'whiteboard') {
        if (el.stage.dataset.stageKey !== 'whiteboard') {
            el.stage.dataset.stageKey = 'whiteboard';
            mountWhiteboard();
        } else {
            redrawWhiteboard();
        }
        renderStageToolbar('whiteboard');
        return;
    }

    // Avoid ripping the stage DOM out on every roster ping — that is what made
    // sharing a video feel like the whole page locked up.
    const materialChanged = el.stage.dataset.stageKey !== stageKey;
    if (materialChanged) {
        el.stage.dataset.stageKey = stageKey;
    }

    if (!shared) {
        if (materialChanged) {
            el.stage.innerHTML = '<p class="p-10 text-center text-sm text-ink-400">چیزی روی صفحه نیست.</p>';
        }
        renderStageToolbar(null);
        return;
    }

    if (!materialChanged) {
        applyPresentationToMounted(shared);
        renderStageToolbar(shared.kind);
        return;
    }

    if (shared.kind === 'text' || shared.kind === 'quiz') {
        el.stage.innerHTML = `
            <article class="prose-sm max-w-none cursor-zoom-in whitespace-pre-wrap p-6 text-sm leading-7" data-enlarge-source>
                <h3 class="mb-3 text-base font-semibold">${escape(shared.title)}</h3>
                ${escape(shared.body)}
            </article>`;
        renderStageToolbar(shared.kind);
        return;
    }

    if (shared.kind === 'lesson' || shared.kind === 'exercise') {
        el.stage.innerHTML = `
            <div class="p-10 text-center" data-enlarge-source>
                <p class="text-base font-semibold">${escape(shared.title)}</p>
                <p class="mt-2 text-sm text-ink-400">
                    این درس در اپلیکیشن زبان‌آموزان باز می‌شود.
                </p>
            </div>`;
        renderStageToolbar(shared.kind);
        return;
    }

    if (!shared.media_asset_id) {
        el.stage.innerHTML = `<p class="p-10 text-center text-sm text-ink-400">${escape(shared.title)}</p>`;
        renderStageToolbar(shared.kind);
        return;
    }

    // Instant shell so whiteboard→image/video never looks frozen while the URL resolves.
    el.stage.innerHTML = `
        <div class="flex h-[50vh] items-center justify-center text-sm text-ink-400" id="stage-loading">
            در حال آماده‌سازی محتوا…
        </div>`;
    renderStageToolbar(shared.kind);

    const url = await mediaUrl(shared.media_asset_id);
    // Coach may have switched away while we waited.
    if (el.stage.dataset.stageKey !== stageKey) return;
    const page = state.stage?.page ?? 1;

    if (shared.kind === 'image') {
        el.stage.innerHTML = `<img class="mx-auto max-h-[70vh] cursor-zoom-in" data-enlarge-source src="${url}" alt="${escape(shared.title)}" decoding="async">`;
    } else if (shared.kind === 'audio') {
        el.stage.innerHTML = `<div class="p-8"><audio id="stage-audio" class="w-full" preload="auto" src="${url}"></audio></div>`;
        wireSyncedMedia(document.getElementById('stage-audio'));
        } else if (shared.kind === 'pdf') {
        mountPdfViewer(url, page);
    } else {
        el.stage.innerHTML = `<video id="stage-video" class="max-h-[70vh] w-full bg-black" playsinline preload="auto" src="${url}"></video>`;
        wireSyncedMedia(document.getElementById('stage-video'));
    }

    if (el.stage.dataset.stageKey !== stageKey) return;
    applyPresentationToMounted(shared);
}

function renderStageToolbar(kind) {
    if (!el.stageToolbar) return;

    if (!state.isCoach || !kind) {
        el.stageToolbar.hidden = true;
        el.stageToolbar.innerHTML = '';
        return;
    }

    if (kind === 'whiteboard') {
        el.stageToolbar.hidden = false;
        el.stageToolbar.innerHTML = `
            <span class="text-xs text-ink-500">رنگ</span>
            <input type="color" id="wb-color" value="#111827" class="h-8 w-10 cursor-pointer rounded border border-ink-200">
            <button type="button" class="btn-ghost" data-wb-clear>پاک کردن تخته</button>
            <span class="text-xs text-ink-400">با ماوس روی تخته بکشید؛ همه می‌بینند.</span>`;
        return;
    }

    if (kind === 'pdf') {
        el.stageToolbar.hidden = false;
        el.stageToolbar.innerHTML = `
            <button type="button" class="btn-ghost" data-page-delta="-1">صفحه قبل</button>
            <span class="tabular text-sm">صفحه <span id="page-label">${state.stage?.page ?? 1}</span></span>
            <button type="button" class="btn-ghost" data-page-delta="1">صفحه بعد</button>`;
        return;
    }

    if (kind === 'video' || kind === 'audio') {
        const playing = !!state.stage?.media?.playing;
        el.stageToolbar.hidden = false;
        el.stageToolbar.innerHTML = `
            <button type="button" class="btn-primary" data-media-play>${playing ? '⏸ توقف موقت' : '▶ پخش'}</button>
            <button type="button" class="btn-ghost" data-media-stop>⏹ توقف</button>
            <span class="text-xs text-ink-400">پخش برای همهٔ کلاس از اینجا کنترل می‌شود.</span>`;
        return;
    }

    el.stageToolbar.hidden = true;
    el.stageToolbar.innerHTML = '';
}

function applyPresentationToMounted(shared) {
    const page = state.stage?.page ?? 1;
    const pageLabel = document.getElementById('page-label');
    if (pageLabel) pageLabel.textContent = String(page);

    if (shared.kind === 'pdf') {
        showPdfPage(page);
    }

    const media = document.getElementById('stage-video') || document.getElementById('stage-audio');
    if (media) syncMediaElement(media);
}

let pdfDocUrl = null;

/** Browser-native PDF viewer — correct fonts, rotation, and RTL text. */
function pdfViewerSrc(url, page) {
    const pageNum = Math.max(1, Number(page) || 1);
    // Nameddest-free hash Chrome/Edge/Firefox PDF plugins understand.
    return `${url}#page=${pageNum}&zoom=page-width`;
}

function mountPdfViewer(url, page) {
    pdfDocUrl = url;
    const pageNum = Math.max(1, Number(page) || 1);
    el.stage.innerHTML = `
        <div class="relative h-[70vh] overflow-hidden rounded-lg bg-white" id="stage-pdf-wrap" dir="ltr">
            <object id="stage-pdf" type="application/pdf"
                    data="${pdfViewerSrc(url, pageNum)}"
                    data-page="${pageNum}"
                    class="h-full w-full"
                    style="min-height:70vh;">
                <iframe id="stage-pdf-iframe" class="h-[70vh] w-full border-0"
                        src="${pdfViewerSrc(url, pageNum)}"
                        title="PDF"></iframe>
            </object>
        </div>`;
}

function showPdfPage(pageNum) {
    if (!pdfDocUrl) return;
    const page = Math.max(1, Number(pageNum) || 1);
    const src = pdfViewerSrc(pdfDocUrl, page);
    const object = document.getElementById('stage-pdf');
    const iframe = document.getElementById('stage-pdf-iframe');

    if (object) {
        if (object.dataset.page === String(page)) return;
        object.dataset.page = String(page);
        // Replacing data forces the plugin to jump page (hash-only often ignored).
        object.data = src;
        return;
    }

    if (iframe) {
        if (iframe.dataset.page === String(page)) return;
        iframe.dataset.page = String(page);
        iframe.src = src;
    }
}

function wireSyncedMedia(media) {
    if (!media || media.dataset.syncWired === '1') return;
    media.dataset.syncWired = '1';

    // Throttle (not debounce): debounce never fired while timeupdate kept resetting.
    const blastClock = () => {
        if (!state.isCoach || state.applyingMedia) return;
        if (!state.stage?.media?.playing) return;

        const positionMs = Math.floor((media.currentTime || 0) * 1000);
        const stamp = new Date().toISOString();
        state.stage.media = {
            ...(state.stage.media ?? {}),
            playing: true,
            position_ms: positionMs,
            updated_at: stamp,
        };

        const now = Date.now();
        if (!wireSyncedMedia._lastBlast || now - wireSyncedMedia._lastBlast >= 80) {
            wireSyncedMedia._lastBlast = now;
            publishStageSignal({
                t: 'media',
                kind: 'tick',
                playing: true,
                position_ms: positionMs,
                updated_at: stamp,
            }, { reliable: false });
        }

        clearTimeout(mediaPersistTimer);
        mediaPersistTimer = setTimeout(() => {
            // Re-check: a pause after this timer was armed must not resurrect "playing".
            if (!state.stage?.media?.playing) return;
            api('/room/stage', {
                method: 'POST',
                body: { media: { playing: true, position_ms: positionMs } },
            }).catch(() => {});
        }, 2500);
    };

    media.addEventListener('timeupdate', blastClock);
    // Extra high-rate path while playing — timeupdate alone is too coarse (~250ms).
    media.addEventListener('play', () => {
        clearInterval(wireSyncedMedia._raf);
        if (!state.isCoach || !state.stage?.media?.playing) return;
        wireSyncedMedia._raf = setInterval(blastClock, 100);
    });
    media.addEventListener('pause', () => {
        clearInterval(wireSyncedMedia._raf);
        clearTimeout(mediaPersistTimer);
    });
    media.addEventListener('ended', () => {
        clearInterval(wireSyncedMedia._raf);
        clearTimeout(mediaPersistTimer);
    });
    syncMediaElement(media);
}

function syncMediaElement(media) {
    const snap = state.stage?.media ?? {};
    state.applyingMedia = true;
    try {
        let targetMs = snap.position_ms ?? 0;
        // Extrapolate from the stamp so a late packet still lands on time.
        if (snap.playing && snap.updated_at) {
            const stamped = Date.parse(snap.updated_at);
            if (Number.isFinite(stamped)) {
                targetMs += Math.max(0, Date.now() - stamped);
            }
        }
        const target = targetMs / 1000;
        // While paused, only correct real scrubs — micro-seeks look like jumping.
        const slack = snap.playing ? 0.15 : 0.6;
        if (Number.isFinite(target) && Math.abs((media.currentTime || 0) - target) > slack) {
            media.currentTime = target;
        }
        if (snap.playing) {
            media.play?.().catch(() => {});
        } else {
            media.pause?.();
        }
    } finally {
        setTimeout(() => { state.applyingMedia = false }, 120);
    }
}

async function pushStage(body) {
    // Apply locally + blast over LiveKit first so learners move with the coach,
    // then persist for late joiners without blocking the UI.
    if (body.page != null) {
        state.stage = { ...state.stage, page: body.page };
        publishStageSignal({ t: 'page', page: body.page }, { reliable: true });
    }
    if (body.mode) {
        state.stage = { ...state.stage, mode: body.mode };
        publishStageSignal({ t: 'mode', mode: body.mode }, { reliable: true });
    }
    if (body.media) {
        const stamp = new Date().toISOString();
        // Pause/stop must cancel in-flight tick persists or they revive "playing".
        if (!body.media.playing) {
            clearTimeout(mediaPersistTimer);
            clearInterval(wireSyncedMedia._raf);
        }
        state.stage = {
            ...state.stage,
            media: {
                ...(state.stage.media ?? {}),
                ...body.media,
                updated_at: stamp,
            },
        };
        publishStageSignal({
            t: 'media',
            kind: 'control',
            playing: !!body.media.playing,
            position_ms: body.media.position_ms ?? 0,
            updated_at: stamp,
        }, { reliable: true });
    }

    const shared = state.materials.find((m) => m.id === state.sharedMaterialId);
    if (shared) applyPresentationToMounted(shared);
    else if (state.stage?.mode === 'whiteboard') redrawWhiteboard();
    renderStageToolbar(
        state.stage?.mode === 'whiteboard'
            ? 'whiteboard'
            : shared?.kind,
    );

    // Persist in the background — awaiting HTTP here is what made mode/share feel stuck.
    api('/room/stage', { method: 'POST', body }).then((data) => {
        if (data?.stage) state.stage = data.stage;
    }).catch(() => {});
}

/** Instant coach→learners path on the same SFU as faces/voices. */
function publishStageSignal(msg, { reliable = true } = {}) {
    if (!room || room.state !== ConnectionState.Connected) return;
    if (!state.isCoach) return;
    try {
        const payload = stageEncoder.encode(JSON.stringify({ v: 1, ts: Date.now(), ...msg }));
        room.localParticipant.publishData(payload, { reliable, topic: STAGE_TOPIC });
    } catch (error) {
        console.warn('stage signal failed', error);
    }
}

function onStageSignal(msg, fromSelf) {
    if (!msg || fromSelf) return;
    if (msg.t === 'page' && msg.page != null) {
        state.stage = { ...state.stage, page: Number(msg.page) || 1 };
        const shared = state.materials.find((m) => m.id === state.sharedMaterialId);
        if (shared) applyPresentationToMounted(shared);
        return;
    }
    if (msg.t === 'mode' && msg.mode) {
        state.stage = { ...state.stage, mode: msg.mode };
        renderStage();
        return;
    }
    if (msg.t === 'media') {
        const kind = msg.kind || 'control';
        // Late unreliable ticks after a pause must not restart playback.
        if (kind === 'tick' && !state.stage?.media?.playing && msg.playing) {
            return;
        }
        if (msg.ts != null && state._mediaSignalTs != null && msg.ts < state._mediaSignalTs) {
            return;
        }
        if (msg.ts != null) state._mediaSignalTs = msg.ts;
        state.stage = {
            ...state.stage,
            media: {
                playing: !!msg.playing,
                position_ms: Number(msg.position_ms) || 0,
                updated_at: msg.updated_at ?? new Date().toISOString(),
            },
        };
        const media = document.getElementById('stage-video') || document.getElementById('stage-audio');
        if (media) syncMediaElement(media);
        renderStageToolbar(
            state.materials.find((m) => m.id === state.sharedMaterialId)?.kind,
        );
        return;
    }
    if (msg.t === 'wb.draft') {
        draftStroke = msg.stroke ?? null;
        redrawWhiteboard();
        return;
    }
    if (msg.t === 'wb.stroke' && msg.stroke) {
        draftStroke = null;
        const strokes = [...(state.stage.whiteboard?.strokes ?? [])];
        strokes.push(msg.stroke);
        state.stage = {
            ...state.stage,
            mode: 'whiteboard',
            whiteboard: { strokes },
        };
        if (el.stage.dataset.stageKey !== 'whiteboard') {
            renderStage();
        } else {
            redrawWhiteboard();
        }
        return;
    }
    if (msg.t === 'wb.clear') {
        draftStroke = null;
        state.stage = {
            ...state.stage,
            mode: 'whiteboard',
            whiteboard: { strokes: [] },
        };
        redrawWhiteboard();
    }
}

function mountWhiteboard() {
    el.stage.innerHTML = `
        <canvas id="wb-canvas" class="h-[60vh] w-full touch-none bg-white"
                style="cursor: crosshair;"></canvas>`;
    const canvas = document.getElementById('wb-canvas');
    const ctx = canvas.getContext('2d');
    const resize = () => {
        const rect = canvas.getBoundingClientRect();
        canvas.width = Math.max(320, Math.floor(rect.width));
        canvas.height = Math.max(240, Math.floor(rect.height));
        redrawWhiteboard();
    };
    resize();
    window.addEventListener('resize', resize);

    if (!state.isCoach) {
        canvas.style.cursor = 'default';
        return;
    }

    let drawing = false;
    let points = [];

    const pos = (event) => {
        const rect = canvas.getBoundingClientRect();
        const x = (('clientX' in event ? event.clientX : event.touches[0].clientX) - rect.left) / rect.width;
        const y = (('clientY' in event ? event.clientY : event.touches[0].clientY) - rect.top) / rect.height;
        return [Math.min(1, Math.max(0, x)), Math.min(1, Math.max(0, y))];
    };

    const start = (event) => {
        event.preventDefault();
        drawing = true;
        points = [pos(event)];
    };
    const move = (event) => {
        if (!drawing) return;
        event.preventDefault();
        points.push(pos(event));
        // Cap draft payload size so LiveKit data stays under packet limits.
        let draftPoints = points;
        if (draftPoints.length > 48) {
            const step = Math.ceil(draftPoints.length / 40);
            draftPoints = draftPoints.filter((_, i) => i % step === 0 || i === draftPoints.length - 1);
        }
        const stroke = {
            id: 'draft',
            color: document.getElementById('wb-color')?.value ?? '#111827',
            width: 3,
            points: draftPoints,
        };
        draftStroke = stroke;
        redrawWhiteboard();
        // Unreliable LiveKit data — every move, no debounce (debounce = lag).
        publishStageSignal({ t: 'wb.draft', stroke }, { reliable: false });
    };
    const end = async () => {
        if (!drawing) return;
        drawing = false;
        if (points.length < 2) {
            draftStroke = null;
            points = [];
            redrawWhiteboard();
            return;
        }
        const stroke = {
            id: `s${Date.now()}`,
            color: document.getElementById('wb-color')?.value ?? '#111827',
            width: 3,
            points,
        };
        draftStroke = null;
        state.stage = {
            ...state.stage,
            mode: 'whiteboard',
            whiteboard: {
                strokes: [...(state.stage.whiteboard?.strokes ?? []), stroke],
            },
        };
        redrawWhiteboard();
        // Instant to the room; API persistence in the background.
        publishStageSignal({ t: 'wb.stroke', stroke }, { reliable: true });
        api('/room/whiteboard', {
            method: 'POST',
            body: { action: 'stroke', stroke },
        }).then((data) => {
            if (data?.stage) state.stage = data.stage;
        }).catch(() => {});
        points = [];
    };

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
}

function redrawWhiteboard() {
    const canvas = document.getElementById('wb-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    for (const stroke of state.stage?.whiteboard?.strokes ?? []) {
        strokePath(ctx, canvas, stroke);
    }
    if (draftStroke) strokePath(ctx, canvas, draftStroke);
}

function strokePath(ctx, canvas, stroke) {
    const pts = stroke.points ?? [];
    if (pts.length < 2) return;
    ctx.beginPath();
    ctx.strokeStyle = stroke.color ?? '#111827';
    ctx.lineWidth = stroke.width ?? 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    pts.forEach(([x, y], index) => {
        const px = x * canvas.width;
        const py = y * canvas.height;
        if (index === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
    });
    ctx.stroke();
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
    if (connecting || leaving) return;
    connecting = true;

    try {
        note('در حال اتصال تصویری…');

        const joined = await api('/room/join', { method: 'POST' });

        state.participants = state.participants.filter((p) => p.id !== joined.participant.id);
        state.participants.push(joined.participant);
        renderRoster();

        if (!joined.room_available || joined.room.provider === 'null') {
            note('سرور تصویر پیکربندی نشده است؛ بقیهٔ کلاس کار می‌کند.');
            return;
        }

        if (room) {
            try {
                room.removeAllListeners();
                await room.disconnect();
            } catch {
                // replacing
            }
            room = null;
        }

        room = new Room({
            adaptiveStream: false,
            dynacast: false,
            disconnectOnPageLeave: true,
            videoCaptureDefaults: {
                resolution: VideoPresets.h540.resolution,
            },
        });

        room
            .on(RoomEvent.TrackSubscribed, (track, _publication, participant) => {
                attach(track, participant.identity);
            })
            .on(RoomEvent.TrackUnsubscribed, (track) => {
                track.detach().forEach((element) => element.remove());
                pruneTiles();
            })
            .on(RoomEvent.TrackMuted, (publication, participant) => {
                if (publication.kind === Track.Kind.Video) {
                    el.tiles.querySelector(`[data-identity="${participant.identity}"]`)
                        ?.querySelector('video')?.classList.add('opacity-40');
                }
            })
            .on(RoomEvent.TrackUnmuted, (publication, participant) => {
                if (publication.kind === Track.Kind.Video) {
                    el.tiles.querySelector(`[data-identity="${participant.identity}"]`)
                        ?.querySelector('video')?.classList.remove('opacity-40');
                }
            })
            .on(RoomEvent.ParticipantDisconnected, () => pruneTiles())
            .on(RoomEvent.LocalTrackPublished, (publication) => {
                if (publication.track) attach(publication.track, room.localParticipant.identity, true);
            })
            .on(RoomEvent.ConnectionStateChanged, (connectionState) => {
                if (leaving) return;
                if (connectionState === ConnectionState.Reconnecting) {
                    note('در حال اتصال دوباره…');
                }
                if (connectionState === ConnectionState.Connected) {
                    note('متصل.');
                    syncLocalPublishing().catch(() => {});
                }
            })
            .on(RoomEvent.Disconnected, () => {
                if (leaving || connecting) return;
                note('ارتباط تصویری قطع شد. برای وصل شدن دوباره دکمهٔ زیر را بزنید.', true);
                showMediaRetry();
            })
            .on(RoomEvent.DataReceived, (payload, participant, _kind, topic) => {
                if (topic && topic !== STAGE_TOPIC) return;
                try {
                    const msg = JSON.parse(stageDecoder.decode(payload));
                    const fromSelf = participant?.identity === room?.localParticipant?.identity;
                    onStageSignal(msg, fromSelf);
                } catch (error) {
                    console.warn('stage signal parse failed', error);
                }
            });

        await room.connect(joined.room.url, joined.room.token, {
            autoSubscribe: true,
            // Firefox + strict NATs need headroom beyond the SDK 15s default.
            peerConnectionTimeout: 45_000,
            websocketTimeout: 20_000,
        });

        // SDK-managed publish only. Opening then stopping preview tracks left
        // Firefox holding the camera lock so remotes stayed on a black tile.
        await syncLocalPublishing();
        hideMediaRetry();
        if (room.state === ConnectionState.Connected) {
            note('متصل.');
        }
    } catch (error) {
        console.warn('livekit connect failed', error);
        note(mediaErrorMessage(error), true);
        showMediaRetry();
    } finally {
        connecting = false;
    }
}

/** Map LiveKit / browser connect failures to a short Persian status line. */
function mediaErrorMessage(error) {
    const raw = String(error?.message ?? error ?? '');
    const lower = raw.toLowerCase();

    if (lower.includes('permission') || lower.includes('notallowed') || lower.includes('denied')) {
        return 'دسترسی دوربین یا میکروفون داده نشده است.';
    }
    if (lower.includes('timeout') || lower.includes('timed out')) {
        return 'اتصال تصویر زمان‌بر شد. صفحه را یک‌بار رفرش سخت کنید و دوباره وارد شوید.';
    }
    if (lower.includes('signal') || lower.includes('websocket') || lower.includes('server unreachable')) {
        return 'ارتباط با سرور تصویر برقرار نشد. چند ثانیه بعد دوباره تلاش کنید.';
    }
    if (lower.includes('pc connection') || lower.includes('peer')) {
        return 'ارتباط رسانه‌ای برقرار نشد (شبکه/فایروال).';
    }

    return raw || 'ارتباط تصویری برقرار نشد.';
}

async function reconnectMedia() {
    hideMediaRetry();
    await connectMedia();
}

function showMediaRetry() {
    if (!el.status || document.getElementById('media-retry')) return;

    const button = document.createElement('button');
    button.id = 'media-retry';
    button.type = 'button';
    button.className = 'btn-primary mt-2';
    button.textContent = 'اتصال دوباره تصویر';
    button.addEventListener('click', () => {
        button.disabled = true;
        reconnectMedia().finally(() => {
            button.disabled = false;
        });
    });
    el.status.insertAdjacentElement('afterend', button);
}

function hideMediaRetry() {
    document.getElementById('media-retry')?.remove();
}

async function syncLocalPublishing() {
    if (!room || room.state !== ConnectionState.Connected) return;

    const mine = state.participants.find((p) => p.user_id === state.me);
    if (!mine) return;

    const wantMic = !!(mine.can_publish_audio && el.micButton?.dataset.on === 'true');
    const wantCam = !!(mine.can_publish_video && el.camButton?.dataset.on === 'true');

    try {
        if (room.localParticipant.isMicrophoneEnabled !== wantMic) {
            await room.localParticipant.setMicrophoneEnabled(wantMic);
        }
    } catch (error) {
        console.warn('microphone toggle failed', error);
        note('میکروفون در دسترس نیست.', true);
        if (el.micButton) el.micButton.dataset.on = 'false';
    }

    try {
        if (room.localParticipant.isCameraEnabled !== wantCam) {
            await room.localParticipant.setCameraEnabled(wantCam, {
                resolution: VideoPresets.h540.resolution,
            });
        }
    } catch (error) {
        console.warn('camera toggle failed', error);
        note('دوربین روشن نشد؛ مجوز دوربین مرورگر را بررسی کنید.', true);
        if (el.camButton) el.camButton.dataset.on = 'false';
    }

    syncDeviceButtons();
}

function syncDeviceButtons() {
    if (!room) return;

    const mine = state.participants.find((p) => p.user_id === state.me);
    const micOn = room.localParticipant?.isMicrophoneEnabled ?? false;
    const camOn = room.localParticipant?.isCameraEnabled ?? false;

    if (el.micButton) {
        el.micButton.disabled = !(mine?.can_publish_audio);
        el.micButton.dataset.on = micOn ? 'true' : 'false';
        el.micButton.textContent = micOn ? '🎤 میکروفون باز' : '🔇 میکروفون بسته';
    }
    if (el.camButton) {
        el.camButton.disabled = !(mine?.can_publish_video);
        el.camButton.dataset.on = camOn ? 'true' : 'false';
        el.camButton.textContent = camOn ? '🎥 دوربین باز' : '📷 دوربین بسته';
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
        element.playsInline = true;
        element.setAttribute('playsinline', 'true');
        element.autoplay = true;
        // Local preview must be muted or the browser blocks autoplay → black tile.
        if (isLocal) {
            element.muted = true;
            element.style.transform = 'scaleX(-1)';
        }
        tile.querySelector('video')?.remove();
        tile.append(element);
        element.play?.().catch(() => {});
    } else {
        element.className = 'hidden';
        if (isLocal) element.muted = true;
        tile.append(element);
    }

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

// ----------------------------------------------------------- the recording

/*
 * A class that believes it is being recorded and is not is worse than one that
 * knows it is not, so the button says what is actually happening rather than
 * what was last clicked, and a failure stays on screen.
 */
function renderRecording() {
    const recording = state.recording;

    if (!el.recordButton || !recording || !state.isCoach) return;

    if (!recording.available) {
        el.recordButton.hidden = true;
        el.recordStatus.textContent =
            'ضبط کلاس روی این نصب فعال نیست.';
        return;
    }

    el.recordButton.hidden = false;

    const labels = {
        none: ['⏺ شروع ضبط', 'start'],
        failed: ['⏺ تلاش دوباره برای ضبط', 'start'],
        starting: ['در حال آغاز…', null],
        recording: ['⏹ توقف ضبط', 'stop'],
        processing: ['در حال آماده‌سازی…', null],
        ready: ['ضبط آماده است', null],
    };

    const [label, action] = labels[recording.status] ?? labels.none;

    el.recordButton.textContent = label;
    el.recordButton.disabled = action === null;
    el.recordButton.dataset.record = action ?? '';
    el.recordButton.classList.toggle('!text-red-700', recording.status === 'recording');

    el.recordStatus.textContent = recording.error
        ? `ضبط نشد: ${recording.error}`
        : recording.status === 'recording'
            ? 'این کلاس در حال ضبط است.'
            : recording.status === 'processing'
                ? 'کلاس ضبط شد؛ فایل در حال آماده‌سازی است.'
                : '';
}

// -------------------------------------------------------------- the socket

let refreshTimer = null;

function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        refresh().catch(() => {});
    }, 120);
}

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

        if (event.type === 'chat.message' && event.message) {
            if (!state.chat.some((m) => m.id === event.message.id)) {
                state.chat.push(event.message);
                renderChat();
            }
            return;
        }

        if ((event.type === 'stage.updated' || event.type === 'whiteboard.updated') && event.stage) {
            state.stage = event.stage;
            renderStage();
            // Do not full-refresh: that is what made stage follow feel late.
            return;
        }

        if (event.type === 'material.shared') {
            if (event.material_id != null) {
                state.sharedMaterialId = event.material_id;
            }
            if (event.stage) state.stage = event.stage;
            else state.stage = { ...(state.stage ?? {}), mode: 'material' };
            renderShelf();
            renderStage();
            return;
        }

        if (event.type === 'material.closed') {
            if (event.material_id === state.sharedMaterialId) {
                state.sharedMaterialId = null;
            }
            renderShelf();
            renderStage();
            return;
        }

        if (event.type === 'material.added' || event.type === 'material.removed') {
            scheduleRefresh();
            return;
        }

        // Debounce: a busy room fires many events; one refresh is enough.
        scheduleRefresh();
    });
}

// ------------------------------------------------------------- the controls

document.addEventListener('click', async (event) => {
    const target = event.target.closest('[data-media],[data-remove],[data-share-material],'
        + '[data-close-material],[data-delete-material],[data-close-question],[data-mute-all],[data-lock],[data-unlock],'
        + '[data-hand],[data-ask-exercise],[data-record],[data-page-delta],[data-media-play],[data-media-stop],'
        + '[data-stage-mode],[data-wb-clear]');

    if (!target) {
        if (event.target.closest('#enlarge-stage') || event.target.closest('[data-enlarge-source]')) {
            openLightbox();
        }
        return;
    }

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
            const id = Number(target.dataset.shareMaterial);
            // Paint immediately — waiting on HTTP made whiteboard→image/video feel broken.
            state.sharedMaterialId = id;
            state.stage = {
                ...(state.stage ?? {}),
                mode: 'material',
                page: 1,
                media: { playing: false, position_ms: 0, updated_at: new Date().toISOString() },
            };
            el.stage.dataset.stageKey = '';
            renderShelf();
            const painting = renderStage();
            api(`/room/materials/${id}/share`, { method: 'POST' }).catch(() => {
                note('نمایش محتوا ثبت نشد.', true);
            });
            await painting;
        } else if (target.dataset.closeMaterial) {
            const id = Number(target.dataset.closeMaterial);
            if (state.sharedMaterialId === id) state.sharedMaterialId = null;
            el.stage.dataset.stageKey = '';
            renderShelf();
            const painting = renderStage();
            api(`/room/materials/${id}/close`, { method: 'POST' }).catch(() => {});
            await painting;
        } else if (target.dataset.deleteMaterial) {
            if (!confirm('این محتوا از قفسه حذف شود؟')) return;
            await api(`/room/materials/${target.dataset.deleteMaterial}`, { method: 'DELETE' });
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
        } else if (target.dataset.record) {
            await api(
                target.dataset.record === 'stop' ? '/room/record/stop' : '/room/record',
                { method: 'POST' },
            );
        } else if (target.hasAttribute('data-hand')) {
            await api('/room/hand', { method: 'POST', body: { raised: true } });
        } else if (target.dataset.pageDelta) {
            const next = Math.max(1, (state.stage?.page ?? 1) + Number(target.dataset.pageDelta));
            pushStage({ page: next });
            await renderStage();
        } else if (target.hasAttribute('data-media-play')) {
            const media = document.getElementById('stage-video') || document.getElementById('stage-audio');
            const playing = !state.stage?.media?.playing;
            pushStage({
                media: {
                    playing,
                    position_ms: Math.floor((media?.currentTime || 0) * 1000),
                },
            });
            await renderStage();
        } else if (target.hasAttribute('data-media-stop')) {
            const media = document.getElementById('stage-video') || document.getElementById('stage-audio');
            if (media) media.currentTime = 0;
            pushStage({ media: { playing: false, position_ms: 0 } });
            await renderStage();
        } else if (target.dataset.stageMode) {
            pushStage({ mode: target.dataset.stageMode });
            await renderStage();
        } else if (target.hasAttribute('data-wb-clear')) {
            draftStroke = null;
            state.stage = {
                ...state.stage,
                mode: 'whiteboard',
                whiteboard: { strokes: [] },
            };
            redrawWhiteboard();
            publishStageSignal({ t: 'wb.clear' }, { reliable: true });
            api('/room/whiteboard', { method: 'POST', body: { action: 'clear' } })
                .then((data) => { if (data?.stage) state.stage = data.stage; })
                .catch(() => {});
        }

        scheduleRefresh();
    } catch {
        // api() has already said what went wrong.
    } finally {
        target.disabled = false;
    }
});

function openLightbox() {
    if (!el.lightbox || !el.lightboxBody) return;
    el.lightboxBody.innerHTML = el.stage.innerHTML;
    el.lightbox.showModal?.();
}

document.getElementById('close-lightbox')?.addEventListener('click', () => {
    el.lightbox?.close?.();
});

el.chatForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const body = el.chatInput?.value?.trim();
    if (!body) return;
    try {
        const message = await api('/room/chat', { method: 'POST', body: { body } });
        if (message && !state.chat.some((m) => m.id === message.id)) {
            state.chat.push(message);
            renderChat();
        }
        if (el.chatInput) el.chatInput.value = '';
    } catch {
        // noted
    }
});

el.shelfAdd?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    const kind = form.kind.value;
    const title = form.title.value.trim();
    if (!title) return;

    const payload = new FormData();
    payload.append('kind', kind);
    payload.append('title', title);
    if (kind === 'text' && form.body.value.trim()) {
        payload.append('body', form.body.value.trim());
    }
    if (form.file.files[0]) {
        payload.append('file', form.file.files[0]);
    }

    const submit = form.querySelector('button[type=submit]');
    const previous = submit?.textContent;
    const busy = document.getElementById('shelf-upload-busy');
    if (submit) {
        submit.disabled = true;
        submit.innerHTML = '<span class="inline-block animate-pulse">در حال آپلود…</span>';
    }
    if (busy) {
        busy.hidden = false;
        busy.setAttribute('aria-busy', 'true');
    }
    form.setAttribute('aria-busy', 'true');
    note('در حال بارگذاری محتوا…');

    try {
        await api('/room/materials', { method: 'POST', formData: payload });
        form.reset();
        note('به قفسه اضافه شد.');
        scheduleRefresh();
    } catch {
        // noted
    } finally {
        form.removeAttribute('aria-busy');
        if (busy) {
            busy.hidden = true;
            busy.removeAttribute('aria-busy');
        }
        if (submit) {
            submit.disabled = false;
            submit.textContent = previous || 'افزودن به قفسه';
        }
    }
});

el.shelfAdd?.kind?.addEventListener('change', () => {
    const body = el.shelfAdd.querySelector('[name=body]');
    const file = el.shelfAdd.querySelector('[name=file]');
    const isText = el.shelfAdd.kind.value === 'text';
    if (body) body.hidden = !isText;
    if (file) file.hidden = isText;
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
    scheduleRefresh();
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
        note('در حال باز کردن اتاق…');

        // Media first: do not wait on shelf/lock extras before the SFU dial.
        const mediaPromise = connectMedia();

        await refresh();
        listen();
        await mediaPromise;

        if (state.isCoach) {
            api('/room/lock-preview').then((preview) => {
                if (el.lock) {
                    el.lock.textContent =
                        `${preview.concept_count} مفهوم از این جلسه، برای ${preview.student_count} زبان‌آموز.`;
                }
            }).catch(() => {});

            api('/room/askable').then((askable) => {
                state.askable = askable;
                renderAskable();
            }).catch(() => {});
        }
    } catch (error) {
        note(error.message ?? 'اتاق باز نشد.', true);
    }
})();
