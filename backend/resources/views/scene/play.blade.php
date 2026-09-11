<!doctype html>
{{--
    The acted-scene player.

    Deliberately a bare page rather than a panel layout: it is opened inside the
    app as often as in a browser, and it should carry nothing but the stage, the
    words and the controls.
--}}
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $scene->title_fa ?: $scene->title }}</title>
    @vite(['resources/css/scene.css', 'resources/js/scene/main.js'])
</head>
<body class="scene-body">
    <div class="scene-shell">
        <div id="scene-stage"></div>
        <div id="scene-ui"></div>
    </div>

    @php($bootstrapJson = $bootstrap)
    <script>window.__SCENE__ = @json($bootstrapJson);</script>

    <noscript>
        <p style="padding:1rem">این تمرین برای اجرا به جاوااسکریپت نیاز دارد.</p>
    </noscript>
</body>
</html>
