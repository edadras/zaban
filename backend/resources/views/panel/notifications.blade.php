@extends('panel.layout')
@section('title', 'اعلان‌ها')
@section('heading', 'اعلان‌ها')

@section('content')

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">تازه‌ترین‌ها</h2>
            <form method="POST" action="{{ route('panel.notifications.read-all') }}">
                @csrf
                <button class="btn-ghost">همه را خوانده‌شده کن</button>
            </form>
        </div>

        @if ($notifications->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-ink-400">اعلانی نیست.</p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($notifications as $notification)
                    <li @class(['px-5 py-3.5', 'bg-brand-50/50' => $notification->read_at === null])>
                        <p class="text-sm">
                            @if (($notification->data['kind'] ?? null) === 'class.live')
                                کلاس «{{ $notification->data['title'] ?? '' }}» شروع شد.
                            @elseif (($notification->data['kind'] ?? null) === 'class.starting')
                                کلاس «{{ $notification->data['title'] ?? '' }}» تا
                                <span class="tabular">{{ $notification->data['minutes_until'] ?? 0 }}</span>
                                دقیقهٔ دیگر آغاز می‌شود.
                            @else
                                {{ $notification->data['title'] ?? 'اعلان' }}
                            @endif
                        </p>
                        @if (! empty($notification->data['class_session_id']))
                            <a class="text-xs text-brand-600 hover:underline"
                               href="{{ route('panel.sessions.show', $notification->data['class_session_id']) }}">
                                رفتن به جلسه
                            </a>
                        @endif
                        <p class="mt-0.5 text-xs text-ink-400 tabular">
                            {{ $notification->created_at?->format('Y-m-d H:i') }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

@endsection
