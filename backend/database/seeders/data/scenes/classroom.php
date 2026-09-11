<?php

/**
 * Asking a tutor for an extension - B2.
 *
 * A negotiation with an unequal power balance, which is exactly where learners
 * either over-apologise or sound like they are making demands. The lines drill
 * the middle: state the situation, make the request, accept a condition.
 */
return [
    'slug' => 'classroom-extension',
    'scenario' => 'university-tutor',
    'cefr' => 'B2',
    'environment' => 'classroom',
    'light' => 1.05,
    'title' => 'Asking for an extension',
    'title_fa' => 'درخواست تمدید مهلت',
    'situation' => 'An assignment is due on Friday and you cannot finish it. You are speaking to your tutor.',
    'situation_fa' => 'تکلیفی جمعه موعدش است و نمی‌توانید تمامش کنید. با استادتان صحبت می‌کنید.',
    'estimated_seconds' => 300,
    'objectives' => [
        'Explain a situation without making excuses',
        'Make a request with a clear reason',
        'Propose a new date yourself',
        'Accept a condition and confirm it',
    ],
    'cast' => [
        [
            'role' => 'tutor', 'character' => 'omar', 'name' => 'Omar',
            'name_fa' => 'عمر', 'playable' => false,
            'colour' => '#4a3f2f', 'skin' => '#8e5a34',
            'x' => -0.9, 'z' => -0.25, 'rotation' => 0.5, 'expression' => 'neutral',
        ],
        [
            'role' => 'student', 'character' => null, 'voice' => '7a6845a2-5865-5669-a0ca-8fc8d8e96528', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#40628f', 'skin' => '#c68642',
            'x' => 0.85, 'z' => 0.05, 'rotation' => -0.5, 'expression' => 'worried',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -0.2, 'z' => -0.9],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.6, 'z' => 0.8],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -1.0, 'z' => -1.1],
    ],
    'vocabulary' => [
        ['word' => 'an extension', 'fa' => 'تمدید مهلت', 'meaning' => 'Extra time to hand work in.'],
        ['word' => 'deadline', 'fa' => 'مهلت', 'meaning' => 'The last moment something can be handed in.'],
        ['word' => 'draft', 'fa' => 'پیش‌نویس', 'meaning' => 'An early, unfinished version of a piece of writing.'],
        ['word' => 'to hand in', 'fa' => 'تحویل دادن', 'meaning' => 'To give completed work to a teacher.'],
        ['word' => 'workload', 'fa' => 'حجم کار', 'meaning' => 'The amount of work you have to do.'],
        ['word' => 'catch up', 'fa' => 'جبران کردن عقب‌ماندگی', 'meaning' => 'To reach the level of work you should be at.'],
    ],
    'beats' => [
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'Come in. You wanted to talk about Friday?',
            'translation_fa' => 'بفرمایید تو. می‌خواستید دربارهٔ جمعه صحبت کنید؟',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'friendly',
        ],
        [
            'role' => 'student', 'interaction' => 'speak',
            'text' => 'Yes. I have been ill for a week and I am behind on the essay.',
            'translation_fa' => 'بله. یک هفته بیمار بودم و از مقاله عقب مانده‌ام.',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
            'prompt' => 'Explain: you have been ill for a week and you are behind on the essay.',
            'prompt_fa' => 'توضیح بدهید: یک هفته بیمار بوده‌اید و از مقاله عقب مانده‌اید.',
            'accept' => [
                'Yes. I have been ill for a week and I am behind on the essay.',
                "I've been ill for a week and I'm behind on the essay.",
                'I have been ill for a week, so I am behind with the essay.',
            ],
            'hint' => 'The illness started in the past and still affects you: have been ill.',
            'hint_fa' => 'بیماری در گذشته شروع شده و هنوز اثر دارد: have been ill.',
        ],
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'I am sorry to hear that. How much have you written?',
            'translation_fa' => 'متأسفم. چقدرش را نوشته‌اید؟',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
        ],
        [
            'role' => 'student', 'interaction' => 'recall',
            'text' => 'I have a rough draft of about half of it.',
            'translation_fa' => 'یک پیش‌نویس خام از حدود نصفش دارم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'I have a rough ______ of about half of it.',
            'prompt_fa' => 'جای خالی را پر کنید: a rough ______.',
            'accept' => ['draft'],
            'hint' => 'An early version, not the final one.',
            'hint_fa' => 'نسخهٔ اولیه، نه نسخهٔ نهایی.',
        ],
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'Half is more than I expected. What are you asking for?',
            'translation_fa' => 'نصف بیشتر از چیزی است که فکر می‌کردم. درخواستتان چیست؟',
            'camera' => 'two_person', 'expression' => 'thinking',
        ],
        [
            'role' => 'student', 'interaction' => 'speak',
            'text' => 'Could I have an extension until Wednesday, please?',
            'translation_fa' => 'می‌شود مهلت را تا چهارشنبه تمدید کنید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Ask for an extension until Wednesday.',
            'prompt_fa' => 'تمدید مهلت تا چهارشنبه را درخواست کنید.',
            'accept' => [
                'Could I have an extension until Wednesday, please?',
                'Would it be possible to have an extension until Wednesday?',
                'Could I have until Wednesday to hand it in?',
            ],
            'hint' => 'Name the day yourself; do not leave it open.',
            'hint_fa' => 'خودتان روز را مشخص کنید و بازش نگذارید.',
        ],
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'Wednesday is possible, but the mark is capped unless you bring a medical note.',
            'translation_fa' => 'چهارشنبه ممکن است، اما بدون گواهی پزشکی نمره سقف می‌خورد.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'student', 'interaction' => 'choose',
            'text' => 'I can bring the note tomorrow. Would that be in time?',
            'translation_fa' => 'می‌توانم گواهی را فردا بیاورم. به موقع است؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which reply keeps the conversation moving?',
            'prompt_fa' => 'کدام جواب گفت‌وگو را پیش می‌برد؟',
            'choices' => [
                ['text' => 'I can bring the note tomorrow. Would that be in time?', 'text_fa' => 'می‌توانم گواهی را فردا بیاورم. به موقع است؟', 'correct' => true],
                ['text' => 'That is not fair. I was really ill.', 'text_fa' => 'این منصفانه نیست. من واقعاً مریض بودم.', 'correct' => false],
                ['text' => 'Okay, no problem, never mind about the mark.', 'text_fa' => 'باشد، مهم نیست، نمره‌اش هم اهمیتی ندارد.', 'correct' => false],
            ],
            'hint' => 'Meet the condition, then check the timing.',
            'hint_fa' => 'شرط را بپذیرید، بعد زمان‌بندی را بپرسید.',
        ],
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'Tomorrow is fine. I will change the deadline on the system now.',
            'translation_fa' => 'فردا خوب است. الان مهلت را در سامانه تغییر می‌دهم.',
            'camera' => 'speaker_closeup', 'expression' => 'friendly',
        ],
        [
            'role' => 'student', 'interaction' => 'speak',
            'text' => 'Thank you. So I hand it in by five on Wednesday, is that right?',
            'translation_fa' => 'ممنون. پس چهارشنبه تا ساعت پنج تحویل بدهم، درست است؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Confirm the new arrangement: hand in by five on Wednesday.',
            'prompt_fa' => 'قرار تازه را تأیید کنید: تحویل تا ساعت پنج چهارشنبه.',
            'accept' => [
                'Thank you. So I hand it in by five on Wednesday, is that right?',
                'So I hand it in by five on Wednesday, is that right?',
                'So the new deadline is five on Wednesday, is that correct?',
            ],
            'hint' => 'Repeat the arrangement back and end with is that right?',
            'hint_fa' => 'قرار را تکرار کنید و آخرش بگویید is that right؟',
        ],
        [
            'role' => 'tutor', 'interaction' => 'watch',
            'text' => 'That is right. Get some rest and let me know if you get stuck.',
            'translation_fa' => 'درست است. استراحت کنید و اگر گیر کردید خبرم کنید.',
            'camera' => 'two_person', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
    ],
];
