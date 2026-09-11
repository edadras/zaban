<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <title>اتاق کلاس — {{ $session->title ?: $session->group?->title }}</title>
    @fonts
    @vite(['resources/css/panel.css'])
</head>
<body class="min-h-screen bg-ink-100 text-ink-900 antialiased" data-room-ui="stage-v2">

<header class="flex flex-wrap items-center gap-3 border-b border-ink-200 bg-white px-5 py-3">
    <div class="min-w-0 flex-1">
        <h1 class="truncate text-sm font-semibold">{{ $session->title ?: $session->group?->title }}</h1>
        <p class="truncate text-xs text-ink-400">
            {{ $session->group?->school?->name }} ·
            <span class="tabular">{{ $session->starts_at?->format('Y-m-d H:i') }}</span>
            · <span class="text-emerald-700">اتاق v2 · چت / وایت‌برد / همگام‌سازی</span>
        </p>
    </div>

    <button id="toggle-mic" class="btn-ghost" data-on="true">🎤 میکروفون</button>
    <button id="toggle-cam" class="btn-ghost" data-on="true">🎥 دوربین</button>
    <button class="btn-ghost" data-mute-all>ساکت کردن همه</button>
    <button id="record" class="btn-ghost" hidden></button>

    <form method="POST" action="{{ route('panel.sessions.end', $session) }}" data-confirm="کلاس بسته شود؟">
        @csrf
        <button class="btn-danger">پایان کلاس</button>
    </form>

    <a class="btn-ghost" href="{{ route('panel.sessions.show', $session) }}">خروج</a>
</header>

@unless ($mediaConfigured)
    <p class="border-b border-amber-200 bg-amber-50 px-5 py-2 text-sm text-amber-800">
        سرور تصویر (<span dir="ltr">{{ $provider }}</span>) پیکربندی نشده است؛ صدا و تصویر منتقل نمی‌شود،
        اما محتوا، پرسش‌ها، مدیریت میکروفون و قفل تمرین کار می‌کنند.
    </p>
@endunless

<main class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_22rem]">

    <div class="space-y-4">
        <div id="tiles" class="grid gap-3" style="grid-template-columns: repeat(1, minmax(0, 1fr));"></div>

        <section class="card overflow-hidden">
            <div class="card-head flex items-center justify-between gap-2">
                <h2 class="card-title">روی صفحه</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-ghost" data-stage-mode="material" id="mode-material">محتوا</button>
                    <button type="button" class="btn-ghost" data-stage-mode="whiteboard" id="mode-whiteboard">وایت‌برد</button>
                    <button type="button" class="btn-ghost" id="enlarge-stage" title="بزرگ‌نمایی">⛶</button>
                </div>
            </div>
            <div id="stage-toolbar" class="flex flex-wrap items-center gap-2 border-b border-ink-100 px-3 py-2" hidden></div>
            <div id="stage" class="relative min-h-40 cursor-zoom-in"></div>
        </section>

        <p id="room-status" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-600">
            در حال باز کردن اتاق…
        </p>
        <p id="record-status" class="text-xs text-ink-400"></p>
    </div>

    <aside class="space-y-4">

        <section class="card">
            <div class="card-head"><h2 class="card-title">حاضران</h2></div>
            <ul id="roster" class="divide-y divide-ink-100"></ul>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">چت کلاس</h2></div>
            <ul id="chat-log" class="max-h-56 space-y-2 overflow-y-auto px-3 py-2 text-sm"></ul>
            <form id="chat-form" class="flex gap-2 border-t border-ink-100 p-3">
                <input id="chat-input" class="field flex-1" maxlength="1000" placeholder="پیام به کلاس…" autocomplete="off">
                <button class="btn-primary" type="submit">ارسال</button>
            </form>
        </section>

        <section class="card" data-coach-only>
            <div class="card-head"><h2 class="card-title">محتوای جلسه</h2></div>
            <ul id="shelf" class="divide-y divide-ink-100"></ul>
            <form id="shelf-add" class="relative space-y-2 border-t border-ink-100 p-3">
                <div id="shelf-upload-busy" hidden
                     class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-b-xl bg-white/90 backdrop-blur-sm">
                    <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-ink-200 border-t-brand-600"></span>
                    <p class="text-sm font-medium text-ink-700">در حال آپلود محتوا…</p>
                    <p class="text-xs text-ink-400">لطفاً صبر کنید تا فایل روی سرور بنشیند</p>
                </div>
                <p class="text-xs text-ink-400">افزودن محتوا در همین کلاس</p>
                <input class="field" name="title" required maxlength="200" placeholder="عنوان">
                <select class="field" name="kind">
                    <option value="pdf">پی‌دی‌اف</option>
                    <option value="video">ویدیو</option>
                    <option value="image">تصویر</option>
                    <option value="audio">صوت</option>
                    <option value="text">متن</option>
                </select>
                <textarea class="field" name="body" rows="2" placeholder="متن (برای نوع متن)" hidden></textarea>
                <input class="field" name="file" type="file" accept=".pdf,video/*,image/*,audio/*">
                <button class="btn-primary w-full" type="submit">افزودن به قفسه</button>
            </form>
        </section>

        <section class="card" data-coach-only>
            <div class="card-head"><h2 class="card-title">پرسش از محتوای درس</h2></div>
            <ul id="askable" class="max-h-64 divide-y divide-ink-100 overflow-y-auto"></ul>
            <p class="border-t border-ink-100 px-4 py-2 text-xs text-ink-400">
                صورت پرسش، گزینه‌ها و پاسخ درست از خود سامانه برداشته می‌شوند و پاسخ‌ها خودکار تصحیح می‌شوند.
            </p>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">پرسش</h2></div>
            <div id="question"></div>

            <form id="ask" class="space-y-3 border-t border-ink-100 p-4">
                <div>
                    <label class="label" for="prompt">صورت پرسش</label>
                    <textarea class="field" id="prompt" name="prompt" rows="2" required></textarea>
                </div>
                <div>
                    <label class="label" for="options_text">گزینه‌ها (هر خط یک گزینه؛ خالی یعنی پاسخ تشریحی)</label>
                    <textarea class="field" id="options_text" name="options_text" rows="3"></textarea>
                </div>
                <div>
                    <label class="label" for="correct">شمارهٔ گزینه‌های درست</label>
                    <input class="field tabular" id="correct" name="correct" placeholder="مثلاً: 2">
                </div>
                <button class="btn-primary w-full">پرسیدن</button>
            </form>
        </section>

        <section class="card" data-coach-only>
            <div class="card-head"><h2 class="card-title">قفل تمرین امروز</h2></div>
            <div class="space-y-3 p-4">
                <p id="lock-preview" class="text-xs text-ink-400">…</p>
                <div>
                    <label class="label" for="lock-note">یادداشت برای زبان‌آموز</label>
                    <input class="field" id="lock-note" placeholder="امشب همین‌ها را مرور کنید.">
                </div>
                <div>
                    <label class="label" for="lock-hours">تا چند ساعت</label>
                    <input class="field tabular" id="lock-hours" type="number" min="1" max="168" value="24">
                </div>
                <div class="flex gap-2">
                    <button class="btn-primary flex-1" data-lock>قفل کردن</button>
                    <button class="btn-ghost" data-unlock>برداشتن</button>
                </div>
                <p class="text-xs text-ink-400">
                    تمرین روزانهٔ زبان‌آموزان در اپلیکیشن، تا پایان این مدت، از همین جلسه ساخته می‌شود.
                </p>
            </div>
        </section>

    </aside>
</main>

<dialog id="stage-lightbox" class="max-h-[95vh] w-[95vw] max-w-6xl rounded-2xl border border-ink-200 bg-white p-0 shadow-xl backdrop:bg-black/60">
    <div class="flex items-center justify-between border-b border-ink-100 px-4 py-2">
        <p class="text-sm font-medium">نمای بزرگ</p>
        <button type="button" class="btn-ghost" id="close-lightbox">بستن</button>
    </div>
    <div id="lightbox-body" class="max-h-[85vh] overflow-auto p-2"></div>
</dialog>

@php
    $bootstrap = [
        'sessionId' => $session->id,
        'userId' => auth()->id(),
        'token' => $apiToken,
        'reverb' => $reverb,
    ];
@endphp
<script>
    window.__ROOM__ = @json($bootstrap);
</script>
@vite(['resources/js/panel.js', 'resources/js/room.js'])

</body>
</html>
