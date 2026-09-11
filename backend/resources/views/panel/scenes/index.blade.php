@extends('panel.layout')
@section('title', 'صحنه‌ها')
@section('heading', 'صحنه‌های تمرین مکالمه')

@section('content')

    <p class="mb-4 text-sm text-ink-500">
        متن صحنه‌ها در مخزن پروژه نوشته می‌شود و اینجا ویرایش نمی‌شود. کاری که فقط با گوش دادن
        انجام می‌شود این است: صدای هر جمله را بشنوید و تأیید کنید.
    </p>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-ink-50">
                    <tr>
                        <th class="th">صحنه</th>
                        <th class="th">سطح</th>
                        <th class="th">محیط</th>
                        <th class="th">جمله‌ها</th>
                        <th class="th">صدادار</th>
                        <th class="th">تأییدشده</th>
                        <th class="th">برش از ضبط درس</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($scenes as $scene)
                        <tr>
                            <td class="td">
                                <span class="font-medium">{{ $scene->title_fa ?: $scene->title }}</span>
                                <span class="block text-xs text-ink-400" dir="ltr">{{ $scene->slug }}</span>
                            </td>
                            <td class="td tabular">{{ $scene->cefrLevel?->code ?? '—' }}</td>
                            <td class="td" dir="ltr">{{ $scene->environment }}</td>
                            <td class="td tabular">{{ $scene->beats_count }}</td>
                            <td class="td tabular">{{ $scene->voiced_count }}</td>
                            <td class="td tabular">{{ $scene->approved_count }}</td>
                            <td class="td tabular">{{ $scene->cut_count }}</td>
                            <td class="td text-left">
                                <a class="btn-ghost" href="{{ route('panel.scenes.show', $scene) }}">شنیدن</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="td py-10 text-center text-ink-400" colspan="8">هنوز صحنه‌ای ساخته نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

@endsection
