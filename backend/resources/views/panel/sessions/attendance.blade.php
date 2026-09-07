@extends('panel.layout')
@section('title', 'حضور و غیاب')
@section('heading', 'حضور و غیاب')
@section('subheading', ($session->title ?: $session->group?->title) . ' · ' . $session->starts_at?->format('Y-m-d H:i'))

@section('content')

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">چه کسی و چقدر در اتاق بود</h2>
            <div class="flex items-center gap-2">
                @include('panel.partials.recording-badge')
                @if ($recording['is_ready'])
                    <a class="btn-primary" href="{{ route('panel.sessions.recording', $session) }}">
                        تماشای ضبط کلاس
                    </a>
                @endif
                <a class="btn-ghost" href="{{ route('panel.sessions.show', $session) }}">بازگشت به جلسه</a>
            </div>
        </div>

        @if ($session->participants->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-ink-400">کسی وارد اتاق نشد.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-ink-50">
                        <tr>
                            <th class="th">نام</th>
                            <th class="th">نقش</th>
                            <th class="th">نخستین ورود</th>
                            <th class="th">مدت حضور</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($session->participants->sortByDesc('seconds_present') as $participant)
                            <tr>
                                <td class="td font-medium">{{ $participant->user?->name }}</td>
                                <td class="td">{{ $participant->role === 'coach' ? 'مربی' : 'زبان‌آموز' }}</td>
                                <td class="td tabular">
                                    {{ $participant->first_joined_at?->format('H:i') ?? '—' }}
                                </td>
                                <td class="td tabular">
                                    {{ intdiv($participant->seconds_present, 60) }} دقیقه
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

@endsection
