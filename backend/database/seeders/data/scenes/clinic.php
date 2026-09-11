<?php

/**
 * At the doctor's - B1.
 *
 * The language a person actually needs in a surgery: saying how long something
 * has been going on, answering a closed question properly, and asking what to
 * do about it. The beat the learner is made to produce first is the present
 * perfect with `since`, because that is the sentence every one of these visits
 * turns on and the one learners reach for and miss.
 */
return [
    'slug' => 'clinic-sore-throat',
    'scenario' => 'doctor-appointment',
    'cefr' => 'B1',
    'environment' => 'clinic',
    'light' => 1.0,
    'title' => 'At the doctor: a sore throat',
    'title_fa' => 'مطب دکتر: گلودرد',
    'situation' => 'You have had a sore throat for several days and you have come to see the doctor.',
    'situation_fa' => 'چند روزی است گلودرد دارید و برای ویزیت به مطب آمده‌اید.',
    'estimated_seconds' => 300,
    'objectives' => [
        'Say how long a symptom has lasted',
        'Answer a yes/no question about pain naturally',
        'Ask what the doctor recommends',
        'Ask whether you should stay off work',
    ],
    'cast' => [
        [
            'role' => 'doctor', 'character' => 'aiko', 'name' => 'Dr Aiko',
            'name_fa' => 'دکتر آیکو', 'playable' => false,
            'colour' => '#f2f4f7', 'skin' => '#c98b62',
            'x' => -0.85, 'z' => -0.1, 'rotation' => 0.55, 'expression' => 'friendly',
        ],
        [
            'role' => 'patient', 'character' => null, 'voice' => '3c9d6053-6334-592c-8997-4e325286af3f', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#5b7cc9', 'skin' => '#b97a52',
            'x' => 0.8, 'z' => 0.05, 'rotation' => -0.55, 'expression' => 'sick',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -1.85, 'z' => -1.5],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -2.2, 'z' => -1.35],
        ['id' => 'exam', 'type' => 'exam', 'x' => 1.6, 'z' => -2.0],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.7, 'z' => 0.7],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.8, 'z' => -2.0],
    ],
    'vocabulary' => [
        ['word' => 'sore throat', 'fa' => 'گلودرد', 'meaning' => 'Pain in the throat, especially when you swallow.'],
        ['word' => 'swallow', 'fa' => 'قورت دادن', 'meaning' => 'To make food or drink go down your throat.'],
        ['word' => 'temperature', 'fa' => 'تب', 'meaning' => 'A body heat higher than normal; a fever.'],
        ['word' => 'ache', 'fa' => 'درد کردن', 'meaning' => 'To hurt with a dull, continuous pain.'],
        ['word' => 'virus', 'fa' => 'ویروس', 'meaning' => 'A very small living thing that causes illness.'],
        ['word' => 'antibiotics', 'fa' => 'آنتی‌بیوتیک', 'meaning' => 'Medicine that kills bacteria, not viruses.'],
        ['word' => 'recommend', 'fa' => 'توصیه کردن', 'meaning' => 'To say what you think someone should do.'],
        ['word' => 'stay off work', 'fa' => 'سر کار نرفتن', 'meaning' => 'Not to go to work, usually because you are ill.'],
    ],
    'beats' => [
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'Good morning. Come in and sit down. What has brought you in today?',
            'translation_fa' => 'صبح بخیر. بفرمایید بنشینید. امروز چه مشکلی پیش آمده؟',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
        [
            'role' => 'patient', 'interaction' => 'speak',
            'text' => "I've had a sore throat since Monday.",
            'translation_fa' => 'از دوشنبه گلودرد دارم.',
            'camera' => 'speaker_closeup', 'expression' => 'sick',
            'prompt' => 'Tell the doctor you have had a sore throat since Monday.',
            'prompt_fa' => 'به دکتر بگویید از دوشنبه گلودرد دارید.',
            'accept' => [
                'I have had a sore throat since Monday.',
                'My throat has been sore since Monday.',
                "I've had a sore throat since Monday morning.",
            ],
            'hint' => 'How long it started ago matters: use have had … since.',
            'hint_fa' => 'مدت زمان مهم است: از ساختار have had … since استفاده کنید.',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'Since Monday, so that is four days. Does it hurt when you swallow?',
            'translation_fa' => 'از دوشنبه، یعنی چهار روز. وقتی قورت می‌دهید درد می‌گیرد؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'patient', 'interaction' => 'choose',
            'text' => 'Yes, it really hurts when I swallow.',
            'translation_fa' => 'بله، وقتی قورت می‌دهم واقعاً درد می‌گیرد.',
            'camera' => 'speaker_closeup', 'expression' => 'sick',
            'prompt' => 'Which reply is natural English?',
            'prompt_fa' => 'کدام جواب انگلیسیِ طبیعی است؟',
            'choices' => [
                ['text' => 'Yes, it really hurts when I swallow.', 'text_fa' => 'بله، وقتی قورت می‌دهم واقعاً درد می‌گیرد.', 'correct' => true],
                ['text' => 'Yes, I am swallowing it with pain.', 'text_fa' => 'بله، دارم آن را با درد قورت می‌دهم.', 'correct' => false],
                ['text' => 'Yes, it hurt me for swallow.', 'text_fa' => 'بله، برای قورت دادن مرا درد کرد.', 'correct' => false],
            ],
            'hint' => 'Answer the question that was asked: when do you feel it?',
            'hint_fa' => 'به همان سؤال جواب بدهید: چه وقت درد را حس می‌کنید؟',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'I see. Have you had a temperature, or any aching?',
            'translation_fa' => 'که این‌طور. تب داشته‌اید؟ بدن‌درد چطور؟',
            'camera' => 'two_person', 'expression' => 'neutral',
        ],
        [
            'role' => 'patient', 'interaction' => 'speak',
            'text' => 'I had a temperature last night and I ache all over.',
            'translation_fa' => 'دیشب تب داشتم و تمام بدنم درد می‌کند.',
            'camera' => 'speaker_closeup', 'expression' => 'sick',
            'prompt' => 'Say you had a temperature last night and you ache all over.',
            'prompt_fa' => 'بگویید دیشب تب داشتید و تمام بدنتان درد می‌کند.',
            'accept' => [
                'I had a temperature last night and I ache all over.',
                'I had a fever last night and I ache all over.',
                'Last night I had a temperature and my whole body aches.',
            ],
            'hint' => 'Last night is finished, so the first verb is a past simple.',
            'hint_fa' => 'دیشب تمام شده است، پس فعل اول گذشتهٔ ساده می‌شود.',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'Let me have a look at your throat. Open wide, please.',
            'translation_fa' => 'بگذارید گلویتان را ببینم. لطفاً دهانتان را باز کنید.',
            'camera' => 'over_shoulder', 'animation' => 'standing', 'gesture' => 'pointing', 'expression' => 'neutral',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'It is quite red, but this is a virus. Antibiotics would not help.',
            'translation_fa' => 'کمی قرمز است، اما ویروسی است. آنتی‌بیوتیک فایده‌ای ندارد.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'neutral',
        ],
        [
            'role' => 'patient', 'interaction' => 'recall',
            'text' => 'So what do you recommend I take?',
            'translation_fa' => 'پس توصیه می‌کنید چه چیزی مصرف کنم؟',
            'camera' => 'speaker_closeup', 'expression' => 'confused',
            'prompt' => 'So what do you ______ I take?',
            'prompt_fa' => 'جای خالی را پر کنید: So what do you ______ I take؟',
            'accept' => ['recommend'],
            'hint' => 'The doctor says what you should do: they r______ it.',
            'hint_fa' => 'دکتر می‌گوید چه کنید، یعنی آن را r______ می‌کند.',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'Rest, plenty of water, and paracetamol for the pain.',
            'translation_fa' => 'استراحت، آب فراوان، و برای درد استامینوفن.',
            'camera' => 'speaker_closeup', 'gesture' => 'pointing', 'expression' => 'friendly',
        ],
        [
            'role' => 'patient', 'interaction' => 'speak',
            'text' => 'Should I stay off work this week?',
            'translation_fa' => 'این هفته باید سر کار نروم؟',
            'camera' => 'speaker_closeup', 'expression' => 'worried',
            'prompt' => 'Ask whether you should stay off work this week.',
            'prompt_fa' => 'بپرسید که آیا این هفته باید سر کار نروید.',
            'accept' => [
                'Should I stay off work this week?',
                'Do I need to stay off work this week?',
                'Should I take this week off work?',
            ],
            'hint' => 'Asking for advice starts with Should I …?',
            'hint_fa' => 'برای گرفتن نظر، جمله را با Should I …? شروع کنید.',
        ],
        [
            'role' => 'doctor', 'interaction' => 'watch',
            'text' => 'A couple of days, while you still have a temperature. Come back on Monday if you are no better.',
            'translation_fa' => 'تا وقتی تب دارید، دو روزی. اگر دوشنبه بهتر نشدید دوباره بیایید.',
            'camera' => 'two_person', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
    ],
];
