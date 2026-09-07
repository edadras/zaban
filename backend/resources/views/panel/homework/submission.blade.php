@extends('panel.layout')
@section('title', 'تصحیح مشق')
@section('heading', $submission->learner?->name)
@section('subheading', $assignment->title . ' · ' . $group->title)

@section('content')

    <div class="flex flex-wrap items-center gap-2">
        <a class="btn-ghost" href="{{ route('panel.homework.show', $assignment) }}">بازگشت به مشق</a>
        @if ($submission->is_late)
            <span class="chip bg-amber-100 text-amber-800">با تأخیر تحویل شد</span>
        @endif
        @if ($submission->isReturned())
            <span class="chip bg-emerald-100 text-emerald-800">برگردانده شده</span>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">

            <section class="card">
                <div class="card-head"><h2 class="card-title">کار زبان‌آموز</h2></div>
                <div class="space-y-4 p-5">
                    @if ($submission->body)
                        <p class="whitespace-pre-wrap text-sm leading-8">{{ $submission->body }}</p>
                    @endif

                    @include('panel.partials.attachments', ['attachments' => $submission->attachments])

                    @if ($submission->responses->isNotEmpty())
                        <ul class="divide-y divide-ink-100 rounded-lg border border-ink-200">
                            @foreach ($submission->responses as $response)
                                <li class="flex items-start justify-between gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm">{{ $response->item?->prompt }}</p>
                                        <p class="mt-1 text-xs text-ink-400">
                                            پاسخ:
                                            {{ $response->body
                                                ?: collect($response->selected_options ?? [])
                                                    ->map(fn ($i) => $response->item?->options[$i] ?? $i)
                                                    ->join('، ') }}
                                        </p>
                                    </div>
                                    @if ($response->is_correct !== null)
                                        <span class="chip {{ $response->is_correct ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700' }}">
                                            {{ $response->is_correct ? 'درست' : 'نادرست' }}
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! $submission->body && $submission->attachments->isEmpty() && $submission->responses->isEmpty())
                        <p class="text-center text-sm text-ink-400">چیزی تحویل داده نشده است.</p>
                    @endif
                </div>
            </section>

            @if ($submission->ai_feedback)
                <section class="card border-brand-200">
                    <div class="card-head">
                        <h2 class="card-title">نظر دستیار هوشمند</h2>
                        <span class="chip bg-amber-100 text-amber-800">پیشنهاد — نمرهٔ نهایی با شماست</span>
                    </div>
                    <div class="space-y-3 p-5 text-sm">
                        @if (! empty($submission->ai_feedback['summary']))
                            <p class="leading-7">{{ $submission->ai_feedback['summary'] }}</p>
                        @endif

                        @if (! empty($submission->ai_feedback['scores']))
                            <ul class="flex flex-wrap gap-2">
                                @foreach ($submission->ai_feedback['scores'] as $name => $value)
                                    <li class="chip bg-ink-100 text-ink-700 tabular">
                                        {{ $name }}: {{ $value }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @foreach (['strengths' => 'نقاط قوت', 'next_steps' => 'قدم بعدی'] as $key => $label)
                            @if (! empty($submission->ai_feedback[$key]))
                                <div>
                                    <p class="text-xs font-medium text-ink-600">{{ $label }}</p>
                                    <ul class="mt-1 list-inside list-disc space-y-1 text-ink-800">
                                        @foreach ($submission->ai_feedback[$key] as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach

                        @if (! empty($submission->ai_feedback['wrong_positions']))
                            <p class="text-ink-600">
                                سؤال‌های نادرست:
                                <span class="tabular">{{ implode('، ', $submission->ai_feedback['wrong_positions']) }}</span>
                            </p>
                        @endif

                        <p class="text-xs text-ink-400" dir="ltr">{{ $submission->ai_model }}</p>
                    </div>
                </section>
            @elseif ($submission->ai_error)
                <section class="card">
                    <p class="px-5 py-6 text-sm text-ink-600">
                        دستیار نتوانست این کار را تصحیح کند؛ تصحیح با شماست.
                        <span class="mt-1 block text-xs text-ink-400">{{ $submission->ai_error }}</span>
                    </p>
                </section>
            @endif
        </div>

        <section class="card h-fit">
            <div class="card-head"><h2 class="card-title">نمره و بازخورد</h2></div>
            <form method="POST" action="{{ route('panel.homework.mark', [$assignment, $submission]) }}"
                  class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="score">نمره از {{ $assignment->points }}</label>
                    <input class="field tabular" id="score" name="score" type="number" step="0.5"
                           min="0" max="{{ $assignment->points }}"
                           value="{{ old('score', $submission->score ?? ($submission->ai_score !== null ? round($submission->ai_score, 1) : null)) }}">
                    @if ($submission->ai_score !== null)
                        <p class="mt-1 text-xs text-ink-400">
                            پیشنهاد دستیار: <span class="tabular">{{ round($submission->ai_score, 1) }}</span>
                        </p>
                    @endif
                </div>
                <div>
                    <label class="label" for="feedback">بازخورد برای زبان‌آموز</label>
                    <textarea class="field" id="feedback" name="feedback" rows="6">{{ old('feedback', $submission->feedback) }}</textarea>
                </div>
                <button class="btn-primary w-full">ثبت و برگرداندن به زبان‌آموز</button>
                <p class="text-xs text-ink-400">
                    تا این دکمه زده نشود، زبان‌آموز هیچ نمره‌ای نمی‌بیند.
                </p>
            </form>
        </section>
    </div>

@endsection
