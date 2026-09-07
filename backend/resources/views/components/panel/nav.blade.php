@props(['href', 'active' => false])

<a href="{{ $href }}"
   @class([
       'flex items-center justify-between gap-2 rounded-lg px-3 py-2 transition',
       'bg-brand-50 font-medium text-brand-700' => $active,
       'text-ink-600 hover:bg-ink-50 hover:text-ink-900' => ! $active,
   ])>
    <span class="truncate">{{ $slot }}</span>
</a>
