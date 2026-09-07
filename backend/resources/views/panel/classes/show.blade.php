@extends('panel.layout')
@section('title', $group->title)
@section('heading', $group->title)
@section('subheading', $group->school?->name . ' · مربی: ' . ($group->coach?->name ?? '—'))

@php
    $weekdays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
@endphp

@section('content')

    <div class="flex flex-wrap gap-2">
        <a class="btn-ghost" href="{{ route('panel.forum.index', $group) }}">تابلوی کلاس</a>
        <a class="btn-ghost" href="{{ route('panel.homework.index', $group) }}">مشق‌ها</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------- timetable --}}
        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">برنامهٔ هفتگی</h2></div>

            @if ($rules->isEmpty())
                <p class="px-5 pt-5 text-sm text-ink-400">
                    زمانی ثبت نشده است. با افزودن یک زمان، جلسه‌های هفته‌های آینده ساخته می‌شوند.
                </p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($rules as $rule)
                        <li class="flex items-center justify-between px-5 py-3">
                            <div>
                                <p class="text-sm font-medium">{{ $weekdays[$rule->weekday] ?? $rule->weekday }}</p>
                                <p class="text-xs text-ink-400 tabular">
                                    {{ \Illuminate\Support\Str::substr($rule->start_time, 0, 5) }}
                                    · {{ $rule->duration_minutes }} دقیقه
                                </p>
                            </div>
                            <form method="POST" data-confirm="این زمان و جلسه‌های آیندهٔ آن برداشته شود؟"
                                  action="{{ route('panel.classes.rules.destroy', [$group, $rule]) }}">
                                @csrf @method('DELETE')
                                <button class="btn-danger">برداشتن</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('panel.classes.rules.store', $group) }}"
                  class="space-y-3 border-t border-ink-100 p-5">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="weekday">روز</label>
                        <select class="field" id="weekday" name="weekday">
                            @foreach ($weekdays as $index => $name)
                                <option value="{{ $index }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="start_time">ساعت</label>
                        <input class="field tabular" id="start_time" name="start_time" type="time"
                               value="{{ old('start_time', '18:00') }}" required>
                    </div>
                    <div>
                        <label class="label" for="duration_minutes">مدت (دقیقه)</label>
                        <input class="field tabular" id="duration_minutes" name="duration_minutes" type="number"
                               min="10" max="480" value="{{ old('duration_minutes', 90) }}" required>
                    </div>
                    <div>
                        <label class="label" for="generate_weeks">برای چند هفته</label>
                        <input class="field tabular" id="generate_weeks" name="generate_weeks" type="number"
                               min="1" max="52" value="{{ old('generate_weeks', 8) }}">
                    </div>
                </div>
                <div>
                    <label class="label" for="starts_on">از تاریخ</label>
                    <input class="field tabular" id="starts_on" name="starts_on" type="date"
                           value="{{ old('starts_on', now()->toDateString()) }}" required>
                </div>
                <button class="btn-primary w-full">افزودن زمان</button>
            </form>
        </section>

        <div class="space-y-6 lg:col-span-2">

            {{-- ---------------------------------------------------- sessions --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">جلسه‌ها</h2>
                    <form method="POST" action="{{ route('panel.classes.generate', $group) }}">
                        @csrf
                        <input type="hidden" name="weeks" value="8">
                        <button class="btn-ghost">پر کردن تقویم ۸ هفته</button>
                    </form>
                </div>

                @if ($sessions->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-400">جلسه‌ای در تقویم نیست.</p>
                @else
                    <ul class="divide-y divide-ink-100">
                        @foreach ($sessions as $session)
                            @include('panel.partials.session-row', ['session' => $session])
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('panel.sessions.store') }}"
                      class="grid gap-3 border-t border-ink-100 p-5 sm:grid-cols-4">
                    @csrf
                    <input type="hidden" name="class_group_id" value="{{ $group->id }}">
                    <div class="sm:col-span-2">
                        <label class="label" for="ad-hoc-title">جلسهٔ فوق‌برنامه</label>
                        <input class="field" id="ad-hoc-title" name="title" placeholder="عنوان (اختیاری)">
                    </div>
                    <div>
                        <label class="label" for="ad-hoc-at">زمان</label>
                        <input class="field tabular" id="ad-hoc-at" name="starts_at" type="datetime-local" required>
                    </div>
                    <div>
                        <label class="label" for="ad-hoc-dur">مدت</label>
                        <div class="flex gap-2">
                            <input class="field tabular" id="ad-hoc-dur" name="duration_minutes" type="number"
                                   min="10" max="480" value="60" required>
                            <button class="btn-primary shrink-0">ساختن</button>
                        </div>
                    </div>
                </form>
            </section>

            {{-- -------------------------------------------------------- roll --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">زبان‌آموزان</h2>
                    <span class="chip bg-ink-100 text-ink-600 tabular">
                        {{ $group->students->count() }}/{{ $group->capacity }}
                    </span>
                </div>

                @if ($group->students->isEmpty())
                    <p class="px-5 pt-6 text-center text-sm text-ink-400">هنوز کسی ثبت‌نام نکرده است.</p>
                @else
                    <ul class="divide-y divide-ink-100">
                        @foreach ($group->students as $student)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $student->name }}</p>
                                    <p class="truncate text-xs text-ink-400" dir="ltr">{{ $student->email }}</p>
                                </div>
                                <form method="POST" data-confirm="از کلاس خارج شود؟"
                                      action="{{ route('panel.classes.withdraw', [$group, $student]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn-danger">خروج</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($candidates->isNotEmpty())
                    <form method="POST" action="{{ route('panel.classes.enrol', $group) }}"
                          class="space-y-3 border-t border-ink-100 p-5">
                        @csrf
                        <label class="label">افزودن از زبان‌آموزان آموزشگاه</label>
                        <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-ink-200 p-3">
                            @foreach ($candidates as $member)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="user_ids[]" value="{{ $member->user_id }}"
                                           class="rounded border-ink-200">
                                    <span>{{ $member->user?->name }}</span>
                                    <span class="text-xs text-ink-400" dir="ltr">{{ $member->user?->email }}</span>
                                </label>
                            @endforeach
                        </div>
                        <button class="btn-primary">ثبت‌نام</button>
                    </form>
                @endif
            </section>

            {{-- ----------------------------------------------------- details --}}
            <section class="card">
                <div class="card-head"><h2 class="card-title">مشخصات کلاس</h2></div>
                <form method="POST" action="{{ route('panel.classes.update', $group) }}"
                      class="grid gap-4 p-5 sm:grid-cols-2">
                    @csrf @method('PATCH')
                    <div class="sm:col-span-2">
                        <label class="label" for="c-title">عنوان</label>
                        <input class="field" id="c-title" name="title" value="{{ old('title', $group->title) }}" required>
                    </div>
                    @if ($isManager)
                        <div class="sm:col-span-2">
                            <label class="label" for="c-coach">مربی کلاس</label>
                            <select class="field" id="c-coach" name="coach_id">
                                @foreach ($coaches as $member)
                                    <option value="{{ $member->user_id }}"
                                            @selected($group->coach_id === $member->user_id)>
                                        {{ $member->display_name ?: $member->user?->name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-ink-400">
                                جلسه‌های آیندهٔ این کلاس هم به مربی تازه سپرده می‌شوند؛ جلسه‌های برگزارشده
                                به نام مربی قبلی می‌مانند.
                            </p>
                        </div>
                    @endif
                    <div>
                        <label class="label" for="c-level">سطح</label>
                        <select class="field" id="c-level" name="cefr_level_id">
                            <option value="">—</option>
                            @foreach ($levels as $level)
                                <option value="{{ $level->id }}" @selected($group->cefr_level_id === $level->id)>
                                    {{ $level->code }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="c-capacity">ظرفیت</label>
                        <input class="field tabular" id="c-capacity" name="capacity" type="number" min="1" max="200"
                               value="{{ old('capacity', $group->capacity) }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="c-desc">توضیح</label>
                        <textarea class="field" id="c-desc" name="description" rows="3">{{ old('description', $group->description) }}</textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-ink-200"
                               @checked($group->is_active)>
                        کلاس فعال است
                    </label>
                    <div class="flex gap-2 sm:col-span-2">
                        <button class="btn-primary">ذخیره</button>
                    </div>
                </form>
            </section>

        </div>
    </div>

@endsection
