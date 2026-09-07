@extends('panel.layout')
@section('title', 'افراد آموزشگاه')
@section('heading', 'افراد ' . $school->name)

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">افزودن عضو</h2></div>
            <form method="POST" action="{{ route('panel.schools.people.store', $school) }}" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="email">ایمیل حساب</label>
                    <input class="field" id="email" name="email" type="email" dir="ltr"
                           value="{{ old('email') }}" required>
                    <p class="mt-1 text-xs text-ink-400">
                        حساب باید از پیش ساخته شده باشد؛ این صفحه برای کسی حساب نمی‌سازد.
                    </p>
                </div>
                <div>
                    <label class="label" for="role">نقش</label>
                    <select class="field" id="role" name="role">
                        <option value="coach">مربی</option>
                        <option value="student">زبان‌آموز</option>
                        <option value="admin">مدیر</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="display_name">نام نمایشی (اختیاری)</label>
                    <input class="field" id="display_name" name="display_name" value="{{ old('display_name') }}">
                </div>
                <button class="btn-primary w-full">افزودن</button>
            </form>
        </section>

        <div class="space-y-6 lg:col-span-2">

            <form method="GET" class="flex gap-2">
                <input class="field" name="q" value="{{ $search }}" placeholder="جست‌وجوی نام یا ایمیل">
                <button class="btn-ghost">جست‌وجو</button>
            </form>

            @foreach ([
                ['مدیران', $managers, false],
                ['مربیان', $coaches, true],
                ['زبان‌آموزان', $students, false],
            ] as [$title, $rows, $linkable])
                <section class="card">
                    <div class="card-head">
                        <h2 class="card-title">{{ $title }}</h2>
                        <span class="chip bg-ink-100 text-ink-600 tabular">{{ $rows->count() }}</span>
                    </div>

                    @if ($rows->isEmpty())
                        <p class="px-5 py-6 text-center text-sm text-ink-400">کسی در این دسته نیست.</p>
                    @else
                        <ul class="divide-y divide-ink-100">
                            @foreach ($rows as $member)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">
                                            {{ $member->display_name ?: $member->user?->name }}
                                        </p>
                                        <p class="truncate text-xs text-ink-400" dir="ltr">{{ $member->user?->email }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($linkable)
                                            <a class="btn-ghost"
                                               href="{{ route('panel.schools.coach', [$school, $member->user_id]) }}">
                                                زبان‌آموزانش
                                            </a>
                                        @endif
                                        @if ($member->role !== \App\Models\SchoolMember::OWNER)
                                            <form method="POST" data-confirm="حذف از آموزشگاه؟"
                                                  action="{{ route('panel.schools.people.destroy', [$school, $member]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn-danger">حذف</button>
                                            </form>
                                        @else
                                            <span class="chip bg-brand-100 text-brand-700">مالک</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

@endsection
