<?php

/**
 * A speaking exam discussion - B2.
 *
 * The second interview, on the other prompt the course carries: "A decision
 * made in your country or your field that you believe was wrong", which exists
 * as an `exam_tasks` row for IELTS part three, Cambridge part four and TOEFL
 * independent speaking. Same examiner as the other interview on purpose - a
 * candidate practising twice should be nervous about the questions, not about
 * meeting a stranger.
 *
 * What this part actually tests is whether a position survives a challenge. The
 * examiner pushes back once, and the line the learner has to produce concedes
 * the point and keeps the argument, which is the hardest move in the test and
 * the one nobody teaches.
 */
return [
    'slug' => 'exam-speaking-discussion',
    'scenario' => 'exam-interview',
    'cefr' => 'B2',
    'environment' => 'classroom',
    'light' => 1.05,
    'title' => 'Speaking exam: the discussion',
    'title_fa' => 'آزمون شفاهی: بحث',
    'situation' => 'The discussion part of a speaking exam, where each opinion has to carry a reason.',
    'situation_fa' => 'بخش بحث آزمون شفاهی، جایی که هر نظری باید دلیل داشته باشد.',
    'estimated_seconds' => 360,
    'objectives' => [
        'State a position on an abstract question',
        'Give the reasoning that was used at the time, not only your own',
        'Concede a point without losing the argument',
        'Say what should have happened instead',
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
        ['word' => 'the reasoning behind', 'fa' => 'استدلال پشتِ', 'meaning' => 'The thinking that led to a decision.'],
        ['word' => 'in hindsight', 'fa' => 'با نگاه به گذشته', 'meaning' => 'Looking back, knowing how it turned out.'],
        ['word' => 'to cancel out', 'fa' => 'خنثی کردن', 'meaning' => 'To remove the effect of something else.'],
        ['word' => 'I take that point', 'fa' => 'این را قبول دارم', 'meaning' => 'Used to accept part of what someone said.'],
        ['word' => 'to reverse a decision', 'fa' => 'لغو کردن یک تصمیم', 'meaning' => 'To undo what was decided.'],
        ['word' => 'on balance', 'fa' => 'روی هم رفته', 'meaning' => 'After weighing both sides.'],
    ],
    'beats' => [
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'In this part we will discuss a topic in more depth. Describe a decision made in your country or your field that you believe was wrong.',
            'translation_fa' => 'در این بخش موضوعی را عمیق‌تر بررسی می‌کنیم. تصمیمی در کشور یا حوزهٔ کاری‌تان را توصیف کنید که به نظرتان اشتباه بوده است.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'The decision to close the smaller railway lines was wrong, in my view.',
            'translation_fa' => 'به نظر من تصمیم به تعطیل کردن خطوط کوچک راه‌آهن اشتباه بود.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Name the decision and say it was wrong, in your view.',
            'prompt_fa' => 'تصمیم را نام ببرید و بگویید به نظر شما اشتباه بوده است.',
            'accept' => [
                'The decision to close the smaller railway lines was wrong, in my view.',
                'In my view, the decision to close the smaller railway lines was wrong.',
                'I think the decision to close the smaller railway lines was wrong.',
            ],
            'hint' => 'In my view marks it as your position rather than a fact.',
            'hint_fa' => 'با In my view مشخص می‌کنید این موضع شماست، نه یک واقعیت.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'What was the reasoning behind it at the time?',
            'translation_fa' => 'در آن زمان استدلال پشت این تصمیم چه بود؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'They were losing money, and buses were supposed to replace them.',
            'translation_fa' => 'ضرر می‌دادند، و قرار بود اتوبوس‌ها جایشان را بگیرند.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Give their reasoning, not yours: the lines lost money and buses were to replace them.',
            'prompt_fa' => 'استدلال آن‌ها را بگویید نه خودتان را: خطوط ضرر می‌دادند و قرار بود اتوبوس جایگزین شود.',
            'accept' => [
                'They were losing money, and buses were supposed to replace them.',
                'They were losing money and the buses were supposed to replace them.',
                'They lost money, and buses were meant to replace them.',
            ],
            'hint' => 'Stating the other side fairly is what makes your case credible.',
            'hint_fa' => 'منصفانه گفتنِ طرف مقابل است که استدلال شما را قابل باور می‌کند.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'And why do you think that was a mistake?',
            'translation_fa' => 'و چرا فکر می‌کنید اشتباه بود؟',
            'camera' => 'two_person',
        ],
        [
            'role' => 'candidate', 'interaction' => 'choose',
            'text' => 'Because the buses were slower, so people bought cars instead. The saving was cancelled out.',
            'translation_fa' => 'چون اتوبوس‌ها کندتر بودند و مردم به جایش ماشین خریدند. آن صرفه‌جویی خنثی شد.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which answer argues, rather than just repeats the position?',
            'prompt_fa' => 'کدام جواب استدلال می‌کند، نه اینکه فقط موضع را تکرار کند؟',
            'choices' => [
                [
                    'text' => 'Because the buses were slower, so people bought cars instead. The saving was cancelled out.',
                    'text_fa' => 'چون اتوبوس‌ها کندتر بودند و مردم به جایش ماشین خریدند. آن صرفه‌جویی خنثی شد.',
                    'correct' => true,
                ],
                ['text' => 'Because I do not like buses very much.', 'text_fa' => 'چون من اتوبوس را خیلی دوست ندارم.', 'correct' => false],
                ['text' => 'Because it was a wrong decision that they made it.', 'text_fa' => 'چون یک تصمیم غلط بود که آن را گرفتند.', 'correct' => false],
            ],
            'hint' => 'Follow the consequence through to the end: what did the saving actually buy?',
            'hint_fa' => 'پیامد را تا آخر دنبال کنید: آن صرفه‌جویی در عمل چه چیزی خرید؟',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Some would say the money had to be saved somewhere.',
            'translation_fa' => 'بعضی‌ها می‌گویند بالاخره باید از جایی صرفه‌جویی می‌شد.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'I take that point, but the cost simply moved to the roads.',
            'translation_fa' => 'این را قبول دارم، اما هزینه فقط به جاده‌ها منتقل شد.',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Concede the point, then keep your position.',
            'prompt_fa' => 'نکته را بپذیرید، اما موضعتان را حفظ کنید.',
            'accept' => [
                'I take that point, but the cost simply moved to the roads.',
                'I take that point, but the cost just moved to the roads.',
                'That is a fair point, but the cost simply moved to the roads.',
            ],
            'hint' => 'I take that point, but … is how an argument survives a challenge.',
            'hint_fa' => 'با I take that point, but … استدلال در برابر اعتراض دوام می‌آورد.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'What should have happened instead?',
            'translation_fa' => 'به جایش چه باید می‌شد؟',
            'camera' => 'listener_closeup',
        ],
        [
            'role' => 'candidate', 'interaction' => 'recall',
            'text' => 'In hindsight, they should have kept the line and cut the timetable.',
            'translation_fa' => 'با نگاه به گذشته، باید خط را نگه می‌داشتند و تعداد سرویس‌ها را کم می‌کردند.',
            'camera' => 'speaker_closeup',
            'prompt' => 'In ______, they should have kept the line and cut the timetable.',
            'prompt_fa' => 'جای خالی را پر کنید: In ______, they should have kept the line.',
            'accept' => ['hindsight'],
            'hint' => 'Judging a past decision now that you know how it turned out.',
            'hint_fa' => 'قضاوت یک تصمیم گذشته حالا که می‌دانید چه شد.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Does that kind of decision ever get reversed?',
            'translation_fa' => 'آیا چنین تصمیم‌هایی هیچ‌وقت لغو می‌شوند؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'Rarely, because reopening costs far more than staying open would have.',
            'translation_fa' => 'به‌ندرت، چون بازگشایی بسیار گران‌تر از باز نگه داشتن تمام می‌شود.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Answer with one word and a reason: rarely, because reopening costs more.',
            'prompt_fa' => 'با یک کلمه و یک دلیل جواب بدهید: به‌ندرت، چون بازگشایی گران‌تر است.',
            'accept' => [
                'Rarely, because reopening costs far more than staying open would have.',
                'Rarely, because reopening costs much more than staying open would have.',
                'Rarely, since reopening costs far more than staying open would have.',
            ],
            'hint' => 'A one-word answer is fine in part three, as long as a reason follows it.',
            'hint_fa' => 'جواب یک‌کلمه‌ای در بخش سه اشکالی ندارد، به شرطی که دلیلی پشتش بیاید.',
        ],
        [
            'role' => 'examiner', 'interaction' => 'watch',
            'text' => 'Thank you. That is the end of this part.',
            'translation_fa' => 'متشکرم. این بخش به پایان رسید.',
            'camera' => 'two_person', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
    ],
];
