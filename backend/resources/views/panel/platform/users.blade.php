@extends('panel.layout')
@section('title', 'کاربران')
@section('heading', 'کاربران')

@section('content')

    <form method="GET" class="flex flex-wrap gap-2">
        <input class="field max-w-xs" name="q" value="{{ request('q') }}" placeholder="نام یا ایمیل">
        <select class="field max-w-40" name="role" data-submit-on-change>
            <option value="">همهٔ نقش‌ها</option>
            @foreach (['learner' => 'زبان‌آموز', 'admin' => 'مدیر', 'editor' => 'ویراستار', 'reviewer' => 'بازبین'] as $value => $label)
                <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="field max-w-40" name="status" data-submit-on-change>
            <option value="">همهٔ وضعیت‌ها</option>
            <option value="active" @selected(request('status') === 'active')>فعال</option>
            <option value="suspended" @selected(request('status') === 'suspended')>معلق</option>
        </select>
        <button class="btn-ghost">جست‌وجو</button>
    </form>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-ink-50">
                    <tr>
                        <th class="th">نام</th>
                        <th class="th">ایمیل</th>
                        <th class="th">سطح</th>
                        <th class="th">نقش و وضعیت</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse ($users as $user)
                        <tr>
                            <td class="td font-medium">{{ $user->name }}</td>
                            <td class="td" dir="ltr">{{ $user->email }}</td>
                            <td class="td tabular">{{ $user->learnerProfile?->cefrLevel?->code ?? '—' }}</td>
                            <td class="td">
                                <form method="POST" action="{{ route('panel.platform.users.update', $user) }}"
                                      class="flex items-center gap-2">
                                    @csrf @method('PATCH')
                                    <select class="field max-w-32" name="role">
                                        @foreach (['learner' => 'زبان‌آموز', 'admin' => 'مدیر', 'editor' => 'ویراستار', 'reviewer' => 'بازبین'] as $value => $label)
                                            <option value="{{ $value }}" @selected($user->role === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <select class="field max-w-28" name="status">
                                        <option value="active" @selected($user->status === 'active')>فعال</option>
                                        <option value="suspended" @selected($user->status === 'suspended')>معلق</option>
                                    </select>
                                    <button class="btn-ghost">ذخیره</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="td py-10 text-center text-ink-400" colspan="4">کاربری یافت نشد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $users->links() }}

@endsection
