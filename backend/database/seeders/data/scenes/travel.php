<?php

/**
 * Booking a trip - B1.
 *
 * The situation where a learner has to compare two things out loud and then
 * commit to one. Most travel language is taught as vocabulary lists; what
 * actually fails at the counter is asking the question that tells you which
 * option is better, and then saying which one you want without hedging.
 */
return [
    'slug' => 'travel-booking',
    'scenario' => 'booking-travel',
    'cefr' => 'B1',
    'environment' => 'office',
    'light' => 1.0,
    'title' => 'Booking a trip',
    'title_fa' => 'رزرو سفر',
    'situation' => 'You are at a travel desk, arranging a flight and deciding between two options.',
    'situation_fa' => 'پشت باجهٔ آژانس مسافرتی هستید و بین دو گزینهٔ پرواز تصمیم می‌گیرید.',
    'estimated_seconds' => 300,
    'objectives' => [
        'Say where and when you want to travel',
        'Ask the question that compares two options',
        'Choose one and say so plainly',
        'Confirm how you will be sent the booking',
    ],
    'cast' => [
        [
            'role' => 'agent', 'character' => 'peter', 'name' => 'Peter',
            'name_fa' => 'پیتر', 'playable' => false,
            'colour' => '#1f3a5f', 'skin' => '#9c6640',
            'x' => -0.85, 'z' => -0.1, 'rotation' => 0.55, 'expression' => 'friendly',
        ],
        [
            'role' => 'traveller', 'character' => null,
            'voice' => 'b847bc29-f184-583a-8ad9-d1f1e16d1a60',
            'name' => 'You', 'name_fa' => 'شما', 'playable' => true,
            'colour' => '#4f7cff', 'skin' => '#c68642',
            'x' => 0.8, 'z' => 0.05, 'rotation' => -0.55, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => 0.0, 'z' => -0.8],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -0.8, 'z' => -1.0],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.8, 'z' => 0.9],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.7, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'a return', 'fa' => 'بلیت رفت و برگشت', 'meaning' => 'A ticket there and back again.'],
        ['word' => 'direct', 'fa' => 'مستقیم', 'meaning' => 'With no change on the way.'],
        ['word' => 'a change', 'fa' => 'توقف برای تعویض پرواز', 'meaning' => 'Getting off one flight and onto another.'],
        ['word' => 'an aisle seat', 'fa' => 'صندلی کنار راهرو', 'meaning' => 'A seat next to the walkway, not the window.'],
        ['word' => 'confirmation', 'fa' => 'تأییدیه', 'meaning' => 'The message proving the booking was made.'],
        ['word' => 'to book', 'fa' => 'رزرو کردن', 'meaning' => 'To arrange and pay for a place in advance.'],
    ],
    'beats' => [
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Good morning. What can I do for you today?',
            'translation_fa' => 'صبح بخیر. امروز چه کمکی از دستم برمی‌آید؟',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'friendly',
        ],
        [
            'role' => 'traveller', 'interaction' => 'speak',
            'text' => 'I would like to book a return flight to Madrid in April.',
            'translation_fa' => 'می‌خواهم یک پرواز رفت و برگشت به مادرید برای آوریل رزرو کنم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Say you want to book a return flight to Madrid in April.',
            'prompt_fa' => 'بگویید می‌خواهید پرواز رفت و برگشت به مادرید برای آوریل رزرو کنید.',
            'accept' => [
                'I would like to book a return flight to Madrid in April.',
                "I'd like to book a return flight to Madrid in April.",
                'I want to book a return ticket to Madrid in April.',
            ],
            'hint' => 'I would like to … is the polite way to open a request.',
            'hint_fa' => 'برای شروع مؤدبانهٔ درخواست از I would like to … استفاده کنید.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Of course. Which dates in April were you thinking of?',
            'translation_fa' => 'حتماً. چه تاریخ‌هایی در آوریل مدنظرتان است؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'traveller', 'interaction' => 'recall',
            'text' => 'Out on the tenth and back on the seventeenth.',
            'translation_fa' => 'رفت دهم و برگشت هفدهم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Out on the tenth and ______ on the seventeenth.',
            'prompt_fa' => 'جای خالی را پر کنید: Out on the tenth and ______ on the seventeenth.',
            'accept' => ['back'],
            'hint' => 'Going out, then coming b____.',
            'hint_fa' => 'رفتن، و بعد b____ آمدن.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'I have two. A direct flight at nine in the morning, or one with a change in Paris for sixty euros less.',
            'translation_fa' => 'دو گزینه دارم. پرواز مستقیم ساعت نُه صبح، یا یکی با توقف در پاریس که شصت یورو ارزان‌تر است.',
            'camera' => 'two_person', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'traveller', 'interaction' => 'choose',
            'text' => 'How much longer does the one with a change take?',
            'translation_fa' => 'آنکه توقف دارد چقدر بیشتر طول می‌کشد؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Which question actually tells you which option is better?',
            'prompt_fa' => 'کدام سؤال واقعاً به شما می‌گوید کدام گزینه بهتر است؟',
            'choices' => [
                ['text' => 'How much longer does the one with a change take?', 'text_fa' => 'آنکه توقف دارد چقدر بیشتر طول می‌کشد؟', 'correct' => true],
                ['text' => 'Which one is the cheapest for me to take it?', 'text_fa' => 'کدام یکی ارزان‌ترین است برای من که بگیرمش؟', 'correct' => false],
                ['text' => 'How long is taking the changing flight?', 'text_fa' => 'چقدر است گرفتن پرواز با تعویض؟', 'correct' => false],
            ],
            'hint' => 'You already know the price difference. Ask about the other one.',
            'hint_fa' => 'تفاوت قیمت را می‌دانید؛ دربارهٔ آن یکی تفاوت بپرسید.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Three hours longer, including the wait in Paris.',
            'translation_fa' => 'سه ساعت بیشتر، با احتساب انتظار در پاریس.',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'traveller', 'interaction' => 'speak',
            'text' => 'I will take the direct one, then.',
            'translation_fa' => 'پس همان مستقیم را می‌گیرم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Decide: you will take the direct flight.',
            'prompt_fa' => 'تصمیم بگیرید: پرواز مستقیم را می‌گیرید.',
            'accept' => [
                'I will take the direct one, then.',
                "I'll take the direct one, then.",
                'I will take the direct flight.',
            ],
            'hint' => "A decision made now uses I'll.",
            'hint_fa' => 'تصمیمی که همین حالا گرفته می‌شود با I’ll بیان می‌شود.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Window or aisle?',
            'translation_fa' => 'کنار پنجره یا کنار راهرو؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'traveller', 'interaction' => 'speak',
            'text' => 'An aisle seat, please.',
            'translation_fa' => 'لطفاً صندلی کنار راهرو.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Ask for an aisle seat.',
            'prompt_fa' => 'صندلی کنار راهرو بخواهید.',
            'accept' => ['An aisle seat, please.', 'Aisle, please.', 'I would like an aisle seat, please.'],
            'hint' => 'The seat by the walkway is the aisle.',
            'hint_fa' => 'صندلی کنار راهرو می‌شود aisle.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'That is booked. Shall I email the confirmation?',
            'translation_fa' => 'رزرو شد. تأییدیه را ایمیل کنم؟',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
        ],
        [
            'role' => 'traveller', 'interaction' => 'speak',
            'text' => 'Yes, please. Could you send it to the address on the booking?',
            'translation_fa' => 'بله لطفاً. می‌شود به همان ایمیلی که در رزرو هست بفرستید؟',
            'camera' => 'two_person',
            'prompt' => 'Accept, and ask for it to go to the address on the booking.',
            'prompt_fa' => 'قبول کنید و بخواهید به همان ایمیل ثبت‌شده در رزرو فرستاده شود.',
            'accept' => [
                'Yes, please. Could you send it to the address on the booking?',
                'Could you send it to the address on the booking?',
                'Yes please, send it to the email on the booking.',
            ],
            'hint' => 'Could you …? keeps a second request polite.',
            'hint_fa' => 'با Could you …? درخواست دوم هم مؤدب می‌ماند.',
        ],
    ],
];
