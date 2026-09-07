@extends('panel.layout')
@section('title', $assignment->title)
@section('heading', $assignment->title)
@section('subheading', $group->title)

@php
    $statusLabels = [
        'assigned' => 'شروع نکرده', 'draft' => 'در دست نوشتن', 'submitted' => 'تحویل داده',
        'marking' => 'در حال تصحیح', 'marked' => 'تصحیح‌شده — برنگشته', 'returned' => 'برگردانده شده',
    ];
    $statusClasses = [
        'assigned' => 'bg-ink-100 text-ink-600', 'draft' => 'bg-ink-100 text-ink-600',
        'submitted' => 'bg-amber-100 text-amber-800', 'marking' => 'bg-amber-100 text-amber-800',
        'marked' => 'bg-brand-100 text-brand-700', 'returned' => 'bg-emerald-100 text-emerald-800',
    ];
@endphp

@section('content')

    <div class="flex flex-wrap items-center gap-2">
        <a class="btn-ghost" href="{{ route('panel.homework.index', $group) }}">مشق‌ها</a>

        @if ($canSet && ! $assignment->isPublished())
            <form method="POST" action="{{ route('panel.homework.publish', $assignment) }}">
                @csrf
                <button class="btn-primary">گذاشتن برای کلاس</button>
            </form>
        @elseif ($assignment->isPublished())
            <span class="chip bg-emerald-100 text-emerald-800">برای کلاس گذاشته شده</span>
            @if ($results['awaiting_marking'] > 0 || $results['returned'] < $results['handed_in'])
                <form method="POST" action="{{ route('panel.homework.return-all', $assignment) }}"
                      data-confirm="همهٔ مشق‌های تصحیح‌شده به زبان‌آموزان برگردانده شوند؟">
                    @csrf
                    <button class="btn-ghost">برگرداندن همه</button>
                </form>
            @endif
        @endif
    </div>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-panel.stat label="ثبت‌شده برای" :value="$results['set_for']" />
        <x-panel.stat label="تحویل داده" :value="$results['handed_in']" />
        <x-panel.stat label="شروع نکرده" :value="$results['not_started']" />
        <x-panel.stat label="منتظر تصحیح" :value="$results['awaiting_marking']" />
        <x-panel.stat label="میانگین" :value="$results['average_score'] ?? '—'" />
    </section>

    @if ($assignment->brief)
        <section class="card">
            <div class="card-head"><h2 class="card-title">صورت مشق</h2></div>
            <p class="whitespace-pre-wrap px-5 py-4 text-sm leading-7">{{ $assignment->brief }}</p>
        </section>
    @endif

    {{-- ------------------------------------------------------- the questions --}}
    @if ($assignment->kind === 'exercises')
        <section class="card">
            <div class="card-head">
                <h2 class="card-title">سؤال‌ها</h2>
                <span class="chip bg-ink-100 text-ink-600 tabular">{{ $assignment->items->count() }}</span>
            </div>

            @if ($assignment->items->isEmpty())
                <p class="px-5 pt-5 text-sm text-ink-400">
                    هنوز سؤالی ندارد و تا سؤال نداشته باشد برای کلاس گذاشته نمی‌شود.
                </p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($assignment->items as $item)
                        <li class="flex items-start justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm"><span class="tabular">{{ $loop->iteration }}.</span> {{ $item->prompt }}</p>
                                @if ($item->options)
                                    <p class="mt-1 text-xs text-ink-400">
                                        {{ implode(' · ', $item->options) }}
                                        @if ($item->correct_options)
                                            — درست:
                                            <span class="tabular">
                                                {{ implode('، ', array_map(fn ($i) => $i + 1, $item->correct_options)) }}
                                            </span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                            @if ($canSet && ! $assignment->isPublished())
                                <form method="POST" data-confirm="این سؤال حذف شود؟"
                                      action="{{ route('panel.homework.items.destroy', [$assignment, $item]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn-danger">حذف</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canSet && ! $assignment->isPublished())
                <form method="POST" action="{{ route('panel.homework.items.store', $assignment) }}"
                      class="grid gap-3 border-t border-ink-100 p-5 sm:grid-cols-2">
                    @csrf
                    <div class="sm:col-span-2">
                        <label class="label" for="prompt">صورت سؤال</label>
                        <input class="field" id="prompt" name="prompt" placeholder="یا شناسهٔ تمرین سامانه را بدهید">
                    </div>
                    <div>
                        <label class="label" for="exercise_id">شناسهٔ تمرین سامانه</label>
                        <input class="field tabular" id="exercise_id" name="exercise_id" type="number"
                               placeholder="از جست‌وجوی زیر">
                    </div>
                    <div>
                        <label class="label" for="points">نمرهٔ این سؤال</label>
                        <input class="field tabular" id="points" name="points" type="number" min="1" value="1">
                    </div>
                    <div>
                        <label class="label" for="options_text">گزینه‌ها (هر خط یکی)</label>
                        <textarea class="field" id="options_text" name="options_text" rows="3"></textarea>
                    </div>
                    <div>
                        <label class="label" for="correct">شمارهٔ گزینه‌های درست</label>
                        <input class="field tabular" id="correct" name="correct" placeholder="مثلاً: 2">
                    </div>
                    <div class="sm:col-span-2">
                        <button class="btn-primary">افزودن سؤال</button>
                    </div>
                </form>

                <div class="border-t border-ink-100 p-5">
                    <form method="GET" class="flex gap-2">
                        <input class="field" name="lesson" value="{{ $lessonSearch }}"
                               placeholder="جست‌وجوی درس در سامانه">
                        <button class="btn-ghost shrink-0">بگرد</button>
                    </form>
                    @if ($lessonResults->isNotEmpty())
                        <ul class="mt-3 max-h-56 space-y-1 overflow-y-auto text-sm">
                            @foreach ($lessonResults as $lesson)
                                <li class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-ink-50">
                                    <span class="truncate">{{ $lesson->title }}</span>
                                    <span class="text-xs text-ink-400 tabular">درس {{ $lesson->id }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-ink-400">
                            برای گرفتن شناسهٔ تمرین‌های یک درس، از کنسول کلاس «پرسش از محتوای درس» را ببینید.
                        </p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- ------------------------------------------------------ who did what --}}
    <section class="card">
        <div class="card-head"><h2 class="card-title">زبان‌آموزان</h2></div>

        @if ($submissions->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-ink-400">
                تا وقتی مشق برای کلاس گذاشته نشود، فهرستی نیست.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-ink-50">
                        <tr>
                            <th class="th">زبان‌آموز</th>
                            <th class="th">وضعیت</th>
                            <th class="th">تحویل</th>
                            <th class="th">نمرهٔ دستیار</th>
                            <th class="th">نمرهٔ شما</th>
                            <th class="th"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($submissions as $submission)
                            <tr>
                                <td class="td font-medium">{{ $submission->learner?->name }}</td>
                                <td class="td">
                                    <span class="chip {{ $statusClasses[$submission->status] ?? '' }}">
                                        {{ $statusLabels[$submission->status] ?? $submission->status }}
                                    </span>
                                    @if ($submission->is_late)
                                        <span class="chip bg-amber-100 text-amber-800">با تأخیر</span>
                                    @endif
                                </td>
                                <td class="td tabular whitespace-nowrap">
                                    {{ $submission->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="td tabular">
                                    {{ $submission->ai_score !== null ? round($submission->ai_score, 1) : '—' }}
                                    @if ($submission->ai_error)
                                        <span class="block text-xs text-ink-400">تصحیح خودکار نشد</span>
                                    @endif
                                </td>
                                <td class="td tabular">
                                    {{ $submission->score !== null ? round($submission->score, 1) : '—' }}
                                </td>
                                <td class="td text-left">
                                    @if ($submission->isHandedIn())
                                        <a class="btn-ghost"
                                           href="{{ route('panel.homework.submission', [$assignment, $submission]) }}">
                                            دیدن و تصحیح
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

@endsection
