<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود به پنل — {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/panel.css'])
</head>
<body class="grid min-h-screen place-items-center bg-ink-50 p-5 text-ink-900 antialiased">

<div class="w-full max-w-sm space-y-6">
    <div class="text-center">
        <span class="mx-auto grid size-11 place-items-center rounded-xl bg-brand-600 text-lg font-bold text-white">ز</span>
        <h1 class="mt-3 text-lg font-semibold">پنل آموزشگاه</h1>
        <p class="mt-1 text-sm text-ink-600">ویژهٔ مدیران آموزشگاه و مربیان</p>
    </div>

    <form method="POST" action="{{ route('panel.login.attempt') }}" class="card space-y-4 p-5">
        @csrf

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label class="label" for="email">ایمیل</label>
            <input class="field" id="email" name="email" type="email" dir="ltr"
                   value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>

        <div>
            <label class="label" for="password">گذرواژه</label>
            <input class="field" id="password" name="password" type="password" dir="ltr"
                   required autocomplete="current-password">
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-ink-200">
            مرا به خاطر بسپار
        </label>

        <button class="btn-primary w-full">ورود</button>
    </form>

    <p class="text-center text-xs text-ink-400">
        زبان‌آموز هستید؟ از اپلیکیشن استفاده کنید.
    </p>
</div>

</body>
</html>
