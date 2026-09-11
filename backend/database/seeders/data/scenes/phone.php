<?php

/**
 * A phone enquiry - B1.
 *
 * The situation the course keeps promising to prepare people for and never
 * quite does: no face, no gestures, and a stranger reading from a script. What
 * carries it is being able to say why you are calling in one sentence, spell
 * and repeat details without embarrassment, and ask for the reference number
 * back - which is the line most learners never think to say.
 *
 * Staged with both people on screen although they cannot see each other. That
 * is the film convention rather than the truth of a phone call, and it is the
 * right trade here: a learner watching one person talk to nobody learns less
 * about the exchange than one watching both halves of it.
 */
return [
    'slug' => 'phone-broadband-fault',
    'scenario' => 'phone-enquiry',
    'cefr' => 'B1',
    'environment' => 'office',
    'light' => 1.0,
    'title' => 'Phoning about a fault',
    'title_fa' => 'تماس تلفنی برای خرابی سرویس',
    'situation' => 'Your internet has been down since yesterday and you are calling customer service.',
    'situation_fa' => 'اینترنتتان از دیروز قطع است و با پشتیبانی تماس گرفته‌اید.',
    'estimated_seconds' => 300,
    'objectives' => [
        'Say why you are calling in one sentence',
        'Give your details when asked',
        'Ask for a time that suits you instead of accepting the first one',
        'Ask for the reference number back',
    ],
    'cast' => [
        [
            'role' => 'agent', 'character' => 'grace', 'name' => 'Grace',
            'name_fa' => 'گریس', 'playable' => false,
            'colour' => '#2f3b52', 'skin' => '#8c5a3c',
            'x' => -0.9, 'z' => -0.25, 'rotation' => 0.35, 'expression' => 'friendly',
        ],
        [
            'role' => 'caller', 'character' => null,
            'voice' => 'a3ce02fe-4d3e-55bc-b4d4-a4801b9acdb4',
            'name' => 'You', 'name_fa' => 'شما', 'playable' => true,
            'colour' => '#7a5ea8', 'skin' => '#b97a52',
            'x' => 0.9, 'z' => 0.1, 'rotation' => -0.35, 'expression' => 'worried',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -1.2, 'z' => -1.2],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -1.6, 'z' => -1.35],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.9, 'z' => 0.9],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.8, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'to be down', 'fa' => 'قطع بودن', 'meaning' => 'Not working, said of a service.'],
        ['word' => 'a fault', 'fa' => 'خرابی', 'meaning' => 'Something broken in the equipment or the line.'],
        ['word' => 'an engineer', 'fa' => 'تکنسین', 'meaning' => 'The person sent out to repair it.'],
        ['word' => 'a slot', 'fa' => 'بازهٔ زمانی', 'meaning' => 'A period of time booked for an appointment.'],
        ['word' => 'a reference number', 'fa' => 'شمارهٔ پیگیری', 'meaning' => 'The code that finds your case again.'],
        ['word' => 'to confirm', 'fa' => 'تأیید کردن', 'meaning' => 'To say officially that something is arranged.'],
    ],
    'beats' => [
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Good afternoon, customer service. How can I help?',
            'translation_fa' => 'عصر بخیر، پشتیبانی مشتریان. چطور می‌توانم کمک کنم؟',
            'camera' => 'speaker_closeup', 'gesture' => 'none', 'expression' => 'friendly',
        ],
        [
            'role' => 'caller', 'interaction' => 'speak',
            'text' => 'I am calling about my internet. It has been down since yesterday.',
            'translation_fa' => 'دربارهٔ اینترنتم تماس گرفته‌ام. از دیروز قطع است.',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
            'prompt' => 'Say why you are calling: the internet has been down since yesterday.',
            'prompt_fa' => 'بگویید چرا زنگ زده‌اید: اینترنت از دیروز قطع است.',
            'accept' => [
                'I am calling about my internet. It has been down since yesterday.',
                "I'm calling about my internet. It's been down since yesterday.",
                'I am calling because my internet has been down since yesterday.',
            ],
            'hint' => 'I am calling about … then the problem, with since for when it started.',
            'hint_fa' => 'اول I am calling about …، بعد مشکل، و با since بگویید از کی.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'I am sorry to hear that. Could I take your name and postcode?',
            'translation_fa' => 'متأسفم. می‌شود نام و کد پستی‌تان را بگویید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'caller', 'interaction' => 'recall',
            'text' => 'It is under Karimi, and the postcode is M one four B T.',
            'translation_fa' => 'به نام کریمی است، و کد پستی M۱۴BT.',
            'camera' => 'speaker_closeup',
            'prompt' => 'It is under Karimi, and the ______ is M one four B T.',
            'prompt_fa' => 'جای خالی را پر کنید: and the ______ is M one four B T.',
            'accept' => ['postcode'],
            'hint' => 'The code that tells the post office which street you are on.',
            'hint_fa' => 'کدی که نشان می‌دهد در کدام محله و خیابان هستید.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Thank you. I can see a fault on the line at your address.',
            'translation_fa' => 'ممنون. یک خرابی روی خط آدرس شما می‌بینم.',
            'camera' => 'two_person',
        ],
        [
            'role' => 'caller', 'interaction' => 'choose',
            'text' => 'Do you have any idea when it will be back on?',
            'translation_fa' => 'می‌دانید کِی دوباره وصل می‌شود؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which way of asking keeps the agent on your side?',
            'prompt_fa' => 'کدام طرز پرسیدن، طرف مقابل را با شما همراه نگه می‌دارد؟',
            'choices' => [
                ['text' => 'Do you have any idea when it will be back on?', 'text_fa' => 'می‌دانید کِی دوباره وصل می‌شود؟', 'correct' => true],
                ['text' => 'When you are fixing it for me?', 'text_fa' => 'کِی دارید برایم درستش می‌کنید؟', 'correct' => false],
                ['text' => 'Why did nobody tell me before now?', 'text_fa' => 'چرا کسی تا حالا به من نگفت؟', 'correct' => false],
            ],
            'hint' => 'Do you have any idea …? asks the same thing without blaming them.',
            'hint_fa' => 'با Do you have any idea …? همان را می‌پرسید بدون سرزنش کردن.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'An engineer is booked for tomorrow, between eight and twelve.',
            'translation_fa' => 'یک تکنسین برای فردا بین هشت تا دوازده رزرو شده.',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'caller', 'interaction' => 'speak',
            'text' => 'I am at work then. Could I have an afternoon slot instead?',
            'translation_fa' => 'آن موقع سر کارم. می‌شود به جایش یک بازهٔ بعدازظهر بدهید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'You cannot be there in the morning. Ask for an afternoon slot.',
            'prompt_fa' => 'صبح نمی‌توانید باشید. بازهٔ بعدازظهر بخواهید.',
            'accept' => [
                'I am at work then. Could I have an afternoon slot instead?',
                "I'm at work then. Could I have an afternoon slot instead?",
                'I am working then. Could I have an afternoon appointment instead?',
            ],
            'hint' => 'Give the reason first, then ask. Instead marks the swap.',
            'hint_fa' => 'اول دلیل، بعد درخواست. کلمهٔ instead جایگزینی را می‌رساند.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'Let me check. I can move it to two until six on Thursday.',
            'translation_fa' => 'بگذارید ببینم. می‌توانم پنجشنبه دو تا شش جابه‌جایش کنم.',
            'camera' => 'speaker_closeup', 'animation' => 'thinking', 'expression' => 'thinking',
        ],
        [
            'role' => 'caller', 'interaction' => 'speak',
            'text' => 'Thursday afternoon is fine, thank you.',
            'translation_fa' => 'پنجشنبه بعدازظهر خوب است، ممنون.',
            'camera' => 'speaker_closeup', 'expression' => 'friendly',
            'prompt' => 'Accept Thursday afternoon.',
            'prompt_fa' => 'پنجشنبه بعدازظهر را بپذیرید.',
            'accept' => ['Thursday afternoon is fine, thank you.', 'Thursday afternoon is fine.', 'That is fine, Thursday afternoon.'],
            'hint' => 'Repeat the day back so both of you have heard it.',
            'hint_fa' => 'روز را تکرار کنید تا هر دو طرف آن را شنیده باشند.',
        ],
        [
            'role' => 'agent', 'interaction' => 'watch',
            'text' => 'I will text you to confirm. Your reference is four nine two one.',
            'translation_fa' => 'برای تأیید پیامک می‌فرستم. شمارهٔ پیگیری‌تان چهار نُه دو یک است.',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'caller', 'interaction' => 'speak',
            'text' => 'Sorry, could you repeat the reference number?',
            'translation_fa' => 'ببخشید، می‌شود شمارهٔ پیگیری را دوباره بگویید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'You did not catch it. Ask for the reference number again.',
            'prompt_fa' => 'نگرفتیدش. بخواهید شمارهٔ پیگیری را دوباره بگوید.',
            'accept' => [
                'Sorry, could you repeat the reference number?',
                'Could you repeat the reference number, please?',
                'Sorry, could you say the reference number again?',
            ],
            'hint' => 'Asking twice costs nothing. Sorry, could you repeat …?',
            'hint_fa' => 'دوباره پرسیدن هیچ هزینه‌ای ندارد: Sorry, could you repeat …?',
        ],
    ],
];
