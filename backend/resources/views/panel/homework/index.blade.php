@extends('panel.layout')
@section('title', 'مشق‌ها')
@section('heading', 'مشق‌های ' . $group->title)

@php
    $kindLabels = [
        'writing' => 'نوشتن', 'speaking' => 'گفتن', 'exercises' => 'تمرین سامانه',
        'upload' => 'بارگذاری فایل', 'practice' => 'تمرین روزانه', 'reading' => 'خواندن',
    ];
@endphp

@section('content')

    <div class="flex flex-wrap gap-2">
        <a class="btn-ghost" href="{{ route('panel.classes.show', $group) }}">کلاس</a>
        <a class="btn-ghost" href="{{ route('panel.forum.index', $group) }}">تابلوی کلاس</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">مشق تازه</h2></div>
            <form method="POST" action="{{ route('panel.homework.store', $group) }}" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="kind">نوع مشق</label>
                    <select class="field" id="kind" name="kind">
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ $kindLabels[$kind] ?? $kind }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-400">
                        نوشتن و گفتن و تمرین سامانه را دستیار هوشمند اول تصحیح می‌کند؛ نمرهٔ نهایی با شماست.
                    </p>
                </div>
                <div>
                    <label class="label" for="title">عنوان</label>
                    <input class="field" id="title" name="title" required maxlength="200">
                </div>
                <div>
                    <label class="label" for="brief">صورت مشق</label>
                    <textarea class="field" id="brief" name="brief" rows="4"
                              placeholder="مثلاً: حدود ۱۰۰ کلمه دربارهٔ تعطیلات، با زمان گذشته."></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="due_at">مهلت</label>
                        <input class="field tabular" id="due_at" name="due_at" type="datetime-local">
                    </div>
                    <div>
                        <label class="label" for="points">نمرهٔ کل</label>
                        <input class="field tabular" id="points" name="points" type="number"
                               min="1" max="1000" value="100">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="allow_late" value="1" class="rounded border-ink-200" checked>
                    تحویل با تأخیر پذیرفته شود
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="auto_release" value="1" class="rounded border-ink-200">
                    نمرهٔ خودکار بی‌درنگ به زبان‌آموز نشان داده شود
                </label>
                <p class="text-xs text-ink-400">
                    بدون این گزینه، نمره تا وقتی خودتان تأیید نکنید به زبان‌آموز نشان داده نمی‌شود.
                </p>
                <button class="btn-primary w-full">ساختن</button>
            </form>
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">همهٔ مشق‌ها</h2></div>

            @if ($assignments->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-ink-400">هنوز مشقی نداده‌اید.</p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($assignments as $assignment)
                        @php $r = $results[$assignment->id]; @endphp
                        <li class="px-5 py-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a class="text-sm font-medium hover:underline"
                                       href="{{ route('panel.homework.show', $assignment) }}">
                                        {{ $assignment->title }}
                                    </a>
                                    <p class="mt-0.5 text-xs text-ink-400">
                                        {{ $kindLabels[$assignment->kind] ?? $assignment->kind }}
                                        @if ($assignment->due_at)
                                            · مهلت
                                            <span class="tabular">{{ $assignment->due_at->format('Y-m-d H:i') }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if (! $assignment->isPublished())
                                        <span class="chip bg-ink-100 text-ink-600">پیش‌نویس</span>
                                    @else
                                        <span class="chip bg-brand-50 text-brand-700 tabular">
                                            {{ $r['handed_in'] }}/{{ $r['set_for'] }} تحویل
                                        </span>
                                        @if ($r['awaiting_marking'] > 0)
                                            <span class="chip bg-amber-100 text-amber-800 tabular">
                                                {{ $r['awaiting_marking'] }} منتظر تصحیح
                                            </span>
                                        @endif
                                        @if ($r['average_score'] !== null)
                                            <span class="chip bg-emerald-100 text-emerald-800 tabular">
                                                میانگین {{ $r['average_score'] }}
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

@endsection
