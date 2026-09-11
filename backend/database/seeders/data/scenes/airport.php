<?php

/**
 * Lost luggage - B1.
 *
 * Chosen because it is the situation where a learner's English fails them at
 * the worst moment: they are tired, the bag is gone, and the language needed is
 * description and negotiation rather than greetings. The scene drills describing
 * an object precisely and arranging what happens next.
 */
return [
    'slug' => 'airport-lost-luggage',
    'scenario' => 'airport-lost-luggage',
    'cefr' => 'B1',
    'environment' => 'airport',
    'light' => 1.1,
    'title' => 'Lost luggage',
    'title_fa' => 'گم شدن چمدان',
    'situation' => 'Your flight has landed but your suitcase has not arrived. You are at the airline desk.',
    'situation_fa' => 'پرواز شما نشسته اما چمدانتان نیامده است. جلوی باجهٔ شرکت هواپیمایی هستید.',
    'estimated_seconds' => 300,
    'objectives' => [
        'Report that a bag has not arrived',
        'Describe a suitcase so someone else could recognise it',
        'Give a flight number clearly',
        'Arrange delivery and ask what happens next',
    ],
    'cast' => [
        [
            'role' => 'agent', 'character' => 'peter', 'name' => 'Peter',
            'name_fa' => 'پیتر', 'playable' => false,
            'colour' => '#1f3a5f', 'skin' => '#9c6640',
            'x' => -0.9, 'z' => -0.25, 'rotation' => 0.5, 'expression' => 'neutral',
        ],
        [
            'role' => 'passenger', 'character' => null, 'voice' => '64cf4f1a-61c8-5938-9aea-83d12b2e1d13', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#a8563f', 'skin' => '#b97a52',
            'x' => 0.85, 'z' => 0.05, 'rotation' => -0.5, 'expression' => 'worried',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -0.1, 'z' => -0.9],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -0.9, 'z' => -1.1],
        ['id' => 'chair', 'type' => 'chair', 'x' => 2.0, 'z' => 0.9],
    ],
    'vocabulary' => [
        ['word' => 'baggage reclaim', 'fa' => 'تحویل بار', 'meaning' => 'The place where you collect your bags after a flight.'],
        ['word' => 'hard-shell', 'fa' => 'بدنه سخت', 'meaning' => 'Made with a hard outer case rather than fabric.'],
        ['word' => 'a label', 'fa' => 'برچسب', 'meaning' => 'A small tag with your name or a code on it.'],
        ['word' => 'reference number', 'fa' => 'شمارهٔ پیگیری', 'meaning' => 'A code used to find your case in a system.'],
        ['word' => 'to trace', 'fa' => 'ردیابی کردن', 'meaning' => 'To follow information until you find something.'],
        ['word' => 'deliver', 'fa' => 'تحویل دادن', 'meaning' => 'To bring something to an address.'],
    ],
    'beats' => [
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Next, please. How can I help?',
            'translation_fa' => 'نفر بعد، بفرمایید. چطور می‌توانم کمک کنم؟',
            'camera' => 'speaker_closeup', 'expression' => 'neutral',
        ],
        [
            'role' => 'passenger', 'interaction' => 'speak',
            'text' => 'My suitcase has not arrived on the belt.',
            'translation_fa' => 'چمدان من روی نوار نیامده است.',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
            'prompt' => 'Say your suitcase has not arrived.',
            'prompt_fa' => 'بگویید چمدانتان نیامده است.',
            'accept' => [
                'My suitcase has not arrived on the belt.',
                "My suitcase hasn't arrived.",
                'My bag has not come through.',
            ],
            'hint' => 'It has not happened yet, so use the present perfect: has not arrived.',
            'hint_fa' => 'هنوز اتفاق نیفتاده، پس حال کامل به کار ببرید: has not arrived.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'I am sorry to hear that. Which flight were you on?',
            'translation_fa' => 'متأسفم. با کدام پرواز آمدید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'passenger', 'interaction' => 'recall',
            'text' => 'Flight BA four one seven, from Istanbul.',
            'translation_fa' => 'پرواز BA چهار یک هفت، از استانبول.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Flight BA four one seven, ______ Istanbul.',
            'prompt_fa' => 'جای خالی را پر کنید: Flight BA four one seven, ______ Istanbul.',
            'accept' => ['from'],
            'hint' => 'Where the flight started.',
            'hint_fa' => 'جایی که پرواز از آنجا شروع شده است.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Thank you. Can you describe the case for me?',
            'translation_fa' => 'ممنون. می‌توانید چمدان را توصیف کنید؟',
            'camera' => 'two_person', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'passenger', 'interaction' => 'speak',
            'text' => 'It is a large dark green hard-shell case with a red label on the handle.',
            'translation_fa' => 'یک چمدان بزرگ سبز تیره با بدنهٔ سخت که برچسب قرمزی روی دسته‌اش هست.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing',
            'prompt' => 'Describe the case: large, dark green, hard-shell, red label on the handle.',
            'prompt_fa' => 'چمدان را توصیف کنید: بزرگ، سبز تیره، بدنهٔ سخت، با برچسب قرمز روی دسته.',
            'accept' => [
                'It is a large dark green hard-shell case with a red label on the handle.',
                "It's a large dark green hard-shell suitcase with a red label on the handle.",
                'It is a big dark green hard-shell case and it has a red label on the handle.',
            ],
            'hint' => 'Size, then colour, then material: a large dark green hard-shell case.',
            'hint_fa' => 'اول اندازه، بعد رنگ، بعد جنس: a large dark green hard-shell case.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'That is helpful. It may still be in Istanbul. I will trace it now.',
            'translation_fa' => 'کمک‌کننده بود. ممکن است هنوز در استانبول باشد. الان ردیابی‌اش می‌کنم.',
            'camera' => 'speaker_closeup', 'animation' => 'thinking', 'expression' => 'thinking',
        ],
        [
            'role' => 'passenger', 'interaction' => 'choose',
            'text' => 'How long does tracing usually take?',
            'translation_fa' => 'ردیابی معمولاً چقدر طول می‌کشد؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Choose the correct way to ask about the usual time.',
            'prompt_fa' => 'درست‌ترین راه پرسیدن مدت معمول را انتخاب کنید.',
            'choices' => [
                ['text' => 'How long does tracing usually take?', 'text_fa' => 'ردیابی معمولاً چقدر طول می‌کشد؟', 'correct' => true],
                ['text' => 'How much time is tracing usually taking?', 'text_fa' => 'ردیابی معمولاً دارد چقدر وقت می‌گیرد؟', 'correct' => false],
                ['text' => 'How long time takes to trace usually?', 'text_fa' => 'چقدر زمان می‌برد تا معمولاً ردیابی شود؟', 'correct' => false],
            ],
            'hint' => 'Something that is usually true takes the present simple.',
            'hint_fa' => 'برای چیزی که معمولاً درست است، حال ساده به کار می‌رود.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Most cases are found within twenty-four hours. Where are you staying?',
            'translation_fa' => 'بیشتر چمدان‌ها تا بیست‌وچهار ساعت پیدا می‌شوند. کجا اقامت دارید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'passenger', 'interaction' => 'speak',
            'text' => 'Could you deliver it to my hotel when you find it?',
            'translation_fa' => 'وقتی پیدا شد می‌توانید به هتلم تحویل بدهید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Ask them to deliver the case to your hotel.',
            'prompt_fa' => 'بخواهید چمدان را به هتلتان تحویل بدهند.',
            'accept' => [
                'Could you deliver it to my hotel when you find it?',
                'Can you deliver it to my hotel when you find it?',
                'Would you be able to deliver it to my hotel?',
            ],
            'hint' => 'Could you … ? is the request; when you find it is the condition.',
            'hint_fa' => 'درخواست با Could you … ? و شرط با when you find it بیان می‌شود.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Of course. Here is your reference number. Keep it safe.',
            'translation_fa' => 'حتماً. این شمارهٔ پیگیری شماست. آن را نگه دارید.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing', 'expression' => 'friendly',
        ],
        [
            'role' => 'passenger', 'interaction' => 'speak',
            'text' => 'Thank you. What should I do if it has not arrived by tomorrow evening?',
            'translation_fa' => 'ممنون. اگر تا فردا عصر نرسید چه کار کنم؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Ask what to do if the bag has not arrived by tomorrow evening.',
            'prompt_fa' => 'بپرسید اگر چمدان تا فردا عصر نرسید باید چه کار کنید.',
            'accept' => [
                'Thank you. What should I do if it has not arrived by tomorrow evening?',
                'What should I do if it has not arrived by tomorrow evening?',
                "What do I do if it hasn't arrived by tomorrow evening?",
            ],
            'hint' => 'What should I do if …? asks for instructions in advance.',
            'hint_fa' => 'با What should I do if …? از پیش دستورالعمل می‌خواهید.',
        ],
    ],
];
