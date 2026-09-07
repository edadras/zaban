@php
    [$label, $classes] = match ($recording['status']) {
        'starting' => ['در حال آغاز ضبط', 'bg-amber-100 text-amber-800'],
        'recording' => ['در حال ضبط', 'bg-red-100 text-red-700'],
        'processing' => ['در حال آماده‌سازی فایل', 'bg-amber-100 text-amber-800'],
        'ready' => ['ضبط آماده است', 'bg-emerald-100 text-emerald-800'],
        'failed' => ['ضبط نشد', 'bg-red-100 text-red-700'],
        default => [null, ''],
    };
@endphp

@if ($label)
    <span class="chip {{ $classes }}">
        @if ($recording['status'] === 'recording')
            <span class="size-1.5 animate-pulse rounded-full bg-red-600"></span>
        @endif
        {{ $label }}
    </span>
@endif
