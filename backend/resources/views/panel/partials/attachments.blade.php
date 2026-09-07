@if ($attachments->isNotEmpty())
    <ul class="mt-3 flex flex-wrap gap-2">
        @foreach ($attachments as $attachment)
            <li class="chip bg-ink-100 text-ink-700">
                {{ match ($attachment->kind) {
                    'image' => '🖼',
                    'video' => '🎬',
                    'audio' => '🎧',
                    'pdf' => '📄',
                    default => '📎',
                } }}
                {{ $attachment->caption ?: $attachment->media?->mime }}
                <span class="text-ink-400 tabular">
                    {{ $attachment->media?->bytes ? round($attachment->media->bytes / 1024) . 'KB' : '' }}
                </span>
            </li>
        @endforeach
    </ul>
    <p class="mt-1 text-xs text-ink-400">
        فایل‌های پیوست در اپلیکیشن زبان‌آموزان باز می‌شوند.
    </p>
@endif
