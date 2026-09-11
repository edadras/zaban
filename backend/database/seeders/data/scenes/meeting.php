<?php

/**
 * A team meeting - B2.
 *
 * The hardest thing in a work meeting is not vocabulary, it is disagreeing with
 * the person running it without either backing down or sounding rude. Two of
 * the learner's lines here are exactly that: flagging a risk when the honest
 * answer is not the wanted one, and holding a position after conceding a point.
 */
return [
    'slug' => 'meeting-status-update',
    'scenario' => 'team-meeting',
    'cefr' => 'B2',
    'environment' => 'office',
    'light' => 1.0,
    'title' => 'Giving an update in a meeting',
    'title_fa' => 'گزارش کار در جلسه',
    'situation' => 'Your project has slipped by a week and the lead wants to know where things stand.',
    'situation_fa' => 'پروژه یک هفته عقب افتاده و سرپرست می‌خواهد بداند کار در چه وضعیتی است.',
    'estimated_seconds' => 330,
    'objectives' => [
        'Give a status update that includes the bad part',
        'Raise a risk instead of promising what you cannot deliver',
        'Disagree with the person running the meeting, politely',
        'Close with what you will do next',
    ],
    'cast' => [
        [
            'role' => 'lead', 'character' => 'lena', 'name' => 'Lena',
            'name_fa' => 'لنا', 'playable' => false,
            'colour' => '#2b3550', 'skin' => '#d3a179',
            'x' => -0.85, 'z' => -0.05, 'rotation' => 0.55, 'expression' => 'neutral',
        ],
        [
            'role' => 'member', 'character' => null,
            'voice' => '66469f5a-10db-586a-bab1-72f6ee66ba69',
            'name' => 'You', 'name_fa' => 'شما', 'playable' => true,
            'colour' => '#3d4a5c', 'skin' => '#b97a52',
            'x' => 0.85, 'z' => 0.1, 'rotation' => -0.55, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => 0.0, 'z' => -0.75],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -0.85, 'z' => -1.0],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.8, 'z' => 0.9],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.7, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'on track', 'fa' => 'طبق برنامه', 'meaning' => 'Going as planned.'],
        ['word' => 'to slip', 'fa' => 'عقب افتادن', 'meaning' => 'To fall behind the date it was meant to be done.'],
        ['word' => 'to flag a risk', 'fa' => 'هشدار دادن دربارهٔ یک ریسک', 'meaning' => 'To point out something that could go wrong.'],
        ['word' => 'to push back', 'fa' => 'مخالفت کردن', 'meaning' => 'To disagree with a decision or a request.'],
        ['word' => 'to take it offline', 'fa' => 'بیرون از جلسه بررسی کردن', 'meaning' => 'To discuss the detail after the meeting.'],
        ['word' => 'a knock-on effect', 'fa' => 'اثر زنجیره‌ای', 'meaning' => 'A further result caused by the first one.'],
    ],
    'beats' => [
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'Right, let us start with where we are. How is the migration going?',
            'translation_fa' => 'خب، از وضعیت فعلی شروع کنیم. کار انتقال داده‌ها چطور پیش می‌رود؟',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'neutral',
        ],
        [
            'role' => 'member', 'interaction' => 'speak',
            'text' => 'The first half is done and on track. The second half has slipped by about a week.',
            'translation_fa' => 'نیمهٔ اول تمام و طبق برنامه است. نیمهٔ دوم حدود یک هفته عقب افتاده.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Report both halves: the first is on track, the second has slipped a week.',
            'prompt_fa' => 'هر دو نیمه را گزارش کنید: اولی طبق برنامه، دومی یک هفته عقب.',
            'accept' => [
                'The first half is done and on track. The second half has slipped by about a week.',
                'The first half is on track. The second half has slipped by about a week.',
                'The first half is finished, but the second half has slipped by a week.',
            ],
            'hint' => 'Say the good part and the bad part in the same breath.',
            'hint_fa' => 'بخش خوب و بخش بد را با هم بگویید، نه فقط یکی را.',
        ],
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'A week. What caused that?',
            'translation_fa' => 'یک هفته. علتش چه بود؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'member', 'interaction' => 'speak',
            'text' => 'The old records were not in the format we expected.',
            'translation_fa' => 'داده‌های قدیمی به قالبی که انتظار داشتیم نبودند.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Explain the cause: the old records were not in the expected format.',
            'prompt_fa' => 'علت را توضیح بدهید: داده‌های قدیمی در قالب مورد انتظار نبودند.',
            'accept' => [
                'The old records were not in the format we expected.',
                "The old records weren't in the format we expected.",
                'The old data was not in the format we were expecting.',
            ],
            'hint' => 'One sentence, no apology: what was found, not whose fault it was.',
            'hint_fa' => 'یک جمله و بدون عذرخواهی: چه چیزی پیدا شد، نه تقصیر که بود.',
        ],
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'Understood. Can we still make the end of the month?',
            'translation_fa' => 'متوجه شدم. باز هم به آخر ماه می‌رسیم؟',
            'camera' => 'two_person',
        ],
        [
            'role' => 'member', 'interaction' => 'choose',
            'text' => 'We can, but only if nothing else comes in. I would want to flag that as a risk.',
            'translation_fa' => 'می‌رسیم، اما فقط اگر کار تازه‌ای اضافه نشود. می‌خواهم این را به عنوان ریسک مطرح کنم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which answer is honest and still useful to the meeting?',
            'prompt_fa' => 'کدام جواب هم صادقانه است و هم به کار جلسه می‌آید؟',
            'choices' => [
                [
                    'text' => 'We can, but only if nothing else comes in. I would want to flag that as a risk.',
                    'text_fa' => 'می‌رسیم، اما فقط اگر کار تازه‌ای اضافه نشود. می‌خواهم این را به عنوان ریسک مطرح کنم.',
                    'correct' => true,
                ],
                ['text' => 'Yes, definitely, no problem at all.', 'text_fa' => 'بله، حتماً، هیچ مشکلی نیست.', 'correct' => false],
                ['text' => 'I am not knowing. Maybe yes, maybe no.', 'text_fa' => 'نمی‌دانستن. شاید بله شاید نه.', 'correct' => false],
            ],
            'hint' => 'Answer the question, then name the condition it depends on.',
            'hint_fa' => 'به سؤال جواب بدهید، بعد شرطی را که به آن وابسته است بگویید.',
        ],
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'Noted. I would rather cut the reporting screens than move the date.',
            'translation_fa' => 'ثبت شد. ترجیح می‌دهم صفحه‌های گزارش را حذف کنیم تا تاریخ را جابه‌جا کنیم.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing',
        ],
        [
            'role' => 'member', 'interaction' => 'speak',
            'text' => 'I see it differently. The reporting is what the finance team asked for.',
            'translation_fa' => 'من طور دیگری می‌بینم. گزارش‌ها همان چیزی است که واحد مالی خواسته بود.',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Disagree politely, and give the reason.',
            'prompt_fa' => 'با ادب مخالفت کنید و دلیلتان را بگویید.',
            'accept' => [
                'I see it differently. The reporting is what the finance team asked for.',
                'I see it differently, because the reporting is what the finance team asked for.',
                'I would see it differently. The reporting is what finance asked for.',
            ],
            'hint' => 'I see it differently disagrees without saying anyone is wrong.',
            'hint_fa' => 'با I see it differently مخالفت می‌کنید بدون اینکه کسی را غلط بخوانید.',
        ],
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'Fair point. What would you cut instead?',
            'translation_fa' => 'حرف درستی است. به جایش چه چیزی را حذف می‌کنید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'member', 'interaction' => 'recall',
            'text' => 'I would rather we moved the date by a week than dropped it.',
            'translation_fa' => 'ترجیح می‌دهم تاریخ را یک هفته جابه‌جا کنیم تا اینکه آن را حذف کنیم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'I would rather we moved the date by a ______ than dropped it.',
            'prompt_fa' => 'جای خالی را پر کنید: moved the date by a ______.',
            'accept' => ['week'],
            'hint' => 'The same amount of time the project has slipped.',
            'hint_fa' => 'همان مدتی که پروژه عقب افتاده است.',
        ],
        [
            'role' => 'lead', 'interaction' => 'watch',
            'text' => 'All right. Let us take the detail offline and I will update the plan.',
            'translation_fa' => 'باشد. جزئیاتش را بیرون از جلسه بررسی می‌کنیم و من برنامه را به‌روز می‌کنم.',
            'camera' => 'two_person', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'member', 'interaction' => 'speak',
            'text' => 'I will send you the revised dates this afternoon.',
            'translation_fa' => 'تاریخ‌های اصلاح‌شده را امروز بعدازظهر برایتان می‌فرستم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Close with what you will do and when.',
            'prompt_fa' => 'با گفتن اینکه چه کاری و کِی انجام می‌دهید جمع‌بندی کنید.',
            'accept' => [
                'I will send you the revised dates this afternoon.',
                "I'll send you the revised dates this afternoon.",
                'I will send the updated dates over this afternoon.',
            ],
            'hint' => 'A meeting ends better with an action than with agreement.',
            'hint_fa' => 'جلسه با یک اقدام مشخص بهتر تمام می‌شود تا با تأیید کلی.',
        ],
    ],
];
