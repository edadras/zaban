@extends('panel.layout')
@section('title', 'آموزشگاه‌ها')
@section('heading', 'آموزشگاه‌ها')

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">آموزشگاه‌هایی که مدیریت می‌کنید</h2></div>

            @if ($managed->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-400">
                    هنوز آموزشگاهی نساخته‌اید.
                </p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($managed as $school)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                            <div>
                                <a class="text-sm font-medium hover:underline"
                                   href="{{ route('panel.schools.show', $school) }}">{{ $school->name }}</a>
                                <p class="text-xs text-ink-400">
                                    <span class="tabular">{{ $school->class_groups_count }}</span> کلاس ·
                                    منطقهٔ زمانی {{ $school->timezone }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <a class="btn-ghost" href="{{ route('panel.schools.people', $school) }}">افراد</a>
                                <a class="btn-ghost" href="{{ route('panel.schools.show', $school) }}">کلاس‌ها</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($coaching->isNotEmpty())
                <div class="card-head border-t border-ink-100"><h2 class="card-title">جایی که تدریس می‌کنید</h2></div>
                <ul class="divide-y divide-ink-100">
                    @foreach ($coaching as $school)
                        <li class="px-5 py-3.5">
                            <a class="text-sm font-medium hover:underline"
                               href="{{ route('panel.schools.show', $school) }}">{{ $school->name }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card h-fit">
            <div class="card-head"><h2 class="card-title">آموزشگاه تازه</h2></div>
            <form method="POST" action="{{ route('panel.schools.store') }}" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="name">نام</label>
                    <input class="field" id="name" name="name" value="{{ old('name') }}" required maxlength="160">
                </div>
                <div>
                    <label class="label" for="timezone">منطقهٔ زمانی</label>
                    <input class="field" id="timezone" name="timezone" dir="ltr"
                           value="{{ old('timezone', 'Asia/Tehran') }}" maxlength="64">
                </div>
                <div>
                    <label class="label" for="description">توضیح</label>
                    <textarea class="field" id="description" name="description" rows="3">{{ old('description') }}</textarea>
                </div>
                <button class="btn-primary w-full">ساختن</button>
            </form>
        </section>

    </div>

@endsection
