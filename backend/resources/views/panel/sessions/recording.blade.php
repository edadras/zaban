@extends('panel.layout')
@section('title', 'ضبط کلاس')
@section('heading', $session->title ?: $session->group?->title)
@section('subheading', 'بازپخش کلاس · ' . $session->starts_at?->format('Y-m-d H:i'))

@section('content')

    <div class="flex flex-wrap items-center gap-2">
        @include('panel.partials.recording-badge')
        <a class="btn-ghost" href="{{ route('panel.sessions.attendance', $session) }}">حضور و غیاب</a>
        <a class="btn-ghost" href="{{ route('panel.sessions.show', $session) }}">جلسه</a>
    </div>

    <section class="card overflow-hidden">
        @if ($playback)
            <video class="w-full bg-black" controls preload="metadata" src="{{ $playback['url'] }}"></video>
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-xs text-ink-400">
                <span>
                    مدت:
                    <span class="tabular">
                        {{ $recording['duration_ms'] ? intdiv($recording['duration_ms'], 60000) : '—' }}
                    </span>
                    دقیقه
                </span>
                <span>پیوند پخش تا یک ساعت معتبر است؛ با تازه‌کردن صفحه دوباره ساخته می‌شود.</span>
            </div>
        @elseif ($recording['status'] === 'failed')
            <p class="px-5 py-10 text-center text-sm text-red-700">
                ضبط این کلاس ناموفق بود.
                @if ($recording['error'])
                    <span class="mt-2 block text-xs text-ink-400" dir="ltr">{{ $recording['error'] }}</span>
                @endif
            </p>
        @elseif (in_array($recording['status'], ['recording', 'starting', 'processing'], true))
            <p class="px-5 py-10 text-center text-sm text-ink-400">
                هنوز آماده نیست. وقتی سرور رسانه فایل را بست، همین صفحه آن را نشان می‌دهد.
            </p>
        @else
            <p class="px-5 py-10 text-center text-sm text-ink-400">
                این کلاس ضبط نشده است.
            </p>
        @endif
    </section>

@endsection
