@extends('panel.layout')
@section('title', 'آماده‌سازی جلسه')
@section('heading', $session->title ?: $session->group?->title)
@section('subheading', $session->group?->school?->name . ' · ' . $session->starts_at?->format('Y-m-d H:i'))

@php
    $kindLabels = [
        'video' => 'ویدیو', 'pdf' => 'پی‌دی‌اف', 'image' => 'تصویر', 'audio' => 'صوت',
        'text' => 'متن', 'quiz' => 'آزمون', 'lesson' => 'درس سامانه', 'exercise' => 'تمرین سامانه',
    ];
@endphp

@section('content')

    <div class="flex flex-wrap items-center gap-2">
        <x-panel.status :status="$session->status" />

        @if ($session->status === \App\Models\ClassSession::SCHEDULED)
            <form method="POST" action="{{ route('panel.sessions.start', $session) }}">
                @csrf
                <button class="btn-primary">شروع کلاس و اطلاع به زبان‌آموزان</button>
            </form>
        @elseif ($session->status === \App\Models\ClassSession::LIVE)
            <a class="btn-primary" href="{{ route('panel.sessions.room', $session) }}">ورود به اتاق</a>
            <form method="POST" action="{{ route('panel.sessions.end', $session) }}" data-confirm="کلاس بسته شود؟">
                @csrf
                <button class="btn-danger">پایان کلاس</button>
            </form>
        @endif

        <a class="btn-ghost" href="{{ route('panel.sessions.attendance', $session) }}">حضور و غیاب</a>
        <a class="btn-ghost" href="{{ route('panel.classes.show', $session->group) }}">کلاس</a>

        @if ($session->status !== \App\Models\ClassSession::ENDED)
            <form method="POST" action="{{ route('panel.sessions.cancel', $session) }}"
                  data-confirm="این جلسه لغو شود؟" class="ms-auto">
                @csrf
                <button class="btn-danger">لغو جلسه</button>
            </form>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------- add a material --}}
        <section class="card h-fit lg:order-2">
            <div class="card-head"><h2 class="card-title">افزودن محتوا</h2></div>

            <form method="POST" action="{{ route('panel.sessions.materials.store', $session) }}"
                  enctype="multipart/form-data" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="label" for="kind">نوع</label>
                    <select class="field" id="kind" name="kind" data-material-kind>
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ $kindLabels[$kind] ?? $kind }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="m-title">عنوان</label>
                    <input class="field" id="m-title" name="title" required maxlength="200">
                </div>

                <div data-when-kind="video,pdf,image,audio">
                    <label class="label" for="file">فایل</label>
                    <input class="field" id="file" name="file" type="file">
                    <p class="mt-1 text-xs text-ink-400">تا ۲۰۰ مگابایت.</p>
                </div>

                <div data-when-kind="text,quiz">
                    <label class="label" for="body">متن</label>
                    <textarea class="field" id="body" name="body" rows="5"></textarea>
                </div>

                <div data-when-kind="lesson">
                    <label class="label" for="lesson_id">شناسهٔ درس</label>
                    <input class="field tabular" id="lesson_id" name="lesson_id" type="number"
                           placeholder="از جست‌وجوی زیر بردارید">
                </div>

                <div data-when-kind="exercise">
                    <label class="label" for="exercise_id">شناسهٔ تمرین</label>
                    <input class="field tabular" id="exercise_id" name="exercise_id" type="number">
                </div>

                <button class="btn-primary w-full">افزودن</button>
            </form>

            {{-- The corpus, so a coach can teach from what the app already has --}}
            <div class="border-t border-ink-100 p-5">
                <form method="GET" class="flex gap-2">
                    <input class="field" name="lesson" value="{{ $lessonSearch }}" placeholder="جست‌وجوی درس در سامانه">
                    <button class="btn-ghost shrink-0">بگرد</button>
                </form>

                @if ($lessonSearch !== '')
                    @if ($lessonResults->isEmpty())
                        <p class="mt-3 text-sm text-ink-400">درسی پیدا نشد.</p>
                    @else
                        <ul class="mt-3 max-h-64 space-y-1 overflow-y-auto text-sm">
                            @foreach ($lessonResults as $lesson)
                                <li class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-ink-50">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ $lesson->title }}</span>
                                        <span class="block truncate text-xs text-ink-400">
                                            {{ $lesson->unit?->module?->title }}
                                        </span>
                                    </span>
                                    <button type="button" class="btn-ghost shrink-0 tabular"
                                            data-pick-lesson="{{ $lesson->id }}"
                                            data-lesson-title="{{ $lesson->title }}">
                                        {{ $lesson->id }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        </section>

        <div class="space-y-6 lg:col-span-2">

            {{-- ---------------------------------------------------- the shelf --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">محتوای جلسه</h2>
                    <span class="chip bg-ink-100 text-ink-600 tabular">{{ $session->materials->count() }}</span>
                </div>

                @if ($session->materials->isEmpty())
                    <p class="px-5 py-10 text-center text-sm text-ink-400">
                        هنوز چیزی برای تدریس اضافه نشده است.
                    </p>
                @else
                    <ul class="divide-y divide-ink-100"
                        data-reorder="{{ route('panel.sessions.materials.reorder', $session) }}">
                        @foreach ($session->materials as $material)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3"
                                data-material-id="{{ $material->id }}">
                                <span class="chip bg-brand-50 text-brand-700">
                                    {{ $kindLabels[$material->kind] ?? $material->kind }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ $material->title }}</p>
                                    @if ($material->lesson)
                                        <p class="truncate text-xs text-ink-400">درس: {{ $material->lesson->title }}</p>
                                    @elseif ($material->media)
                                        <p class="truncate text-xs text-ink-400" dir="ltr">{{ $material->media->mime }}</p>
                                    @elseif ($material->body)
                                        <p class="truncate text-xs text-ink-400">{{ \Illuminate\Support\Str::limit($material->body, 90) }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" class="btn-ghost !px-2" data-move="up" aria-label="بالاتر">↑</button>
                                    <button type="button" class="btn-ghost !px-2" data-move="down" aria-label="پایین‌تر">↓</button>
                                    <form method="POST" data-confirm="این محتوا حذف شود؟"
                                          action="{{ route('panel.sessions.materials.destroy', [$session, $material]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn-danger">حذف</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ------------------------------------------------------- agenda --}}
            <section class="card">
                <div class="card-head"><h2 class="card-title">عنوان و طرح درس</h2></div>
                <form method="POST" action="{{ route('panel.sessions.update', $session) }}" class="space-y-4 p-5">
                    @csrf @method('PATCH')
                    <div>
                        <label class="label" for="s-title">عنوان</label>
                        <input class="field" id="s-title" name="title" value="{{ old('title', $session->title) }}"
                               placeholder="{{ $session->group?->title }}">
                    </div>
                    <div>
                        <label class="label" for="agenda">طرح درس</label>
                        <textarea class="field" id="agenda" name="agenda" rows="4">{{ old('agenda', $session->agenda) }}</textarea>
                    </div>
                    <button class="btn-primary">ذخیره</button>
                </form>
            </section>

            {{-- ------------------------------------------------------- summon --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">فراخواندن به کلاس</h2>
                </div>
                <form method="POST" action="{{ route('panel.sessions.summon', $session) }}" class="space-y-3 p-5">
                    @csrf
                    <p class="text-xs text-ink-400">
                        بدون انتخاب، به همهٔ زبان‌آموزان کلاس اطلاع می‌رود.
                    </p>
                    <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-ink-200 p-3">
                        @forelse ($session->group?->students ?? [] as $student)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="user_ids[]" value="{{ $student->id }}"
                                       class="rounded border-ink-200">
                                {{ $student->name }}
                            </label>
                        @empty
                            <p class="text-sm text-ink-400">کسی در این کلاس ثبت‌نام نکرده است.</p>
                        @endforelse
                    </div>
                    <button class="btn-ghost">فرستادن اعلان</button>
                </form>
            </section>

        </div>
    </div>

@endsection

@push('scripts')
<script>
    /* Show only the fields the chosen kind of material actually needs. */
    const kind = document.querySelector('[data-material-kind]');
    const groups = document.querySelectorAll('[data-when-kind]');

    function syncKind() {
        groups.forEach((group) => {
            group.hidden = !group.dataset.whenKind.split(',').includes(kind.value);
        });
    }

    kind?.addEventListener('change', syncKind);
    syncKind();

    /* Picking a lesson out of the corpus fills the form rather than a clipboard. */
    document.querySelectorAll('[data-pick-lesson]').forEach((button) => {
        button.addEventListener('click', () => {
            kind.value = 'lesson';
            syncKind();
            document.getElementById('lesson_id').value = button.dataset.pickLesson;
            const title = document.getElementById('m-title');
            if (!title.value) title.value = button.dataset.lessonTitle;
            title.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
</script>
@endpush
