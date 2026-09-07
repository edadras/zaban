@extends('panel.layout')
@section('title', 'گزارش تغییرات')
@section('heading', 'گزارش تغییرات')

@section('content')

    <section class="card">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-ink-50">
                    <tr>
                        <th class="th">زمان</th>
                        <th class="th">چه کسی</th>
                        <th class="th">کار</th>
                        <th class="th">روی چه</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="td tabular whitespace-nowrap">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="td">{{ $entry->user?->name ?? '—' }}</td>
                            <td class="td" dir="ltr">{{ $entry->action }}</td>
                            <td class="td tabular" dir="ltr">
                                {{ class_basename($entry->auditable_type) }}#{{ $entry->auditable_id }}
                            </td>
                        </tr>
                    @empty
                        <tr><td class="td py-10 text-center text-ink-400" colspan="4">چیزی ثبت نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $entries->links() }}

@endsection
