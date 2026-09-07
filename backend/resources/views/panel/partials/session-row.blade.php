@php
    $when = $session->starts_at?->timezone($session->group?->timezone ?? config('app.timezone'));
@endphp
<li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
    <div class="min-w-0">
        <p class="truncate text-sm font-medium">{{ $session->title ?: $session->group?->title }}</p>
        <p class="text-xs text-ink-400">
            <span class="tabular">{{ $when?->format('Y-m-d H:i') }}</span>
            @if ($session->group?->school) · {{ $session->group->school->name }} @endif
            · <span class="tabular">{{ $session->materials_count ?? 0 }}</span> محتوا
        </p>
    </div>
    <div class="flex items-center gap-2">
        <x-panel.status :status="$session->status" />
        <a class="btn-ghost" href="{{ route('panel.sessions.show', $session) }}">آماده‌سازی</a>
        @if ($session->isJoinable())
            <a class="btn-primary" href="{{ route('panel.sessions.room', $session) }}">اتاق</a>
        @endif
    </div>
</li>
