<?php

/**
 * Checking in - A2.
 *
 * Everything a person has to do at a desk they have never stood at before:
 * give a name, understand a number said quickly, ask about breakfast, and say
 * that something is wrong without being rude about it.
 */
return [
    'slug' => 'hotel-check-in',
    'scenario' => 'hotel-check-in',
    'cefr' => 'A2',
    'environment' => 'hotel',
    'light' => 1.05,
    'title' => 'Checking in at the hotel',
    'title_fa' => 'پذیرش در هتل',
    'situation' => 'You have arrived at your hotel after a long journey and you are checking in.',
    'situation_fa' => 'بعد از سفری طولانی به هتل رسیده‌اید و می‌خواهید پذیرش شوید.',
    'estimated_seconds' => 240,
    'objectives' => [
        'Give your booking name and nights',
        'Understand a room number and a floor',
        'Ask when breakfast is served',
        'Report a problem with the room politely',
    ],
    'cast' => [
        [
            'role' => 'receptionist', 'character' => 'grace', 'name' => 'Grace',
            'name_fa' => 'گریس', 'playable' => false,
            'colour' => '#2f3b52', 'skin' => '#8c5a3c',
            'x' => -0.9, 'z' => -0.3, 'rotation' => 0.5, 'expression' => 'friendly',
        ],
        [
            'role' => 'guest', 'character' => null, 'voice' => 'b847bc29-f184-583a-8ad9-d1f1e16d1a60', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#4f7cff', 'skin' => '#c68642',
            'x' => 0.85, 'z' => 0.05, 'rotation' => -0.5, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -0.1, 'z' => -0.9],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -0.9, 'z' => -1.1],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.6, 'z' => -1.8],
    ],
    'vocabulary' => [
        ['word' => 'booking', 'fa' => 'رزرو', 'meaning' => 'An arrangement made in advance to keep a room.'],
        ['word' => 'double room', 'fa' => 'اتاق دو تخته', 'meaning' => 'A room with one large bed for two people.'],
        ['word' => 'second floor', 'fa' => 'طبقهٔ دوم', 'meaning' => 'Two levels above the ground floor.'],
        ['word' => 'served', 'fa' => 'سرو شدن', 'meaning' => 'Given to guests, as a meal is.'],
        ['word' => 'the lift', 'fa' => 'آسانسور', 'meaning' => 'The machine that carries you between floors.'],
        ['word' => 'air conditioning', 'fa' => 'تهویه مطبوع', 'meaning' => 'The system that cools the air in a room.'],
    ],
    'beats' => [
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'Good afternoon, welcome. Do you have a booking with us?',
            'translation_fa' => 'عصر بخیر، خوش آمدید. رزرو دارید؟',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'friendly',
        ],
        [
            'role' => 'guest', 'interaction' => 'speak',
            'text' => 'Yes, I have a booking for three nights.',
            'translation_fa' => 'بله، برای سه شب رزرو دارم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Say you have a booking for three nights.',
            'prompt_fa' => 'بگویید برای سه شب رزرو دارید.',
            'accept' => [
                'Yes, I have a booking for three nights.',
                'Yes, I have a reservation for three nights.',
                "Yes, I've booked a room for three nights.",
            ],
            'hint' => 'How many nights, not how many days.',
            'hint_fa' => 'تعداد شب‌ها گفته می‌شود، نه تعداد روزها.',
        ],
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'Lovely. Could I have the name it is under?',
            'translation_fa' => 'خیلی خوب. رزرو به نام چه کسی است؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'guest', 'interaction' => 'choose',
            'text' => 'It is under Karimi. K-A-R-I-M-I.',
            'translation_fa' => 'به نام کریمی است. K-A-R-I-M-I.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which answer gives the name the way a receptionist needs it?',
            'prompt_fa' => 'کدام جواب نام را به شکلی می‌دهد که پذیرش لازم دارد؟',
            'choices' => [
                ['text' => 'It is under Karimi. K-A-R-I-M-I.', 'text_fa' => 'به نام کریمی است. K-A-R-I-M-I.', 'correct' => true],
                ['text' => 'My name is under the booking.', 'text_fa' => 'اسم من زیر رزرو است.', 'correct' => false],
                ['text' => 'The name has Karimi in the computer.', 'text_fa' => 'اسم کریمی در کامپیوتر است.', 'correct' => false],
            ],
            'hint' => 'Give the name, then spell it.',
            'hint_fa' => 'اول نام را بگویید، بعد حرف به حرف هجی کنید.',
        ],
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'Here it is. A double room, 214, on the second floor.',
            'translation_fa' => 'پیدا شد. اتاق دو تخته، شمارهٔ ۲۱۴، طبقهٔ دوم.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing',
        ],
        [
            'role' => 'guest', 'interaction' => 'recall',
            'text' => 'Sorry, which floor is it on?',
            'translation_fa' => 'ببخشید، در کدام طبقه است؟',
            'camera' => 'speaker_closeup', 'expression' => 'confused',
            'prompt' => 'Sorry, which ______ is it on?',
            'prompt_fa' => 'جای خالی را پر کنید: Sorry, which ______ is it on؟',
            'accept' => ['floor'],
            'hint' => 'Ground, first, second … each one is a f_____.',
            'hint_fa' => 'همکف، اول، دوم … هر کدام یک f_____ است.',
        ],
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'The second. The lift is behind you, on the right.',
            'translation_fa' => 'طبقهٔ دوم. آسانسور پشت سر شماست، سمت راست.',
            'camera' => 'over_shoulder', 'gesture' => 'pointing',
        ],
        [
            'role' => 'guest', 'interaction' => 'speak',
            'text' => 'Thank you. What time is breakfast served?',
            'translation_fa' => 'ممنون. صبحانه چه ساعتی سرو می‌شود؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Ask what time breakfast is served.',
            'prompt_fa' => 'بپرسید صبحانه چه ساعتی سرو می‌شود.',
            'accept' => [
                'Thank you. What time is breakfast served?',
                'What time is breakfast served?',
                'What time do you serve breakfast?',
            ],
            'hint' => 'What time is … served? asks about the hotel, not about you.',
            'hint_fa' => 'با What time is … served? دربارهٔ خود هتل می‌پرسید نه دربارهٔ خودتان.',
        ],
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'From seven to half past ten, in the room behind the stairs.',
            'translation_fa' => 'از هفت تا ده و نیم، در سالن پشت پله‌ها.',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'guest', 'interaction' => 'speak',
            'text' => 'I am sorry to bother you, but the air conditioning in my room is not working.',
            'translation_fa' => 'ببخشید مزاحم می‌شوم، اما تهویهٔ اتاق من کار نمی‌کند.',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
            'prompt' => 'You are back at the desk. Report politely that the air conditioning is not working.',
            'prompt_fa' => 'به پذیرش برگشته‌اید. با ادب بگویید تهویهٔ اتاق کار نمی‌کند.',
            'accept' => [
                'I am sorry to bother you, but the air conditioning in my room is not working.',
                'Sorry to bother you, but the air conditioning is not working.',
                'Excuse me, the air conditioning in my room does not work.',
            ],
            'hint' => 'Soften the complaint first: Sorry to bother you, but …',
            'hint_fa' => 'اول گله را نرم کنید: Sorry to bother you, but …',
        ],
        [
            'role' => 'receptionist', 'interaction' => 'watch',
            'text' => 'I am very sorry. I will send someone up in ten minutes.',
            'translation_fa' => 'واقعاً متأسفم. تا ده دقیقهٔ دیگر کسی را می‌فرستم.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'worried',
        ],
    ],
];
