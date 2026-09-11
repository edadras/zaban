@extends('panel.layout')
@section('title', 'آموزشگاه‌ها')
@section('heading', 'آموزشگاه‌ها')

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card {{ $isPlatformAdmin ? 'lg:col-span-3' : 'lg:col-span-2' }}">
            <div class="card-head"><h2 class="card-title">آموزشگاه‌هایی که مدیریت می‌کنید</h2></div>

            @if ($managed->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-400">
                    هنوز آموزشگاهی به شما سپرده نشده است.
                    @if ($isPlatformAdmin)
                        از بخش سامانه → آموزشگاه‌ها یک آموزشگاه و مدیرش را ثبت کنید.
                    @endif
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

        @unless ($isPlatformAdmin)
            <section class="card h-fit">
                <div class="card-head"><h2 class="card-title">آموزشگاه تازه</h2></div>
                <p class="px-5 py-6 text-sm text-ink-500">
                    ساخت آموزشگاه فقط توسط مدیر سامانه انجام می‌شود. پس از ثبت، مدیر آموزشگاه از همین پنل افراد و مربیان را مدیریت می‌کند.
                </p>
            </section>
        @endunless

    </div>

@endsection
