@extends('panel.layout')
@section('title', 'کلاس‌ها')
@section('heading', 'کلاس‌ها')

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">همهٔ کلاس‌ها</h2></div>

            @if ($groups->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-400">هنوز کلاسی نساخته‌اید.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-ink-50">
                            <tr>
                                <th class="th">کلاس</th>
                                <th class="th">آموزشگاه</th>
                                <th class="th">مربی</th>
                                <th class="th">سطح</th>
                                <th class="th">زبان‌آموز</th>
                                <th class="th"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($groups as $group)
                                <tr @class(['opacity-60' => ! $group->is_active])>
                                    <td class="td font-medium">{{ $group->title }}</td>
                                    <td class="td">{{ $group->school?->name }}</td>
                                    <td class="td">{{ $group->coach?->name }}</td>
                                    <td class="td tabular">{{ $group->level?->code ?? '—' }}</td>
                                    <td class="td tabular">{{ $group->students_count }}/{{ $group->capacity }}</td>
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

        <section class="card h-fit">
            <div class="card-head"><h2 class="card-title">کلاس تازه</h2></div>

            @if ($schools->isEmpty())
                <p class="px-5 py-6 text-center text-sm text-ink-400">
                    ساختن کلاس از عهدهٔ مدیر آموزشگاه برمی‌آید.
                </p>
            @else
                <form method="POST" action="{{ route('panel.classes.store') }}" class="space-y-4 p-5">
                    @csrf
                    <div>
                        <label class="label" for="school_id">آموزشگاه</label>
                        <select class="field" id="school_id" name="school_id" required>
                            @foreach ($schools as $school)
                                <option value="{{ $school->id }}">{{ $school->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="title">عنوان</label>
                        <input class="field" id="title" name="title" value="{{ old('title') }}"
                               required placeholder="مثلاً: سه‌شنبه‌ها B1">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="cefr_level_id">سطح</label>
                            <select class="field" id="cefr_level_id" name="cefr_level_id">
                                <option value="">—</option>
                                @foreach ($levels as $level)
                                    <option value="{{ $level->id }}">{{ $level->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label" for="capacity">ظرفیت</label>
                            <input class="field tabular" id="capacity" name="capacity" type="number"
                                   min="1" max="200" value="{{ old('capacity', 20) }}">
                        </div>
                    </div>
                    <p class="text-xs text-ink-400">
                        مربی به‌طور پیش‌فرض خودتان هستید؛ از صفحهٔ کلاس می‌توانید عوضش کنید.
                    </p>
                    <button class="btn-primary w-full">ساختن</button>
                </form>
            @endif
        </section>

    </div>

@endsection
