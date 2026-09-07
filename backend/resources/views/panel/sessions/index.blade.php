@extends('panel.layout')
@section('title', 'جلسه‌های من')
@section('heading', 'جلسه‌های من')

@section('content')

    <section class="card">
        <div class="card-head"><h2 class="card-title">از یک هفتهٔ گذشته به بعد</h2></div>

        @if ($sessions->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-ink-400">
                جلسه‌ای نیست. از صفحهٔ کلاس یک برنامهٔ هفتگی بسازید.
            </p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($sessions as $session)
                    @include('panel.partials.session-row', ['session' => $session])
                @endforeach
            </ul>
        @endif
    </section>

@endsection
