@extends('panel.layout')
@section('title', $scene->title_fa ?: $scene->title)
@section('heading', $scene->title_fa ?: $scene->title)

@section('content')

    <p class="mb-4 text-sm text-ink-500">{{ $scene->situation_fa ?: $scene->situation }}</p>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-ink-50">
                    <tr>
                        <th class="th">#</th>
                        <th class="th">نقش</th>
                        <th class="th">جمله</th>
                        <th class="th">صدا</th>
                        <th class="th">منبع</th>
                        <th class="th">وضعیت</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($lines as $line)
                        @php($beat = $line['beat'])
                        <tr>
                            <td class="td tabular">{{ $beat->position }}</td>
                            <td class="td" dir="ltr">{{ $beat->role }}</td>
                            <td class="td">
                                <span dir="ltr" class="block">{{ $beat->text }}</span>
                                <span class="block text-xs text-ink-400">{{ $beat->translation_fa }}</span>
                                @if ($beat->interaction !== 'watch')
                                    <span class="mt-1 inline-block rounded bg-ink-100 px-2 py-0.5 text-xs">
                                        نوبت زبان‌آموز: {{ $beat->interaction }}
                                    </span>
                                @endif
                            </td>
                            <td class="td">
                                @if ($line['audio'])
                                    {{-- The window matters: a line cut out of a longer recording
                                         starts part-way in, and the player has to as well. --}}
                                    <audio controls preload="none"
                                        src="{{ $line['audio']['url'] }}{{ $beat->audio_start_ms !== null ? '#t='.($beat->audio_start_ms / 1000).','.(($beat->audio_end_ms ?? 0) / 1000) : '' }}">
                                    </audio>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="td text-xs" dir="ltr">
                                {{ $beat->audio_method ?? '—' }}
                                @if ($beat->audio_confidence !== null)
                                    <span class="block tabular text-ink-400">
                                        {{ number_format((float) $beat->audio_confidence, 2) }}
                                    </span>
                                @endif
                            </td>
                            <td class="td">
                                <form method="POST" action="{{ route('panel.scenes.review', [$scene, $beat]) }}"
                                    class="flex items-center gap-2">
                                    @csrf
                                    <select name="status" class="input py-1 text-sm">
                                        @foreach (['pending' => 'بررسی‌نشده', 'approved' => 'تأیید', 'rejected' => 'رد'] as $value => $label)
                                            <option value="{{ $value }}" @selected($beat->audio_review_status === $value)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn-ghost text-sm" type="submit">ثبت</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

@endsection
