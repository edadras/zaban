<!doctype html>
{{--
    The standalone speaker.

    Deliberately bare: it is opened inside a web view in the app and in an
    iframe in the panel, and it should carry nothing but the figure, the line
    and the button. Reached by a signed link, so it holds no credential.
--}}
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    @vite(['resources/css/speaker.css', 'resources/js/speaker/main.js'])
</head>
<body>
    <div class="speaker">
        <div class="speaker-stage" id="speaker-stage"></div>

        <div class="speaker-words">
            <p class="speaker-caption" data-caption>{{ $caption }}</p>
            <p class="speaker-note" data-note hidden></p>
        </div>

        <div class="speaker-controls">
            <button type="button" class="is-primary" data-play>{{ $bootstrap['strings']['play'] }}</button>
        </div>
    </div>

    <script>window.__SPEAKER_PAGE__ = @json($bootstrap);</script>

    <noscript>
        <p style="padding:1rem">این بخش برای اجرا به جاوااسکریپت نیاز دارد.</p>
    </noscript>
</body>
</html>
