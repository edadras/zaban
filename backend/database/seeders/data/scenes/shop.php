<?php

/**
 * Returning a faulty item - A2.
 *
 * Complaining is a skill, and in English it is mostly a politeness skill. The
 * scene teaches the shape that gets a refund without an argument: what you
 * bought, what is wrong, what you want, and the receipt.
 */
return [
    'slug' => 'shop-faulty-return',
    'scenario' => 'shopping-return',
    'cefr' => 'A2',
    'environment' => 'shop',
    'light' => 1.0,
    'title' => 'Taking something back',
    'title_fa' => 'پس دادن جنس خراب',
    'situation' => 'A kettle you bought last week has stopped working and you have brought it back.',
    'situation_fa' => 'کتری‌ای که هفتهٔ پیش خریده‌اید خراب شده و آن را برگردانده‌اید.',
    'estimated_seconds' => 240,
    'objectives' => [
        'Say what you bought and when',
        'Explain what is wrong with it',
        'Ask for a refund rather than an exchange',
        'Answer about the receipt',
    ],
    'cast' => [
        [
            'role' => 'assistant', 'character' => 'tomas', 'name' => 'Tomas',
            'name_fa' => 'توماس', 'playable' => false,
            'colour' => '#37503c', 'skin' => '#a9703f',
            'x' => -0.9, 'z' => -0.3, 'rotation' => 0.5, 'expression' => 'neutral',
        ],
        [
            'role' => 'customer', 'character' => null, 'voice' => 'a3ce02fe-4d3e-55bc-b4d4-a4801b9acdb4', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#4f7cff', 'skin' => '#c68642',
            'x' => 0.85, 'z' => 0.05, 'rotation' => -0.5, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -0.1, 'z' => -0.9],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.6, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'faulty', 'fa' => 'معیوب', 'meaning' => 'Not working as it should because something is wrong with it.'],
        ['word' => 'a refund', 'fa' => 'بازگرداندن پول', 'meaning' => 'Your money given back to you.'],
        ['word' => 'exchange', 'fa' => 'تعویض', 'meaning' => 'Swapping one item for another.'],
        ['word' => 'receipt', 'fa' => 'رسید', 'meaning' => 'The paper that proves you bought something.'],
        ['word' => 'switch on', 'fa' => 'روشن کردن', 'meaning' => 'To start a machine working.'],
        ['word' => 'guarantee', 'fa' => 'ضمانت', 'meaning' => 'A promise to repair or replace something that breaks.'],
    ],
    'beats' => [
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'Hello. Is everything all right there?',
            'translation_fa' => 'سلام. مشکلی پیش آمده؟',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'friendly',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'I bought this kettle here last week and it is faulty.',
            'translation_fa' => 'این کتری را هفتهٔ پیش از اینجا خریدم و معیوب است.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing',
            'prompt' => 'Say you bought the kettle here last week and it is faulty.',
            'prompt_fa' => 'بگویید کتری را هفتهٔ پیش از اینجا خریده‌اید و معیوب است.',
            'accept' => [
                'I bought this kettle here last week and it is faulty.',
                "I bought this kettle here last week and it's faulty.",
                'I bought this kettle from you last week and it does not work.',
            ],
            'hint' => 'Last week is finished: bought, not have bought.',
            'hint_fa' => 'هفتهٔ گذشته تمام شده: bought، نه have bought.',
        ],
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'Oh dear. What exactly is it doing?',
            'translation_fa' => 'ای وای. دقیقاً چه اتفاقی می‌افتد؟',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'It does not switch on at all now.',
            'translation_fa' => 'اصلاً دیگر روشن نمی‌شود.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Explain that it does not switch on at all now.',
            'prompt_fa' => 'توضیح بدهید که اصلاً دیگر روشن نمی‌شود.',
            'accept' => [
                'It does not switch on at all now.',
                "It doesn't switch on at all now.",
                'It will not switch on any more.',
            ],
            'hint' => 'A present problem takes the present simple with does not.',
            'hint_fa' => 'مشکل فعلی با حال ساده و does not بیان می‌شود.',
        ],
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'Have you got the receipt with you?',
            'translation_fa' => 'رسیدش را همراه دارید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'customer', 'interaction' => 'recall',
            'text' => 'Yes, here is the receipt.',
            'translation_fa' => 'بله، رسید اینجاست.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Yes, here is the ______.',
            'prompt_fa' => 'جای خالی را پر کنید: Yes, here is the ______.',
            'accept' => ['receipt'],
            'hint' => 'The paper that proves you paid.',
            'hint_fa' => 'کاغذی که ثابت می‌کند پول را پرداخته‌اید.',
        ],
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'Thank you. I can exchange it for the same one today.',
            'translation_fa' => 'ممنون. امروز می‌توانم با همین مدل عوضش کنم.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'customer', 'interaction' => 'choose',
            'text' => 'I would rather have a refund, if that is possible.',
            'translation_fa' => 'اگر ممکن باشد ترجیح می‌دهم پولم را پس بگیرم.',
            'camera' => 'speaker_closeup', 'expression' => 'neutral',
            'prompt' => 'You want your money back, not another kettle. Choose the polite way to say so.',
            'prompt_fa' => 'شما پولتان را می‌خواهید نه کتری دیگر. راه مؤدبانهٔ گفتنش را انتخاب کنید.',
            'choices' => [
                ['text' => 'I would rather have a refund, if that is possible.', 'text_fa' => 'اگر ممکن باشد ترجیح می‌دهم پولم را پس بگیرم.', 'correct' => true],
                ['text' => 'No. Give me back the money now.', 'text_fa' => 'نه. همین حالا پولم را پس بده.', 'correct' => false],
                ['text' => 'I am preferring the refund than exchange.', 'text_fa' => 'من ترجیح دادن پس گرفتن پول از تعویض.', 'correct' => false],
            ],
            'hint' => 'I would rather … softens a refusal into a preference.',
            'hint_fa' => 'با I would rather … مخالفت را به ترجیح تبدیل می‌کنید.',
        ],
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'That is fine. It is within thirty days, so I can refund the card.',
            'translation_fa' => 'اشکالی ندارد. داخل سی روز است، پس می‌توانم به کارت برگردانم.',
            'camera' => 'two_person', 'expression' => 'friendly',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'How long will the refund take to reach my account?',
            'translation_fa' => 'چقدر طول می‌کشد تا پول به حسابم برسد؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Ask how long the refund takes to reach your account.',
            'prompt_fa' => 'بپرسید چقدر طول می‌کشد پول به حسابتان برسد.',
            'accept' => [
                'How long will the refund take to reach my account?',
                'How long does the refund take to reach my account?',
                'When will the money be back in my account?',
            ],
            'hint' => 'How long will … take? asks about a future length of time.',
            'hint_fa' => 'با How long will … take? دربارهٔ مدت زمان آینده می‌پرسید.',
        ],
        [
            'role' => 'assistant', 'interaction' => 'watch',
            'text' => 'Three to five working days. Sorry about the trouble.',
            'translation_fa' => 'سه تا پنج روز کاری. بابت دردسر متأسفم.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
    ],
];
