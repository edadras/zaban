<?php

/**
 * Ordering a meal - A2.
 *
 * The first scene most learners can finish, and written that way on purpose:
 * short lines, one new structure at a time, and the two requests that carry the
 * whole situation - asking what something is, and asking for the bill.
 */
return [
    'slug' => 'restaurant-ordering',
    'scenario' => 'restaurant',
    'cefr' => 'A2',
    'environment' => 'restaurant',
    'light' => 0.9,
    'title' => 'Ordering a meal',
    'title_fa' => 'سفارش غذا',
    'situation' => 'You are at a restaurant table. The server comes to take your order.',
    'situation_fa' => 'سر میز رستوران نشسته‌اید و گارسون برای گرفتن سفارش می‌آید.',
    'estimated_seconds' => 240,
    'objectives' => [
        'Order a dish politely',
        'Ask what is in a dish',
        'Say that something is finished or not available',
        'Ask for the bill',
    ],
    'cast' => [
        [
            'role' => 'server', 'character' => 'ines', 'name' => 'Inés',
            'name_fa' => 'اینس', 'playable' => false,
            'colour' => '#3f4a5c', 'skin' => '#c58b60',
            'x' => -0.8, 'z' => -0.2, 'rotation' => 0.6, 'expression' => 'friendly',
        ],
        [
            'role' => 'customer', 'character' => null, 'voice' => '3c7d32be-0182-5c5e-aa6a-663409bfbb26', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#7a5ea8', 'skin' => '#b97a52',
            'x' => 0.8, 'z' => 0.1, 'rotation' => -0.6, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => 0.0, 'z' => -0.6],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.4, 'z' => 0.8],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.7, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'starter', 'fa' => 'پیش‌غذا', 'meaning' => 'A small dish eaten before the main course.'],
        ['word' => 'main course', 'fa' => 'غذای اصلی', 'meaning' => 'The largest dish of a meal.'],
        ['word' => 'the special', 'fa' => 'غذای ویژهٔ روز', 'meaning' => 'A dish the restaurant offers only today.'],
        ['word' => 'to be out of something', 'fa' => 'تمام شدن چیزی', 'meaning' => 'To have none of it left.'],
        ['word' => 'still or sparkling', 'fa' => 'بدون گاز یا گازدار', 'meaning' => 'Water without or with bubbles.'],
        ['word' => 'the bill', 'fa' => 'صورتحساب', 'meaning' => 'The paper showing what you must pay.'],
    ],
    'beats' => [
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'Good evening. Are you ready to order?',
            'translation_fa' => 'عصر بخیر. آماده‌اید سفارش بدهید؟',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'friendly',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'Yes. Could I have the soup to start, please?',
            'translation_fa' => 'بله. لطفاً برای پیش‌غذا سوپ می‌خواهم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Order the soup as a starter, politely.',
            'prompt_fa' => 'با ادب، سوپ را به عنوان پیش‌غذا سفارش بدهید.',
            'accept' => [
                'Yes. Could I have the soup to start, please?',
                'Can I have the soup to start, please?',
                "I'd like the soup to start, please.",
            ],
            'hint' => 'Could I have … is the polite way to order.',
            'hint_fa' => 'برای سفارش مؤدبانه از Could I have … استفاده کنید.',
        ],
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'Of course. And for your main course?',
            'translation_fa' => 'حتماً. برای غذای اصلی چه میل دارید؟',
            'camera' => 'speaker_closeup', 'expression' => 'friendly',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'What is in the chicken special?',
            'translation_fa' => 'غذای ویژهٔ مرغ چه چیزهایی دارد؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Ask what is in the chicken special.',
            'prompt_fa' => 'بپرسید غذای ویژهٔ مرغ چه موادی دارد.',
            'accept' => [
                'What is in the chicken special?',
                "What's in the chicken special?",
                'Could you tell me what is in the chicken special?',
            ],
            'hint' => 'Ask about the contents: What is in …?',
            'hint_fa' => 'برای پرسیدن مواد غذا: What is in …؟',
        ],
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'Chicken, rice and green beans, with a lemon sauce.',
            'translation_fa' => 'مرغ، برنج و لوبیا سبز، با سس لیمو.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'customer', 'interaction' => 'choose',
            'text' => "That sounds good. I'll have that.",
            'translation_fa' => 'خوب به نظر می‌رسد. همان را می‌خواهم.',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
            'prompt' => 'Choose the natural way to accept it.',
            'prompt_fa' => 'طبیعی‌ترین راه پذیرفتن را انتخاب کنید.',
            'choices' => [
                ['text' => "That sounds good. I'll have that.", 'text_fa' => 'خوب به نظر می‌رسد. همان را می‌خواهم.', 'correct' => true],
                ['text' => 'That sounds good. I am having that now.', 'text_fa' => 'خوب به نظر می‌رسد. الان دارم آن را می‌خورم.', 'correct' => false],
                ['text' => 'That sounds good. I will taking that.', 'text_fa' => 'خوب به نظر می‌رسد. آن را خواهم گرفتن.', 'correct' => false],
            ],
            'hint' => "A decision made now uses I'll.",
            'hint_fa' => 'تصمیمی که همین حالا گرفته می‌شود با I’ll بیان می‌شود.',
        ],
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'And to drink? We have still or sparkling water.',
            'translation_fa' => 'نوشیدنی چه؟ آب بدون گاز داریم و گازدار.',
            'camera' => 'two_person',
        ],
        [
            'role' => 'customer', 'interaction' => 'recall',
            'text' => 'Still water, please. And a glass of orange juice.',
            'translation_fa' => 'لطفاً آب بدون گاز. و یک لیوان آب‌پرتقال.',
            'camera' => 'speaker_closeup',
            'prompt' => '______ water, please. And a glass of orange juice.',
            'prompt_fa' => 'جای خالی را پر کنید: ______ water, please.',
            'accept' => ['still'],
            'hint' => 'Water with no bubbles in it.',
            'hint_fa' => 'آبی که گاز ندارد.',
        ],
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'I am sorry, we are out of orange juice this evening. Apple?',
            'translation_fa' => 'متأسفم، امشب آب‌پرتقال تمام کرده‌ایم. آب‌سیب چطور؟',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'Apple is fine, thank you.',
            'translation_fa' => 'آب‌سیب خوب است، ممنون.',
            'camera' => 'speaker_closeup', 'expression' => 'friendly',
            'prompt' => 'Accept the apple juice politely.',
            'prompt_fa' => 'با ادب آب‌سیب را بپذیرید.',
            'accept' => ['Apple is fine, thank you.', 'Apple juice is fine, thanks.', "That's fine, apple please."],
            'hint' => 'X is fine, thank you accepts a second-best option without complaint.',
            'hint_fa' => 'با X is fine, thank you گزینهٔ دوم را بدون گله می‌پذیرید.',
        ],
        [
            'role' => 'server', 'interaction' => 'watch',
            'text' => 'Lovely. I will bring your soup right away.',
            'translation_fa' => 'عالی. همین الان سوپتان را می‌آورم.',
            'camera' => 'two_person', 'animation' => 'standing', 'expression' => 'happy',
        ],
        [
            'role' => 'customer', 'interaction' => 'speak',
            'text' => 'Excuse me, could we have the bill, please?',
            'translation_fa' => 'ببخشید، می‌شود صورتحساب را بیاورید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'The meal is over. Ask for the bill.',
            'prompt_fa' => 'غذا تمام شده است. صورتحساب را بخواهید.',
            'accept' => [
                'Excuse me, could we have the bill, please?',
                'Could we have the bill, please?',
                'Can we have the bill, please?',
            ],
            'hint' => 'Start with Excuse me, then Could we have …',
            'hint_fa' => 'با Excuse me شروع کنید و بعد Could we have …',
        ],
    ],
];
