<?php

/**
 * A speaking exam interview - B2.
 *
 * Written against the exam tasks the course already carries rather than around
 * them: the long turn here is "A journey you remember" and the discussion is
 * "Learning at any age", both of which exist as `exam_tasks` rows for IELTS
 * Academic and Cambridge B2 First. A candidate who plays this scene has
 * rehearsed the real prompt, not a paraphrase of it.
 *
 * The shape of the test is the lesson. Part one wants short, complete answers;
 * part two wants a minute of uninterrupted speech with a beginning; part three
 * wants an opinion with a reason attached. Most candidates lose marks by
 * answering part three in the register of part one, which is what the choice
 * beat is about.
 */
return [
    'slug' => 'exam-speaking-interview',
    'scenario' => 'exam-interview',
    'cefr' => 'B2',
    'environment' => 'classroom',
    'light' => 1.05,
    'title' => 'Speaking exam: the interview',
    'title_fa' => 'آزمون شفاهی: مصاحبه',
    'situation' => 'A formal spoken interview in the style of an international exam, in three parts.',
    'situation_fa' => 'یک مصاحبهٔ شفاهی رسمی به سبک آزمون‌های بین‌المللی، در سه بخش.',
    'estimated_seconds' => 360,
    'objectives' => [
        'Answer a personal question in a full sentence, not one word',
        'Ask for what you need before the long turn starts',
        'Open a long turn with a sentence that says what is coming',
        'Answer an abstract question with a reason attached',
    ],
    'cast' => [
        [
            'role' => 'examiner', 'character' => 'omar', 'name' => 'Omar',
            'name_fa' => 'ممتحن', 'playable' => false,
            'colour' => '#4a3f2f', 'skin' => '#8e5a34',
            'x' => -0.85, 'z' => -0.1, 'rotation' => 0.55, 'expression' => 'neutral',
        ],
        [
            'role' => 'candidate', 'character' => null,
            'voice' => '7a6845a2-5865-5669-a0ca-8fc8d8e96528',
            'name' => 'You', 'name_fa' => 'شما', 'playable' => true,
            'colour' => '#40628f', 'skin' => '#c68642',
            'x' => 0.85, 'z' => 0.05, 'rotation' => -0.55, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => -0.2, 'z' => -0.9],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.6, 'z' => 0.8],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -1.0, 'z' => -1.1],
    ],
    'vocabulary' => [
        ['word' => 'to make notes', 'fa' => 'یادداشت برداشتن', 'meaning' => 'To write down short reminders before speaking.'],
        ['word' => 'a long turn', 'fa' => 'صحبت طولانی', 'meaning' => 'The part where you speak alone for one or two minutes.'],
        ['word' => 'within walking distance', 'fa' => 'در فاصلهٔ پیاده‌روی', 'meaning' => 'Close enough to walk to.'],
        ['word' => 'on the whole', 'fa' => 'روی هم رفته', 'meaning' => 'Considering everything; generally.'],
        ['word' => 'to put it another way', 'fa' => 'به بیان دیگر', 'meaning' => 'Used to say the same idea differently.'],
        ['word' => 'in general', 'fa' => 'به طور کلی', 'meaning' => 'Not about one case but about most of them.'],
    ],
    'beats' => [
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Good morning. This is the speaking test. First, let us talk about where you live.',
            'translation_fa' => 'صبح بخیر. این آزمون شفاهی است. اول دربارهٔ جایی که زندگی می‌کنید صحبت کنیم.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'I live in a flat near the centre of the city.',
            'translation_fa' => 'در آپارتمانی نزدیک مرکز شهر زندگی می‌کنم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Say you live in a flat near the city centre. A full sentence, not one word.',
            'prompt_fa' => 'بگویید در آپارتمانی نزدیک مرکز شهر زندگی می‌کنید. یک جملهٔ کامل، نه یک کلمه.',
            'accept' => [
                'I live in a flat near the centre of the city.',
                'I live in a flat near the city centre.',
                'I live in an apartment near the centre of the city.',
            ],
            'hint' => 'Part one answers are short but whole: subject, verb, and a detail.',
            'hint_fa' => 'جواب‌های بخش اول کوتاه‌اند اما کامل: فاعل، فعل، و یک جزئیات.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'And what do you like about living there?',
            'translation_fa' => 'و چه چیزی از زندگی در آنجا را دوست دارید؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'Everything is within walking distance, so I hardly use the car.',
            'translation_fa' => 'همه چیز در فاصلهٔ پیاده‌روی است، برای همین تقریباً از ماشین استفاده نمی‌کنم.',
            'camera' => 'speaker_closeup', 'expression' => 'friendly',
            'prompt' => 'Give the reason, and add what follows from it.',
            'prompt_fa' => 'دلیل را بگویید و نتیجه‌ای که از آن می‌آید را هم اضافه کنید.',
            'accept' => [
                'Everything is within walking distance, so I hardly use the car.',
                'Everything is within walking distance, so I rarely use the car.',
                'Everything is close enough to walk to, so I hardly use the car.',
            ],
            'hint' => 'One reason plus so … turns a short answer into a scoring one.',
            'hint_fa' => 'یک دلیل به‌علاوهٔ so … جواب کوتاه را به جواب نمره‌آور تبدیل می‌کند.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Thank you. Now I will give you a topic and I would like you to talk about it for one to two minutes. You have one minute to make notes.',
            'translation_fa' => 'ممنون. حالا موضوعی می‌دهم که یک تا دو دقیقه دربارهٔ آن صحبت کنید. یک دقیقه وقت دارید یادداشت بردارید.',
            'camera' => 'two_person', 'gesture' => 'pointing',
        ],
        [
            'role' => 'candidate', 'interaction' => 'recall',
            'text' => 'Could I have a pen and some paper, please?',
            'translation_fa' => 'می‌شود یک خودکار و کمی کاغذ بدهید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Could I have a pen and some ______, please?',
            'prompt_fa' => 'جای خالی را پر کنید: a pen and some ______.',
            'accept' => ['paper'],
            'hint' => 'What you make the notes on.',
            'hint_fa' => 'چیزی که روی آن یادداشت می‌نویسید.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Here you are. Describe a journey you remember well. Say where you went, how you travelled, and why it stayed with you.',
            'translation_fa' => 'بفرمایید. سفری را که خوب به یاد دارید توصیف کنید: کجا رفتید، چطور سفر کردید، و چرا در خاطرتان مانده.',
            'camera' => 'speaker_closeup', 'expression' => 'neutral',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'I would like to talk about a train journey I took to the north last summer.',
            'translation_fa' => 'می‌خواهم دربارهٔ سفری با قطار به شمال صحبت کنم که تابستان گذشته رفتم.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
            'prompt' => 'Open the long turn: say what you are going to talk about.',
            'prompt_fa' => 'صحبت طولانی را شروع کنید: بگویید قرار است دربارهٔ چه صحبت کنید.',
            'accept' => [
                'I would like to talk about a train journey I took to the north last summer.',
                "I'd like to talk about a train journey I took to the north last summer.",
                'I am going to talk about a train journey I took to the north last summer.',
            ],
            'hint' => 'Name the subject in the first sentence, then the details have somewhere to go.',
            'hint_fa' => 'موضوع را در جملهٔ اول نام ببرید تا جزئیات جایی برای نشستن داشته باشند.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Thank you. Let us talk more generally about learning. Some people say it is too late to learn a new skill after a certain age. What do you think?',
            'translation_fa' => 'ممنون. حالا کلی‌تر دربارهٔ یادگیری صحبت کنیم. بعضی‌ها می‌گویند بعد از سنی خاص برای یادگیری مهارت تازه دیر است. نظر شما چیست؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'candidate', 'interaction' => 'choose',
            'text' => 'I do not agree. Older learners often have more patience, and that matters more than speed.',
            'translation_fa' => 'موافق نیستم. یادگیرندگان مسن‌تر معمولاً صبر بیشتری دارند، و این از سرعت مهم‌تر است.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which answer belongs in part three rather than part one?',
            'prompt_fa' => 'کدام جواب به بخش سه می‌خورد، نه به بخش یک؟',
            'choices' => [
                [
                    'text' => 'I do not agree. Older learners often have more patience, and that matters more than speed.',
                    'text_fa' => 'موافق نیستم. یادگیرندگان مسن‌تر معمولاً صبر بیشتری دارند، و این از سرعت مهم‌تر است.',
                    'correct' => true,
                ],
                ['text' => 'Yes, it is too late. Thank you.', 'text_fa' => 'بله، دیر است. ممنون.', 'correct' => false],
                ['text' => 'I like learning. It is very good and interesting for everyone.', 'text_fa' => 'من یادگیری را دوست دارم. برای همه خیلی خوب و جالب است.', 'correct' => false],
            ],
            'hint' => 'Part three wants a position and a reason, not a preference.',
            'hint_fa' => 'بخش سه موضع و دلیل می‌خواهد، نه بیان علاقه.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'And why do you think people believe it gets harder?',
            'translation_fa' => 'و به نظرتان چرا مردم فکر می‌کنند سخت‌تر می‌شود؟',
            'camera' => 'listener_closeup',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'Mainly because adults compare themselves with children, who are not afraid of making mistakes.',
            'translation_fa' => 'بیشتر به این دلیل که بزرگسالان خودشان را با بچه‌ها مقایسه می‌کنند، که از اشتباه کردن نمی‌ترسند.',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Explain the belief: adults compare themselves with children, who do not fear mistakes.',
            'prompt_fa' => 'این باور را توضیح بدهید: بزرگسالان خود را با بچه‌ها مقایسه می‌کنند که از اشتباه نمی‌ترسند.',
            'accept' => [
                'Mainly because adults compare themselves with children, who are not afraid of making mistakes.',
                'Because adults compare themselves with children, who are not afraid of making mistakes.',
                'Mainly because adults compare themselves to children, who are not afraid to make mistakes.',
            ],
            'hint' => 'Mainly because … answers why without starting the sentence again.',
            'hint_fa' => 'با Mainly because … به چرایی جواب می‌دهید بدون اینکه جمله را از نو شروع کنید.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Thank you. That is the end of the speaking test.',
            'translation_fa' => 'متشکرم. آزمون شفاهی به پایان رسید.',
            'camera' => 'two_person', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
    ],
];
