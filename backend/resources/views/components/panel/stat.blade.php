@props(['label', 'value', 'hint' => null])

<div class="card p-4">
    <p class="text-xs text-ink-600">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-ink-400">{{ $hint }}</p>
    @endif
</div>
