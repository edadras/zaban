@extends('panel.layout')
@section('title', $thread->title)
@section('heading', $thread->title)
@section('subheading', $group->title . ' · ' . $thread->author?->name)

@section('content')

    <div class="flex flex-wrap items-center gap-2">
        <a class="btn-ghost" href="{{ route('panel.forum.index', $group) }}">تابلو</a>

        @if ($canModerate)
            <form method="POST" action="{{ route('panel.forum.pin', $thread) }}">
                @csrf
                <button class="btn-ghost">{{ $thread->pinned_at ? 'برداشتن سنجاق' : 'سنجاق کردن' }}</button>
            </form>
            <form method="POST" action="{{ route('panel.forum.lock', $thread) }}">
                @csrf
                <button class="btn-ghost">{{ $thread->isLocked() ? 'باز کردن' : 'بستن گفت‌وگو' }}</button>
            </form>

            @if ($thread->isHidden())
                <form method="POST" action="{{ route('panel.forum.restore', $thread) }}">
                    @csrf
                    <button class="btn-ghost">برگرداندن برای کلاس</button>
                </form>
            @else
                <form method="POST" action="{{ route('panel.forum.hide', $thread) }}"
                      data-confirm="این نوشته از دید کلاس برداشته شود؟" class="flex gap-2">
                    @csrf
                    <input class="field max-w-48" name="reason" placeholder="دلیل (اختیاری)">
                    <button class="btn-danger">برداشتن</button>
                </form>
            @endif
        @endif
    </div>

    @if ($thread->isHidden())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            این نوشته از دید کلاس برداشته شده است و فقط شما آن را می‌بینید.
            @if ($thread->hidden_reason) دلیل: {{ $thread->hidden_reason }} @endif
        </div>
    @endif

    <section class="card">
        <div class="p-5">
            <p class="whitespace-pre-wrap text-sm leading-7">{{ $thread->body }}</p>
            @include('panel.partials.attachments', ['attachments' => $thread->attachments])
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">پاسخ‌ها</h2>
            <span class="chip bg-ink-100 text-ink-600 tabular">{{ $replies->count() }}</span>
        </div>

        @if ($replies->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-400">هنوز کسی پاسخ نداده است.</p>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($replies as $reply)
                    <li @class([
                        'px-5 py-4',
                        'bg-emerald-50/50' => $thread->accepted_reply_id === $reply->id,
                        'bg-ink-50/60' => $reply->isHidden(),
                    ])>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">{{ $reply->author?->name }}</span>

                            @if ($reply->is_coach_answer)
                                <span class="chip bg-brand-100 text-brand-700">مربی</span>
                            @endif

                            @if ($reply->is_ai_answer)
                                <span class="chip {{ $reply->ai_endorsed ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $reply->ai_endorsed ? 'دستیار هوشمند — تأیید مربی' : 'دستیار هوشمند — تأیید نشده' }}
                                </span>
                            @endif

                            @if ($thread->accepted_reply_id === $reply->id)
                                <span class="chip bg-emerald-100 text-emerald-800">پاسخ درست</span>
                            @endif

                            @if ($reply->helpful_count > 0)
                                <span class="chip bg-ink-100 text-ink-600 tabular">
                                    {{ $reply->helpful_count }} نفر مفید دانستند
                                </span>
                            @endif

                            <span class="ms-auto text-xs text-ink-400 tabular">
                                {{ $reply->created_at?->format('Y-m-d H:i') }}
                            </span>
                        </div>

                        <p class="mt-2 whitespace-pre-wrap text-sm leading-7">{{ $reply->body }}</p>
                        @include('panel.partials.attachments', ['attachments' => $reply->attachments])

                        @if ($reply->isHidden())
                            <p class="mt-2 text-xs text-red-700">
                                برداشته شده. {{ $reply->hidden_reason }}
                            </p>
                        @endif

                        @if ($canModerate)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($thread->accepted_reply_id !== $reply->id)
                                    <form method="POST" action="{{ route('panel.forum.accept', [$thread, $reply]) }}">
                                        @csrf
                                        <button class="btn-ghost">علامت‌زدن به‌عنوان پاسخ درست</button>
                                    </form>
                                @endif

                                @if ($reply->is_ai_answer)
                                    <form method="POST" action="{{ route('panel.forum.endorse', [$thread, $reply]) }}">
                                        @csrf
                                        <input type="hidden" name="endorsed" value="{{ $reply->ai_endorsed ? 0 : 1 }}">
                                        <button class="btn-ghost">
                                            {{ $reply->ai_endorsed ? 'پس‌گرفتن تأیید' : 'تأیید پاسخ دستیار' }}
                                        </button>
                                    </form>
                                @endif

                                @unless ($reply->isHidden())
                                    <form method="POST" data-confirm="این پاسخ برداشته شود؟"
                                          action="{{ route('panel.forum.reply.hide', [$thread, $reply]) }}">
                                        @csrf
                                        <button class="btn-danger">برداشتن</button>
                                    </form>
                                @endunless
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('panel.forum.reply', $thread) }}"
              enctype="multipart/form-data" class="space-y-3 border-t border-ink-100 p-5">
            @csrf
            <div>
                <label class="label" for="body">پاسخ شما</label>
                <textarea class="field" id="body" name="body" rows="4" required></textarea>
            </div>
            <div>
                <label class="label" for="files">تصویر یا ویدیو</label>
                <input class="field" id="files" name="files[]" type="file" multiple>
            </div>
            <button class="btn-primary">فرستادن پاسخ</button>
        </form>
    </section>

@endsection
