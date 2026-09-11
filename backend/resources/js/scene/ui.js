/**
 * Everything the learner reads and presses.
 *
 * Persian and right-to-left throughout, because that is who this is for; the
 * English line itself is shown left-to-right inside it, since a sentence a
 * learner is about to say has to look the way they will meet it in the world.
 *
 * The panel says what it knows and no more. When a line has no recording it
 * says so; when the microphone is the browser's own recogniser rather than the
 * app's pronunciation scoring, it says that too. Telling someone they were
 * scored when they were not is the one thing a language app cannot do.
 */

const FEEDBACK = {
    correct_first_try: 'درست بود، همان بار اول.',
    correct: 'درست است.',
    almost: 'نزدیک بود. یک بار دیگر، کامل‌تر.',
    missing_words: 'چند کلمه جا افتاد.',
    try_again: 'این جمله نبود. دوباره تلاش کنید.',
    revealed: 'جملهٔ درست این بود. با صدای بلند تکرارش کنید.',
    nothing_asked: '',
};

const INTERACTION_LABEL = {
    speak: 'نوبت شماست: این جمله را بگویید',
    choose: 'کدام جواب درست است؟',
    recall: 'جای خالی را پر کنید',
};

export class SceneUi {
    constructor(root, { onPlay, onReplay, onSlow, onAnswer, onFinish, onRestart }) {
        this.root = root;
        this.handlers = { onPlay, onReplay, onSlow, onAnswer, onFinish, onRestart };
        this.slow = false;
        this.subtitles = 'both';
        this.shown = { html: '', translation: '' };
        this.vocabulary = [];
        this.recogniser = null;
        this.listening = false;
        this.build();
    }

    build() {
        this.root.innerHTML = `
            <div class="scene-top">
                <div class="scene-title">
                    <h1 data-scene-title></h1>
                    <p data-scene-situation></p>
                </div>
                <div class="scene-meta">
                    <span class="scene-chip" data-scene-level></span>
                    <span class="scene-chip" data-scene-progress></span>
                </div>
            </div>

            <div class="scene-subtitles" data-subtitles hidden>
                <p class="scene-line" dir="ltr" data-line></p>
                <p class="scene-translation" data-translation></p>
            </div>

            <div class="scene-turn" data-turn hidden>
                <div class="scene-turn-head">
                    <span data-turn-label></span>
                    <span class="scene-tries" data-tries></span>
                </div>
                <p class="scene-prompt" data-prompt></p>
                <p class="scene-prompt-en" dir="ltr" data-prompt-en></p>

                <div class="scene-choices" data-choices hidden></div>

                <div class="scene-answer" data-answer hidden>
                    <button type="button" class="scene-mic" data-mic>
                        <span data-mic-label>گفتن با میکروفون</span>
                    </button>
                    <input type="text" dir="ltr" data-input placeholder="…یا اینجا بنویسید" />
                    <button type="button" class="scene-send" data-send>بررسی</button>
                </div>

                <p class="scene-hint" data-hint hidden></p>
                <p class="scene-verdict" data-verdict hidden></p>
                <p class="scene-note" data-note hidden></p>
            </div>

            <div class="scene-controls">
                <button type="button" data-play class="scene-primary">پخش</button>
                <button type="button" data-replay>تکرار این جمله</button>
                <button type="button" data-slow>آهسته</button>
                <button type="button" data-subs>زیرنویس</button>
                <button type="button" data-finish>پایان و بازخورد</button>
            </div>

            <div class="scene-vocab" data-vocab hidden></div>

            <div class="scene-debrief" data-debrief hidden>
                <div class="scene-debrief-card">
                    <h2>بازخورد</h2>
                    <div data-debrief-body></div>
                    <div class="scene-debrief-actions">
                        <button type="button" data-restart>یک بار دیگر</button>
                        <button type="button" data-close class="scene-primary">بستن</button>
                    </div>
                </div>
            </div>

            <p class="scene-error" data-error hidden></p>
        `;

        /*
         * Looked up by the attribute name rather than through `dataset`, which
         * camel-cases: `data-mic-label` arrives there as `micLabel`, and every
         * hyphenated name would silently resolve to undefined.
         */
        this.el = {};
        for (const name of [
            'line', 'translation', 'subtitles', 'turn', 'turn-label', 'tries',
            'prompt', 'prompt-en', 'choices', 'answer', 'input', 'mic', 'mic-label',
            'hint', 'verdict', 'note', 'vocab', 'error',
            'scene-title', 'scene-situation', 'scene-level', 'scene-progress',
            'debrief', 'debrief-body',
        ]) {
            const node = this.root.querySelector(`[data-${name}]`);
            if (node) this.el[name] = node;
        }

        this.bind('[data-play]', () => this.handlers.onPlay());
        this.bind('[data-replay]', () => this.handlers.onReplay());
        this.bind('[data-slow]', (button) => {
            this.slow = !this.slow;
            button.classList.toggle('is-on', this.slow);
            this.handlers.onSlow(this.slow);
        });
        this.bind('[data-subs]', () => this.cycleSubtitles());
        this.bind('[data-finish]', () => this.handlers.onFinish());
        this.bind('[data-send]', () => this.send());
        this.bind('[data-mic]', () => this.listen());
        this.bind('[data-restart]', () => this.handlers.onRestart());
        this.bind('[data-close]', () => { this.el.debrief.hidden = true; });

        this.el.input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') this.send();
        });
    }

    bind(selector, handler) {
        const node = this.root.querySelector(selector);
        if (node) node.addEventListener('click', () => handler(node));
    }

    // --------------------------------------------------------------- scene

    describe(scene, session) {
        this.vocabulary = scene.vocabulary || [];
        this.el['scene-title'].textContent = scene.title_fa || scene.title;
        this.el['scene-situation'].textContent = scene.situation_fa || scene.situation || '';
        this.el['scene-level'].textContent = scene.cefr ? `سطح ${scene.cefr}` : '';
        this.el['scene-level'].hidden = !scene.cefr;
        this.maxTries = session.max_tries || 3;

        if (this.vocabulary.length) {
            this.el.vocab.hidden = false;
            this.el.vocab.innerHTML = this.vocabulary.map((word) => `
                <span class="scene-word">
                    <b dir="ltr">${escapeHtml(word.word)}</b>
                    <i>${escapeHtml(word.fa || '')}</i>
                </span>
            `).join('');
        }
    }

    progress(done, total) {
        this.el['scene-progress'].textContent = `${toPersian(done)} از ${toPersian(total)}`;
    }

    line(beat, speakerName) {
        // Kept so the subtitle button can repaint the same line in a different
        // mode. Without it, cycling to English on a line the learner still owes
        // would reveal an empty box where the withheld sentence is not.
        this.shown = {
            html: beat.text ? `<b>${escapeHtml(speakerName || '')}:</b> ${this.highlight(beat.text)}` : '',
            translation: beat.translation_fa || '',
        };
        this.paint();
    }

    /** Show as much of the current line as the chosen subtitle mode allows. */
    paint() {
        const { html = '', translation = '' } = this.shown || {};
        this.el.subtitles.hidden = this.subtitles === 'off';
        this.el.line.innerHTML = html;
        this.el.line.hidden = this.subtitles === 'fa' || !html;
        this.el.translation.textContent = translation;
        this.el.translation.hidden = this.subtitles === 'en' || !translation;
    }

    highlight(text) {
        const words = this.vocabulary.map((v) => v.word).filter(Boolean)
            .sort((a, b) => b.length - a.length);
        if (!words.length) return escapeHtml(text);

        const pattern = new RegExp(words.map(escapeRegExp).join('|'), 'gi');
        let cursor = 0;
        let html = '';
        for (const match of text.matchAll(pattern)) {
            html += escapeHtml(text.slice(cursor, match.index));
            html += `<mark>${escapeHtml(match[0])}</mark>`;
            cursor = match.index + match[0].length;
        }
        return html + escapeHtml(text.slice(cursor));
    }

    cycleSubtitles() {
        const order = ['both', 'en', 'fa', 'off'];
        this.subtitles = order[(order.indexOf(this.subtitles) + 1) % order.length];
        this.paint();
    }

    // ---------------------------------------------------------- the turn

    /**
     * Ask for a line.
     *
     * `again` is another go at the same line after a miss, and it deliberately
     * leaves the verdict, the hint and the count of tries where they are:
     * clearing them would wipe the one thing the learner needs to read before
     * their next attempt, in the same instant it appeared.
     */
    turn(beat, again = false) {
        this.beat = beat;
        this.el.turn.hidden = false;
        this.el['turn-label'].textContent = INTERACTION_LABEL[beat.interaction] || '';
        this.el.prompt.textContent = beat.prompt_fa || '';
        this.el['prompt-en'].textContent = beat.prompt || '';
        this.el['prompt-en'].hidden = !beat.prompt;
        if (!again) {
            this.el.verdict.hidden = true;
            this.el.hint.hidden = true;
            this.tries(0);
        }
        this.el.input.value = '';

        if (beat.interaction === 'choose') {
            this.el.answer.hidden = true;
            this.el.choices.hidden = false;
            this.el.choices.innerHTML = (beat.choices || []).map((choice) => `
                <button type="button" data-choice="${choice.index}">
                    <span dir="ltr">${escapeHtml(choice.text)}</span>
                    ${choice.text_fa ? `<i>${escapeHtml(choice.text_fa)}</i>` : ''}
                </button>
            `).join('');
            for (const button of this.el.choices.querySelectorAll('[data-choice]')) {
                button.addEventListener('click', () => {
                    this.lock(true);
                    this.handlers.onAnswer({ choice: Number(button.dataset.choice) });
                });
            }
        } else {
            this.el.choices.hidden = true;
            this.el.answer.hidden = false;
            this.el.input.focus();
        }

        this.el.turn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    tries(used) {
        const left = Math.max(0, this.maxTries - used);
        this.el.tries.textContent = used === 0 ? '' : `${toPersian(left)} تلاش باقی مانده`;
    }

    send() {
        const text = this.el.input.value.trim();
        if (!text) return;
        this.lock(true);
        this.handlers.onAnswer({ text });
    }

    lock(on) {
        this.el.input.disabled = on;
        for (const button of this.root.querySelectorAll('[data-send],[data-mic],[data-choice]')) {
            button.disabled = on;
        }
    }

    verdict(result, beat) {
        this.lock(false);
        this.tries(result.tries);

        this.el.verdict.hidden = false;
        this.el.verdict.className = `scene-verdict ${result.accepted ? 'is-good' : result.revealed ? 'is-shown' : 'is-off'}`;

        let message = FEEDBACK[result.feedback] || '';
        if (result.missing && result.missing.length) {
            message += ` (${result.missing.join('، ')})`;
        }
        this.el.verdict.textContent = message;

        if (!result.accepted && !result.revealed && (beat.hint_fa || beat.hint)) {
            this.el.hint.hidden = false;
            this.el.hint.textContent = beat.hint_fa || beat.hint;
        }

        if (result.accepted || result.revealed) {
            this.el.turn.hidden = true;
        }
    }

    // ------------------------------------------------------------ speaking

    listen() {
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        if (!Recognition) {
            this.note('مرورگر شما تشخیص گفتار ندارد. جمله را بنویسید.');
            return;
        }

        if (this.listening) {
            this.recogniser?.stop();
            return;
        }

        const recogniser = new Recognition();
        recogniser.lang = 'en-GB';
        recogniser.interimResults = false;
        recogniser.maxAlternatives = 1;
        this.recogniser = recogniser;

        recogniser.onstart = () => {
            this.listening = true;
            this.el['mic-label'].textContent = 'در حال شنیدن…';
            this.el.mic.classList.add('is-live');
        };
        recogniser.onend = () => {
            this.listening = false;
            this.el['mic-label'].textContent = 'گفتن با میکروفون';
            this.el.mic.classList.remove('is-live');
        };
        recogniser.onerror = () => {
            this.note('میکروفون در دسترس نبود. جمله را بنویسید.');
        };
        recogniser.onresult = (event) => {
            const said = event.results?.[0]?.[0]?.transcript || '';
            if (!said) return;
            this.el.input.value = said;
            this.lock(true);
            this.handlers.onAnswer({ text: said });
        };

        try {
            recogniser.start();
        } catch {
            this.note('میکروفون در دسترس نبود. جمله را بنویسید.');
        }
    }

    note(text) {
        this.el.note.hidden = !text;
        this.el.note.textContent = text || '';
    }

    error(text) {
        this.el.error.hidden = !text;
        this.el.error.textContent = text || '';
    }

    playing(on) {
        const button = this.root.querySelector('[data-play]');
        if (button) button.textContent = on ? 'مکث' : 'پخش';
    }

    debrief(session) {
        const s = session.summary || {};
        const rows = [];

        if (session.score !== null && session.score !== undefined) {
            rows.push(`<p class="scene-score">${toPersian(Math.round(session.score))}٪</p>`);
        }
        rows.push(`<p>${toPersian(s.lines_cleared || 0)} جمله از ${toPersian(s.lines_asked || 0)} جملهٔ نقش شما.</p>`);

        if ((s.went_well || []).length) {
            rows.push(`<ul class="scene-good" dir="ltr">${s.went_well.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul>`);
        }
        if ((s.to_practise || []).length) {
            rows.push('<h3>برای تمرین دوباره</h3>');
            rows.push(`<ul class="scene-practise">${s.to_practise.map((p) => `
                <li><span dir="ltr">${escapeHtml(p.line)}</span>${p.translation ? `<i>${escapeHtml(p.translation)}</i>` : ''}</li>
            `).join('')}</ul>`);
        }

        this.el['debrief-body'].innerHTML = rows.join('');
        this.el.debrief.hidden = false;
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Persian digits, because a Persian interface that counts in ASCII looks borrowed. */
function toPersian(value) {
    return String(value).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
}
