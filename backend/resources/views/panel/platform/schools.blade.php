@extends('panel.layout')
@section('title', 'آموزشگاه‌های سامانه')
@section('heading', 'آموزشگاه‌ها')
@section('subheading', 'ثبت آموزشگاه و مدیر آن فقط از اینجا انجام می‌شود')

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">همهٔ آموزشگاه‌ها</h2></div>

            @if ($schools->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-400">هنوز آموزشگاهی ثبت نشده است.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-ink-50">
                            <tr>
                                <th class="th">نام</th>
                                <th class="th">مدیر</th>
                                <th class="th">مربی</th>
                                <th class="th">زبان‌آموز</th>
                                <th class="th">کلاس</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($schools as $school)
                                <tr>
                                    <td class="td font-medium">{{ $school->name }}</td>
                                    <td class="td">
                                        <p>{{ $school->owner?->name ?? '—' }}</p>
                                        <p class="text-xs text-ink-400" dir="ltr">{{ $school->owner?->email }}</p>
                                    </td>
                                    <td class="td tabular">{{ $school->coach_count }}</td>
                                    <td class="td tabular">{{ $school->student_count }}</td>
                                    <td class="td tabular">{{ $school->class_groups_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card h-fit">
            <div class="card-head"><h2 class="card-title">ثبت آموزشگاه تازه</h2></div>
            @if ($canRegister)
            <form method="POST" action="{{ route('panel.platform.schools.store') }}" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="name">نام آموزشگاه</label>
                    <input class="field" id="name" name="name" value="{{ old('name') }}" required maxlength="160">
                </div>
                <div>
                    <label class="label" for="timezone">منطقهٔ زمانی</label>
                    <input class="field" id="timezone" name="timezone" dir="ltr"
                           value="{{ old('timezone', 'Asia/Tehran') }}" maxlength="64">
                </div>
                <div>
                    <label class="label" for="description">توضیح</label>
                    <textarea class="field" id="description" name="description" rows="2">{{ old('description') }}</textarea>
                </div>

                <div class="border-t border-ink-100 pt-4">
                    <p class="mb-3 text-xs font-medium text-ink-400">مدیر آموزشگاه</p>
                    <div class="space-y-4">
                        <div>
                            <label class="label" for="owner_name">نام</label>
                            <input class="field" id="owner_name" name="owner_name"
                                   value="{{ old('owner_name') }}" required maxlength="120">
                        </div>
                        <div>
                            <label class="label" for="owner_email">ایمیل</label>
                            <input class="field" id="owner_email" name="owner_email" type="email" dir="ltr"
                                   value="{{ old('owner_email') }}" required maxlength="190">
                            <p class="mt-1 text-xs text-ink-400">
                                اگر حساب نباشد ساخته می‌شود؛ اگر باشد همان مدیر آموزشگاه می‌شود.
                            </p>
                        </div>
                        <div>
                            <label class="label" for="owner_password">گذرواژه</label>
                            <input class="field" id="owner_password" name="owner_password" type="password" dir="ltr"
                                   autocomplete="new-password">
                        </div>
                        <div>
                            <label class="label" for="owner_password_confirmation">تکرار گذرواژه</label>
                            <input class="field" id="owner_password_confirmation" name="owner_password_confirmation"
                                   type="password" dir="ltr" autocomplete="new-password">
                            <p class="mt-1 text-xs text-ink-400">
                                برای حساب تازه الزامی است؛ برای حساب موجود فقط در صورت تغییر رمز.
                            </p>
                        </div>
                    </div>
                </div>

                <button class="btn-primary w-full">ثبت آموزشگاه و مدیر</button>
            </form>
            @else
                <p class="px-5 py-6 text-sm text-ink-500">فقط مدیر سامانه می‌تواند آموزشگاه تازه ثبت کند.</p>
            @endif
        </section>

    </div>

@endsection
