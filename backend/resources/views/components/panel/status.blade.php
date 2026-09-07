@props(['status'])

@php
    [$label, $classes] = match ($status) {
        'live' => ['در جریان', 'bg-emerald-100 text-emerald-800'],
        'ended' => ['پایان‌یافته', 'bg-ink-100 text-ink-600'],
        'cancelled' => ['لغو شده', 'bg-red-100 text-red-700'],
        default => ['برنامه‌ریزی‌شده', 'bg-brand-100 text-brand-700'],
    };
@endphp

<span class="chip {{ $classes }}">{{ $label }}</span>
