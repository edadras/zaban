@extends('panel.layout')
@section('title', 'میز کار')
@section('heading', 'میز کار')

@section('content')

    @if ($live->isNotEmpty())
        <section class="card border-emerald-200 bg-emerald-50/40">
            <div class="card-head">
                <h2 class="card-title flex items-center gap-2">
                    <span class="size-2 animate-pulse rounded-full bg-emerald-500"></span>
                    هم‌اکنون در جریان
                </h2>
            </div>
            <ul class="divide-y divide-ink-100">
                @foreach ($live as $session)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                        <div>
                            <p class="text-sm font-medium">{{ $session->title ?: $session->group?->title }}</p>
                            <p class="text-xs text-ink-400">
                                {{ $session->group?->school?->name }} ·
                                <span class="tabular">{{ $session->participants_count }}</span> نفر در اتاق
                            </p>
                        </div>
                        <a class="btn-primary" href="{{ route('panel.sessions.room', $session) }}">ورود به کلاس</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-panel.stat label="کلاس‌ها" :value="$groupCount" />
        <x-panel.stat label="زبان‌آموزان" :value="$studentCount" />
        <x-panel.stat label="مربیان" :value="$coachCount" />
        <x-panel.stat label="آموزشگاه‌ها" :value="$managedSchools->count() + $coachingSchools->count()" />
    </section>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">کلاس‌های امروز</h2>
            <a class="text-xs text-brand-600 hover:underline" href="{{ route('panel.sessions.index') }}">همهٔ جلسه‌ها</a>
        </div>

        @if ($today->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-400">امروز کلاسی ندارید.</p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($today as $session)
                    @include('panel.partials.session-row', ['session' => $session])
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">جلسه‌های پیش رو</h2>
        </div>

        @if ($upcoming->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-400">
                جلسه‌ای در تقویم نیست. از صفحهٔ کلاس، یک زمان هفتگی بسازید.
            </p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($upcoming as $session)
                    @include('panel.partials.session-row', ['session' => $session])
                @endforeach
            </ul>
        @endif
    </section>

@endsection
