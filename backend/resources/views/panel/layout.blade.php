@php
    /** @var \App\Models\User $me */
    $me = auth()->user();
    $access = app(\App\Support\PanelAccess::class);
    $managed = $access->managedSchools($me);
    $coaching = $access->coachingSchools($me);
    $unread = $me->unreadNotifications()->count();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'پنل') — {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/panel.css', 'resources/js/panel.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 antialiased">

<div class="flex min-h-screen">

    {{-- ------------------------------------------------------------ sidebar --}}
    <aside id="panel-sidebar"
           class="fixed inset-y-0 right-0 z-40 hidden w-64 overflow-y-auto border-l border-ink-200
                  bg-white lg:static lg:block">
        <div class="flex h-16 items-center gap-2 border-b border-ink-100 px-5">
            <span class="grid size-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">ز</span>
            <span class="text-sm font-semibold">{{ config('app.name') }}</span>
        </div>

        <nav class="space-y-6 p-4 text-sm">
            <div class="space-y-1">
                <x-panel.nav :href="route('panel.home')" :active="request()->routeIs('panel.home')">میز کار</x-panel.nav>
                <x-panel.nav :href="route('panel.sessions.index')" :active="request()->routeIs('panel.sessions.*')">
                    جلسه‌های من
                </x-panel.nav>
                <x-panel.nav :href="route('panel.classes.index')" :active="request()->routeIs('panel.classes.*')">
                    کلاس‌ها
                </x-panel.nav>
                <x-panel.nav :href="route('panel.notifications')" :active="request()->routeIs('panel.notifications')">
                    اعلان‌ها
                    @if ($unread)
                        <span class="chip bg-brand-100 text-brand-700 tabular">{{ $unread }}</span>
                    @endif
                </x-panel.nav>
            </div>

            @if ($managed->isNotEmpty() || $coaching->isNotEmpty())
                <div class="space-y-1">
                    <p class="px-3 pb-1 text-xs font-medium text-ink-400">آموزشگاه</p>
                    <x-panel.nav :href="route('panel.schools.index')" :active="request()->routeIs('panel.schools.index')">
                        همهٔ آموزشگاه‌ها
                    </x-panel.nav>
                    @foreach ($managed->merge($coaching)->unique('id') as $school)
                        <x-panel.nav :href="route('panel.schools.show', $school)"
                                     :active="request()->routeIs('panel.schools.*') && (int) request()->route('school')?->id === $school->id">
                            {{ $school->name }}
                        </x-panel.nav>
                    @endforeach
                </div>
            @endif

            @if ($me->isAdmin())
                <div class="space-y-1">
                    <p class="px-3 pb-1 text-xs font-medium text-ink-400">سامانه</p>
                    <x-panel.nav :href="route('panel.platform.overview')" :active="request()->routeIs('panel.platform.overview')">
                        نمای کلی
                    </x-panel.nav>
                    <x-panel.nav :href="route('panel.platform.schools')" :active="request()->routeIs('panel.platform.schools*')">
                        آموزشگاه‌ها
                    </x-panel.nav>
                    <x-panel.nav :href="route('panel.platform.users')" :active="request()->routeIs('panel.platform.users')">
                        کاربران
                    </x-panel.nav>
                    <x-panel.nav :href="route('panel.platform.audit')" :active="request()->routeIs('panel.platform.audit')">
                        گزارش تغییرات
                    </x-panel.nav>
                </div>
            @endif
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- ---------------------------------------------------------- header --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-ink-200 bg-white/90 px-5 backdrop-blur">
            <button type="button" data-toggle-sidebar
                    class="btn-ghost !px-2 lg:hidden" aria-label="فهرست">☰</button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold">@yield('heading', 'پنل')</h1>
                @hasSection('subheading')
                    <p class="truncate text-xs text-ink-400">@yield('subheading')</p>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <span class="hidden text-sm text-ink-600 sm:inline">{{ $me->name }}</span>
                <form method="POST" action="{{ route('panel.logout') }}">
                    @csrf
                    <button class="btn-ghost">خروج</button>
                </form>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-5">
            @include('panel.partials.flash')
            @yield('content')
        </main>

        <footer class="px-5 py-6 text-center text-xs text-ink-400">
            © {{ date('Y') }} {{ config('app.name') }}
        </footer>
    </div>
</div>

<div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-ink-900/40 lg:hidden"></div>
@stack('scripts')
</body>
</html>
