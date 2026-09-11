<?php

/**
 * Catching up with a friend - A2.
 *
 * The scene with no transaction in it. Nothing is being bought, booked or
 * complained about, so the only thing holding the conversation up is the
 * learner's willingness to ask a question back - which is the single habit that
 * most changes how fluent someone sounds.
 */
return [
    'slug' => 'cafe-catching-up',
    'scenario' => 'catching-up',
    'cefr' => 'A2',
    'environment' => 'cafe',
    'light' => 0.95,
    'title' => 'Catching up with a friend',
    'title_fa' => 'گپ با یک دوست قدیمی',
    'situation' => 'You have run into a friend you have not seen for months, and you sit down for a coffee.',
    'situation_fa' => 'دوستی را که ماه‌هاست ندیده‌اید اتفاقی می‌بینید و برای قهوه می‌نشینید.',
    'estimated_seconds' => 240,
    'objectives' => [
        'Say what you have been doing recently',
        'Ask a question back instead of only answering',
        'React to news with more than one word',
        'Make a plan to meet again',
    ],
    'cast' => [
        [
            'role' => 'friend', 'character' => 'daniel', 'name' => 'Daniel',
            'name_fa' => 'دنیل', 'playable' => false,
            'colour' => '#5d4a3a', 'skin' => '#c58b60',
            'x' => -0.8, 'z' => -0.15, 'rotation' => 0.6, 'expression' => 'happy',
        ],
        [
            'role' => 'you', 'character' => null, 'voice' => '984ddbed-83d3-5388-84ce-02fe6c24befa', 'name' => 'You',
            'name_fa' => 'شما', 'playable' => true,
            'colour' => '#3f7f6f', 'skin' => '#b97a52',
            'x' => 0.8, 'z' => 0.1, 'rotation' => -0.6, 'expression' => 'happy',
        ],
    ],
    'props' => [
        ['id' => 'desk', 'type' => 'desk', 'x' => 0.0, 'z' => -0.55],
        ['id' => 'chair', 'type' => 'chair', 'x' => 1.5, 'z' => 0.8],
        ['id' => 'plant', 'type' => 'plant', 'x' => 2.5, 'z' => -1.7],
    ],
    'vocabulary' => [
        ['word' => 'to be up to', 'fa' => 'مشغول چه کاری بودن', 'meaning' => 'To be doing something, especially recently.'],
        ['word' => 'ages', 'fa' => 'مدت خیلی طولانی', 'meaning' => 'A very long time.'],
        ['word' => 'move house', 'fa' => 'اسباب‌کشی کردن', 'meaning' => 'To go and live somewhere else.'],
        ['word' => 'settle in', 'fa' => 'جا افتادن', 'meaning' => 'To get used to a new place.'],
        ['word' => 'congratulations', 'fa' => 'تبریک', 'meaning' => 'What you say about someone\'s good news.'],
        ['word' => 'get together', 'fa' => 'دور هم جمع شدن', 'meaning' => 'To meet socially.'],
    ],
    'beats' => [
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'I do not believe it. I have not seen you for ages!',
            'translation_fa' => 'باورم نمی‌شود. خیلی وقت است ندیدمت!',
            'camera' => 'speaker_closeup', 'gesture' => 'greeting', 'expression' => 'surprised',
        ],
        [
            'role' => 'you', 'interaction' => 'speak',
            'text' => 'I know! How have you been?',
            'translation_fa' => 'می‌دانم! حالت چطور بوده؟',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
            'prompt' => 'Greet them back and ask how they have been.',
            'prompt_fa' => 'جواب سلامش را بدهید و بپرسید حالش چطور بوده.',
            'accept' => ['I know! How have you been?', 'How have you been?', 'I know, it has been ages! How have you been?'],
            'hint' => 'How have you been? asks about the whole time since you last met.',
            'hint_fa' => 'با How have you been? دربارهٔ تمام مدتی که ندیده‌اید می‌پرسید.',
        ],
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'Busy, actually. We moved house in June, out to the coast.',
            'translation_fa' => 'راستش سرم شلوغ بوده. خرداد اسباب‌کشی کردیم، رفتیم کنار دریا.',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
        ],
        [
            'role' => 'you', 'interaction' => 'choose',
            'text' => 'The coast! How are you settling in?',
            'translation_fa' => 'کنار دریا! دارید جا می‌افتید؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'Which reply keeps them talking?',
            'prompt_fa' => 'کدام جواب باعث می‌شود او به صحبت ادامه بدهد؟',
            'choices' => [
                ['text' => 'The coast! How are you settling in?', 'text_fa' => 'کنار دریا! دارید جا می‌افتید؟', 'correct' => true],
                ['text' => 'Oh. Okay.', 'text_fa' => 'آهان. باشد.', 'correct' => false],
                ['text' => 'I moved house also in June too as well.', 'text_fa' => 'من هم خرداد هم اسباب‌کشی کردم هم همین‌طور.', 'correct' => false],
            ],
            'hint' => 'React, then ask a question about what they just said.',
            'hint_fa' => 'اول واکنش نشان بدهید، بعد دربارهٔ همان حرف سؤال کنید.',
        ],
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'Slowly. The house is lovely but the commute is an hour each way.',
            'translation_fa' => 'کم‌کم. خانه قشنگ است اما رفت‌وآمد هر طرف یک ساعت است.',
            'camera' => 'speaker_closeup', 'expression' => 'thinking',
        ],
        [
            'role' => 'you', 'interaction' => 'speak',
            'text' => 'An hour each way sounds exhausting. What have you been doing at work?',
            'translation_fa' => 'هر طرف یک ساعت خیلی خسته‌کننده است. سر کار چه خبر بوده؟',
            'camera' => 'speaker_closeup',
            'prompt' => 'React to the commute, then ask about their work.',
            'prompt_fa' => 'به رفت‌وآمد واکنش نشان بدهید و بعد از کارش بپرسید.',
            'accept' => [
                'An hour each way sounds exhausting. What have you been doing at work?',
                'That sounds exhausting. What have you been doing at work?',
                'An hour each way sounds tiring. How is work going?',
            ],
            'hint' => 'That sounds … is the easiest way to react before asking.',
            'hint_fa' => 'با That sounds … راحت‌ترین راه واکنش پیش از پرسیدن است.',
        ],
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'I changed jobs in September. I am teaching now, believe it or not.',
            'translation_fa' => 'شهریور شغلم را عوض کردم. باور کن یا نه، الان معلمم.',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
        ],
        [
            'role' => 'you', 'interaction' => 'recall',
            'text' => 'Congratulations! How is it going so far?',
            'translation_fa' => 'تبریک می‌گویم! تا الان چطور پیش می‌رود؟',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
            'prompt' => '______! How is it going so far?',
            'prompt_fa' => 'جای خالی را پر کنید: ______! How is it going so far?',
            'accept' => ['congratulations'],
            'hint' => 'One word for good news about someone else.',
            'hint_fa' => 'یک کلمه برای خبر خوب دیگران.',
        ],
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'Hard, but I like it. And you? What have you been up to?',
            'translation_fa' => 'سخت است، اما دوستش دارم. تو چطور؟ مشغول چه کارهایی بودی؟',
            'camera' => 'two_person', 'gesture' => 'open_hands',
        ],
        [
            'role' => 'you', 'interaction' => 'speak',
            'text' => 'I have been studying English in the evenings, twice a week.',
            'translation_fa' => 'شب‌ها انگلیسی می‌خوانم، هفته‌ای دو بار.',
            'camera' => 'speaker_closeup',
            'prompt' => 'Say you have been studying English in the evenings, twice a week.',
            'prompt_fa' => 'بگویید شب‌ها هفته‌ای دو بار انگلیسی می‌خوانید.',
            'accept' => [
                'I have been studying English in the evenings, twice a week.',
                "I've been studying English in the evenings, twice a week.",
                'I have been learning English twice a week in the evenings.',
            ],
            'hint' => 'Something you started and are still doing: have been studying.',
            'hint_fa' => 'کاری که شروع کرده‌اید و هنوز ادامه دارد: have been studying.',
        ],
        [
            'role' => 'friend', 'interaction' => 'watch',
            'text' => 'That is great. We should get together properly. Are you free on Saturday?',
            'translation_fa' => 'عالی است. باید حسابی دور هم جمع شویم. شنبه وقت داری؟',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
        ],
        [
            'role' => 'you', 'interaction' => 'speak',
            'text' => 'Saturday works for me. Shall we meet here at four?',
            'translation_fa' => 'شنبه برایم خوب است. ساعت چهار همین‌جا ببینیمت؟',
            'camera' => 'speaker_closeup', 'expression' => 'happy',
            'prompt' => 'Accept, and suggest meeting here at four.',
            'prompt_fa' => 'قبول کنید و پیشنهاد بدهید ساعت چهار همین‌جا ببینید.',
            'accept' => [
                'Saturday works for me. Shall we meet here at four?',
                'Saturday is good for me. Shall we meet here at four?',
                'Yes, Saturday is fine. Shall we meet here at four?',
            ],
            'hint' => 'Shall we …? is how a suggestion is made in English.',
            'hint_fa' => 'پیشنهاد دادن در انگلیسی با Shall we …? انجام می‌شود.',
        ],
    ],
];
