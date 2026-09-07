@extends('panel.layout')
@section('title', $school->name)
@section('heading', $school->name)
@section('subheading', $isManager ? 'مدیر آموزشگاه' : 'مربی')

@section('content')

    <div class="flex flex-wrap gap-3">
        <x-panel.stat label="کلاس‌ها" :value="$groups->count()" />
        <x-panel.stat label="مربیان" :value="$coachCount" />
        <x-panel.stat label="زبان‌آموزان" :value="$studentCount" />
        @if ($isManager)
            <a class="btn-ghost self-center" href="{{ route('panel.schools.people', $school) }}">مدیریت افراد</a>
        @endif
    </div>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">کلاس‌ها</h2>
            <a class="text-xs text-brand-600 hover:underline" href="{{ route('panel.classes.index') }}">کلاس تازه</a>
        </div>

        @if ($groups->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-400">کلاسی ثبت نشده است.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-ink-50">
                        <tr>
                            <th class="th">کلاس</th>
                            <th class="th">مربی</th>
                            <th class="th">سطح</th>
                            <th class="th">زبان‌آموز</th>
                            <th class="th">جلسه</th>
                            <th class="th"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($groups as $group)
                            <tr>
                                <td class="td font-medium">{{ $group->title }}</td>
                                <td class="td">{{ $group->coach?->name ?? '—' }}</td>
                                <td class="td tabular">{{ $group->level?->code ?? '—' }}</td>
                                <td class="td tabular">{{ $group->students_count }}</td>
                                <td class="td tabular">{{ $group->sessions_count }}</td>
                                <td class="td text-left">
                                    <a class="btn-ghost" href="{{ route('panel.classes.show', $group) }}">باز کردن</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($isManager)
        <section class="card">
            <div class="card-head"><h2 class="card-title">مشخصات آموزشگاه</h2></div>
            <form method="POST" action="{{ route('panel.schools.update', $school) }}" class="grid gap-4 p-5 sm:grid-cols-2">
                @csrf @method('PATCH')
                <div>
                    <label class="label" for="s-name">نام</label>
                    <input class="field" id="s-name" name="name" value="{{ old('name', $school->name) }}" required>
                </div>
                <div>
                    <label class="label" for="s-tz">منطقهٔ زمانی</label>
                    <input class="field" id="s-tz" name="timezone" dir="ltr"
                           value="{{ old('timezone', $school->timezone) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="s-desc">توضیح</label>
                    <textarea class="field" id="s-desc" name="description" rows="3">{{ old('description', $school->description) }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <button class="btn-primary">ذخیره</button>
                </div>
            </form>
        </section>
    @endif

@endsection
