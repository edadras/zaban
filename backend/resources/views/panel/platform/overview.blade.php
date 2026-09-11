@extends('panel.layout')
@section('title', 'نمای کلی سامانه')
@section('heading', 'نمای کلی سامانه')

@section('content')

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-panel.stat label="کاربران" :value="number_format($users)" :hint="number_format($learners) . ' زبان‌آموز'" />
        <x-panel.stat label="فعال امروز" :value="number_format($activeToday)" />
        <x-panel.stat label="تازه‌وارد این هفته" :value="number_format($newThisWeek)" />
        <x-panel.stat label="معلق" :value="number_format($suspended)" />

        <x-panel.stat label="آموزشگاه‌ها" :value="number_format($schools)" />
        <x-panel.stat label="جلسه‌های تمرین امروز" :value="number_format($sessionsToday)" />
        <x-panel.stat label="اشتراک فعال" :value="number_format($liveSubscriptions)" />
        <x-panel.stat label="پرداخت در انتظار بررسی" :value="number_format($pendingPayments)" />

        <x-panel.stat label="صف بازبینی محتوا" :value="number_format($reviewQueue)" />
        <x-panel.stat label="هزینهٔ هوش مصنوعی ۳۰ روز" :value="'$' . number_format($aiCost30, 2)" />
    </section>

    <section class="card">
        <div class="card-head"><h2 class="card-title">کارهای عمیق‌تر</h2></div>
        <div class="space-y-2 p-5 text-sm text-ink-600">
            <p>
                بارگذاری محتوا، بازبینی تولیدهای هوش مصنوعی، صورتحساب‌ها و برنامهٔ درسی در داشبورد
                مدیریت اپلیکیشن انجام می‌شوند؛ این صفحه همان اعداد را از همان جدول‌ها می‌خواند.
            </p>
            <div class="flex flex-wrap gap-2 pt-1">
                <a class="btn-ghost" href="{{ route('panel.platform.schools') }}">ثبت آموزشگاه</a>
                <a class="btn-ghost" href="{{ route('panel.platform.users') }}">کاربران</a>
                <a class="btn-ghost" href="{{ route('panel.platform.audit') }}">گزارش تغییرات</a>
            </div>
        </div>
    </section>

@endsection
