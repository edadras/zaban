<?php

/**
 * A job interview - B2.
 *
 * At B2 the difficulty stops being grammar and becomes register: saying the
 * same true thing in a way that sounds considered rather than boastful or
 * apologetic. The learner's lines here are all shaped that way, including the
 * one that asks a question back, which is the part candidates forget.
 */
return [
    'slug' => 'office-job-interview',
    'scenario' => 'job-interview',
    'cefr' => 'B2',
    'environment' => 'office',
    'light' => 1.0,
    'title' => 'A job interview',
    'title_fa' => 'مصاحبهٔ شغلی',
    'situation' => 'You are being interviewed for a role you want. The manager is friendly but direct.',
    'situation_fa' => 'برای شغلی که می‌خواهید مصاحبه می‌شوید. مدیر خوش‌برخورد اما صریح است.',
    'estimated_seconds' => 330,
    'objectives' => [
        'Summarise your experience in one sentence',
        'Give a strength with evidence rather than a claim',
        'Answer a question about a weakness honestly',
        'Ask the interviewer a real question',
    ],
    'cast' => [
        [
            'role' => 'manager', 'character' => 'lena', 'name' => 'Lena',
            'name_fa' => 'لنا', 'playable' => false,
            'colour' => '#2b3550', 'skin' => '#d3a179',
            'x' => -0.9, 'z' => -0.2, 'rotation' => 0.5, 'expression' => 'neutral',
        ],
        [
            'role' => 'candidate', 'character' => null, 'voice' => '66469f5a-10db-586a-bab1-72f6ee66ba69', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#3d4a5c', 'skin' => '#b97a52',
            'x' => 0.85, 'z' => 0.1, 'rotation' => -0.5, 'expression' => 'neutral',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => 0.0, 'z' => -0.8],
        ['id' => 'monitor', 'type' => 'monitor', 'x' => -0.8, 'z' => -1.0],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.8, 'z' => 0.9],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.7, 'z' => -1.9],
    ],
    'vocabulary' => [
        ['word' => 'background', 'fa' => 'سابقه', 'meaning' => 'Your education and work history taken together.'],
        ['word' => 'take on', 'fa' => 'بر عهده گرفتن', 'meaning' => 'To accept a piece of work or responsibility.'],
        ['word' => 'hands-on', 'fa' => 'عملی', 'meaning' => 'Doing the work yourself rather than only managing it.'],
        ['word' => 'delegate', 'fa' => 'واگذار کردن', 'meaning' => 'To give part of your work to someone else.'],
        ['word' => 'notice period', 'fa' => 'مدت اطلاع قبل از ترک کار', 'meaning' => 'The time you must work after resigning.'],
        ['word' => 'get up to speed', 'fa' => 'به روال کار افتادن', 'meaning' => 'To learn enough to work effectively.'],
    ],
    'beats' => [
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'Thanks for coming in. Talk me through your background briefly.',
            'translation_fa' => 'ممنون که آمدید. کوتاه از سابقهٔ کاری‌تان بگویید.',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands', 'expression' => 'friendly',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'I studied engineering and I have spent the last four years supporting customers.',
            'translation_fa' => 'مهندسی خوانده‌ام و چهار سال گذشته را در پشتیبانی مشتریان کار کرده‌ام.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Summarise: you studied engineering and have spent four years supporting customers.',
            'prompt_fa' => 'خلاصه بگویید: مهندسی خوانده‌اید و چهار سال در پشتیبانی مشتریان کار کرده‌اید.',
            'accept' => [
                'I studied engineering and I have spent the last four years supporting customers.',
                "I studied engineering and I've spent the last four years supporting customers.",
                'I have a degree in engineering and four years of experience supporting customers.',
            ],
            'hint' => 'Finished study is past simple; the four years continue, so present perfect.',
            'hint_fa' => 'تحصیل تمام شده پس گذشتهٔ ساده؛ آن چهار سال ادامه دارد پس حال کامل.',
        ],
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'What would you say you are strongest at?',
            'translation_fa' => 'می‌گویید در چه چیزی از همه قوی‌تر هستید؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'candidate', 'interaction' => 'choose',
            'text' => 'Explaining a technical problem to someone who is not technical. I did it daily on the support desk.',
            'translation_fa' => 'توضیح یک مشکل فنی برای کسی که فنی نیست. هر روز در میز پشتیبانی همین کار را می‌کردم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which answer is a strength with evidence, not a claim?',
            'prompt_fa' => 'کدام جواب یک نقطهٔ قوت با شاهد است، نه فقط یک ادعا؟',
            'choices' => [
                [
                    'text' => 'Explaining a technical problem to someone who is not technical. I did it daily on the support desk.',
                    'text_fa' => 'توضیح یک مشکل فنی برای کسی که فنی نیست. هر روز در میز پشتیبانی همین کار را می‌کردم.',
                    'correct' => true,
                ],
                ['text' => 'I am the best communicator you will ever meet.', 'text_fa' => 'من بهترین ارتباط‌گیرنده‌ای هستم که خواهید دید.', 'correct' => false],
                ['text' => 'I think maybe I am quite good at some things, perhaps.', 'text_fa' => 'فکر کنم شاید در بعضی چیزها تا حدی خوب باشم.', 'correct' => false],
            ],
            'hint' => 'Name the skill, then say where you used it.',
            'hint_fa' => 'اول مهارت را نام ببرید، بعد بگویید کجا از آن استفاده کرده‌اید.',
        ],
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'And the other side of that? Where do you struggle?',
            'translation_fa' => 'و طرف دیگرش؟ کجا برایتان سخت است؟',
            'camera' => 'speaker_closeup',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'I take on too much myself. I am learning to delegate earlier.',
            'translation_fa' => 'زیادی کارها را خودم بر عهده می‌گیرم. دارم یاد می‌گیرم زودتر واگذار کنم.',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Admit a real weakness and say what you are doing about it.',
            'prompt_fa' => 'یک نقطه‌ضعف واقعی را بپذیرید و بگویید برایش چه می‌کنید.',
            'accept' => [
                'I take on too much myself. I am learning to delegate earlier.',
                "I take on too much myself, and I'm learning to delegate earlier.",
                'I tend to take on too much myself, so I am working on delegating sooner.',
            ],
            'hint' => 'Two parts: the weakness, then the present continuous for the fix in progress.',
            'hint_fa' => 'دو بخش: نقطه‌ضعف، بعد حال استمراری برای کاری که در حال انجامش هستید.',
        ],
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'Fair enough. The role is hands-on for the first six months. Is that a problem?',
            'translation_fa' => 'منطقی است. این شغل شش ماه اول کاملاً عملی است. مشکلی دارد؟',
            'camera' => 'two_person',
        ],
        [
            'role' => 'candidate', 'interaction' => 'recall',
            'text' => 'Not at all. That is how I would get up to speed anyway.',
            'translation_fa' => 'اصلاً. در هر حال با همین کار به روال کار می‌افتم.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Not at all. That is how I would get up to ______ anyway.',
            'prompt_fa' => 'جای خالی را پر کنید: get up to ______.',
            'accept' => ['speed'],
            'hint' => 'The phrase means to learn the job quickly: get up to s_____.',
            'hint_fa' => 'این اصطلاح یعنی سریع کار را یاد گرفتن: get up to s_____.',
        ],
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'Good. Do you have questions for me?',
            'translation_fa' => 'خوب است. سؤالی از من دارید؟',
            'camera' => 'speaker_closeup', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'candidate', 'interaction' => 'speak',
            'text' => 'How would you measure whether I had done well in the first six months?',
            'translation_fa' => 'در شش ماه اول چطور می‌سنجید که خوب کار کرده‌ام یا نه؟',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
            'prompt' => 'Ask how they would measure success in your first six months.',
            'prompt_fa' => 'بپرسید موفقیت شما در شش ماه اول را چطور می‌سنجند.',
            'accept' => [
                'How would you measure whether I had done well in the first six months?',
                'How would you measure success in the first six months?',
                'What would a successful first six months look like?',
            ],
            'hint' => 'Ask about their standard, not about your salary.',
            'hint_fa' => 'دربارهٔ معیار آن‌ها بپرسید، نه دربارهٔ حقوق.',
        ],
        [
            'role' => 'manager', 'interaction' => 'watch',
            'text' => 'That is the right question. We will be in touch this week.',
            'translation_fa' => 'همین سؤال درست است. همین هفته با شما تماس می‌گیریم.',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
        ],
    ],
];
