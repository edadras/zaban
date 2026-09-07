@extends('panel.layout')
@section('title', 'زبان‌آموزان مربی')
@section('heading', $coach->name)
@section('subheading', 'مربی در ' . $school->name)

@section('content')

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">سپردن زبان‌آموز</h2></div>

            @if ($available->isEmpty())
                <p class="px-5 py-6 text-center text-sm text-ink-400">
                    همهٔ زبان‌آموزان آموزشگاه به این مربی سپرده شده‌اند.
                </p>
            @else
                <form method="POST" action="{{ route('panel.schools.coach.assign', [$school, $coach]) }}"
                      class="space-y-4 p-5">
                    @csrf
                    <div>
                        <label class="label" for="student_id">زبان‌آموز</label>
                        <select class="field" id="student_id" name="student_id" required>
                            @foreach ($available as $member)
                                <option value="{{ $member->user_id }}">
                                    {{ $member->user?->name }} — {{ $member->user?->email }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="note">یادداشت</label>
                        <textarea class="field" id="note" name="note" rows="2"
                                  placeholder="مثلاً: برای آزمون بهمن آماده می‌شود."></textarea>
                    </div>
                    <button class="btn-primary w-full">سپردن</button>
                </form>
            @endif
        </section>

        <div class="space-y-6 lg:col-span-2">
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">زبان‌آموزان این مربی</h2>
                    <span class="chip bg-ink-100 text-ink-600 tabular">{{ $assigned->count() }}</span>
                </div>

                @if ($assigned->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-400">هنوز کسی به این مربی سپرده نشده است.</p>
                @else
                    <ul class="divide-y divide-ink-100">
                        @foreach ($assigned as $link)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $link->student?->name }}</p>
                                    <p class="truncate text-xs text-ink-400">
                                        {{ $link->note ?: $link->student?->email }}
                                    </p>
                                </div>
                                <form method="POST" data-confirm="پایان دادن به این ارتباط؟"
                                      action="{{ route('panel.schools.coach.unassign', [$school, $coach, $link->student_id]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn-danger">پایان</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card">
                <div class="card-head"><h2 class="card-title">کلاس‌های این مربی</h2></div>
                @if ($groups->isEmpty())
                    <p class="px-5 py-6 text-center text-sm text-ink-400">کلاسی ندارد.</p>
                @else
                    <ul class="divide-y divide-ink-100">
                        @foreach ($groups as $group)
                            <li class="flex items-center justify-between px-5 py-3">
                                <a class="text-sm font-medium hover:underline"
                                   href="{{ route('panel.classes.show', $group) }}">{{ $group->title }}</a>
                                <span class="text-xs text-ink-400 tabular">{{ $group->students_count }} نفر</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

@endsection
