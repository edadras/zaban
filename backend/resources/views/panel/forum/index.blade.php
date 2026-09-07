@extends('panel.layout')
@section('title', 'تابلوی کلاس')
@section('heading', 'تابلوی ' . $group->title)
@section('subheading', 'پرسش و پاسخ زبان‌آموزان این کلاس')

@php
    $kindLabels = ['question' => 'پرسش', 'discussion' => 'گفت‌وگو', 'announcement' => 'اطلاعیه'];
@endphp

@section('content')

    <div class="flex flex-wrap gap-2">
        <a class="btn-ghost" href="{{ route('panel.classes.show', $group) }}">کلاس</a>
        <a class="btn-ghost" href="{{ route('panel.homework.index', $group) }}">مشق‌ها</a>
        <a class="btn-ghost" href="{{ route('panel.forum.index', [$group, 'unresolved' => 1]) }}">بی‌پاسخ‌ها</a>
        @if ($canModerate)
            <a class="btn-ghost" href="{{ route('panel.forum.index', [$group, 'hidden' => 1]) }}">برداشته‌شده‌ها</a>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">نوشتن روی تابلو</h2></div>
            <form method="POST" action="{{ route('panel.forum.store', $group) }}"
                  enctype="multipart/form-data" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="kind">نوع</label>
                    <select class="field" id="kind" name="kind">
                        <option value="announcement">اطلاعیه به کلاس</option>
                        <option value="discussion">گفت‌وگو</option>
                        <option value="question">پرسش</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="title">عنوان</label>
                    <input class="field" id="title" name="title" required maxlength="200">
                </div>
                <div>
                    <label class="label" for="body">متن</label>
                    <textarea class="field" id="body" name="body" rows="4"></textarea>
                </div>
                <div>
                    <label class="label" for="files">تصویر یا ویدیو</label>
                    <input class="field" id="files" name="files[]" type="file" multiple>
                </div>
                <button class="btn-primary w-full">گذاشتن</button>
            </form>
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head">
                <h2 class="card-title">گفت‌وگوها</h2>
                <span class="chip bg-ink-100 text-ink-600 tabular">{{ $threads->count() }}</span>
            </div>

            @if ($threads->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-ink-400">
                    هنوز چیزی روی تابلو نیست.
                </p>
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($threads as $thread)
                        <li @class(['px-5 py-3.5', 'bg-ink-50/60' => $thread->isHidden()])>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a class="text-sm font-medium hover:underline"
                                       href="{{ route('panel.forum.show', $thread) }}">
                                        @if ($thread->pinned_at) 📌 @endif
                                        {{ $thread->title }}
                                    </a>
                                    <p class="mt-0.5 text-xs text-ink-400">
                                        {{ $thread->author?->name }} ·
                                        <span class="tabular">{{ $thread->created_at?->format('Y-m-d H:i') }}</span> ·
                                        <span class="tabular">{{ $thread->replies_count }}</span> پاسخ
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="chip bg-brand-50 text-brand-700">
                                        {{ $kindLabels[$thread->kind] ?? $thread->kind }}
                                    </span>
                                    @if ($thread->status === 'resolved')
                                        <span class="chip bg-emerald-100 text-emerald-800">پاسخ داده شد</span>
                                    @elseif ($thread->kind === 'question')
                                        <span class="chip bg-amber-100 text-amber-800">بی‌پاسخ</span>
                                    @endif
                                    @if ($thread->isLocked())
                                        <span class="chip bg-ink-100 text-ink-600">بسته</span>
                                    @endif
                                    @if ($thread->isHidden())
                                        <span class="chip bg-red-100 text-red-700">برداشته شده</span>
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
