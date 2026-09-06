#!/usr/bin/env python3
"""
Read the Advanced Grammar in Use disc as exercises rather than as pages.

Every other source in this project is a printed book, and a printed exercise
imports as one row per instruction: the numbered parts live on the page as
typography, and the answers live sixty pages away as a run of prose. That is
why the readiness report has been stuck on the same line for months - *answers
exist as raw answer-key prose, not per-blank values* - and why the books'
exercises cannot be served to a learner as they stand.

This disc is the exception. It is the same book's exercises published as data:
two hundred files, one per exercise, each carrying its rubric, its items, which
blank takes which answer, the wrong form and the corrected form for the
correction drills, the options for the choice drills, the word bank, and a
recording of nearly every item. Nothing has to be inferred from a page.

Five item shapes come out of it, and they are kept apart rather than flattened,
because each one asks the learner to do something different:

  fill_blank        "It [fits] me perfectly."          - type the missing words
  choice            "To work in his garden [^was|were]" - pick between forms
  multiple_choice   "I ...... you all" + answers        - pick from a list
  correction        was: "Amy is telling me"            - find and fix the error
                    is:  "Amy tells me"
  open              a model answer with no single blank - compared, not marked

An item whose answer cannot be read is not written out with a guess in it. It
is counted and reported, because an exercise that marks a right answer wrong
teaches the learner that they were wrong.
"""
import argparse
import html
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Files on the disc that are not exercises: the unit summary pages, the help
# system, the test generator and the acknowledgements.
NOT_AN_EXERCISE = re.compile(r'^(sgpage|help|my_tests|ackowledgements|acknowledgements)', re.I)

# `0151te_correx.xml` - unit 15, exercise 1, and the shape of the exercise.
FILENAME = re.compile(r'^(\d{3})(\d)(.+)$')

KINDS = {
    'tesp': 'fill_blank',
    'te': 'fill_blank',
    'dd': 'fill_blank',
    'scd': 'choice',
    'scdd': 'choice',
    'sc_dd': 'choice',
    'mc': 'multiple_choice',
    'te_correx': 'correction',
    'tecorrex': 'correction',
}


def plain(markup: str) -> str:
    """Readable text out of the disc's inline HTML.

    Unescaping comes before stripping, and repeats. The disc escapes its inline
    markup once for the field it sits in and sometimes again for the document
    that field sits in, so a stem arrives as `&amp;lt;b&amp;gt;`. Strip first
    and the tags are still text, and the item reads "<b>Example 1</b>".
    """
    text = markup or ''
    for _ in range(3):
        after = html.unescape(text)
        if after == text:
            break
        text = after
    text = re.sub(r'<br\s*/?>', ' ', text)
    text = re.sub(r'<[^>]+>', '', text)
    text = text.replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', text).strip()


def unwrap(raw: str) -> str:
    """Undo however many layers of escaping this file happens to carry.

    The disc stores XML inside XML: the exercise definition is a document, and
    the questions inside it are an escaped document of their own - in some
    files escaped twice. Rather than guess the depth, unescape until the tags
    appear and stop there.
    """
    body = raw
    for _ in range(3):
        if re.search(r'<question[\s>]', body) and '&lt;question' not in body:
            break
        body = html.unescape(body)
    return body


def first(tag: str, body: str, default: str = '') -> str:
    m = re.search(rf'<{tag}>(.*?)</{tag}>', body, re.S)
    return m.group(1) if m else default


def blanks_in(text: str):
    """Read the bracket notation the disc marks its blanks with.

    Three forms appear, and they mean different things:

        [fits]                  one right answer, typed
        [^was|were]             a choice, the caret on the right one
        [ever since ...|x]      a choice whose alternatives are the other
                                items' answers, so only the right one is
                                written here; "x" is the disc's placeholder

    @return (stem with the blanks replaced by a rule, list of blanks)
    """
    found = []
    out = []
    cursor = 0

    for m in re.finditer(r'\[([^\[\]]*)\]', text):
        out.append(text[cursor:m.start()])
        cursor = m.end()

        body = m.group(1).strip()
        if not body:
            continue

        parts = [p.strip() for p in body.split('|')]
        options = [p for p in parts if p and p != 'x']
        marked = [p[1:].strip() for p in parts if p.startswith('^')]
        options = [p[1:].strip() if p.startswith('^') else p for p in options]

        if marked:
            answers = marked
        elif len(options) == 1:
            answers = options
        else:
            # Several alternatives and no caret. Sometimes that is because
            # both are right - the model answer then reads "am usually
            # working / usually work" - and sometimes the disc simply did not
            # say. Which of the two it is cannot be decided from the stem, so
            # it is settled against the model answer by the caller.
            answers = []

        found.append({
            'index': len(found),
            'answers': answers,
            'options': options if len(options) > 1 else [],
        })
        out.append('______')

    out.append(text[cursor:])
    return ''.join(out), found


def present(option: str, model: str) -> bool:
    """Is this alternative one of the answers the model sentence gives?"""
    return option.strip().lower() in model.lower()


def read_question(block: str, kind: str):
    """One item, in whichever shape its exercise uses."""
    audio = plain(first('audioURL', block) or first('audio_url', block)) or None
    model = plain(first('modelAnswerString', block)) or None
    is_example = first('isExample', block).strip().lower() == 'true'

    start = first('start_text', block)
    correct = first('correct_text', block)
    if start or correct:
        return {
            'shape': 'correction',
            'stem': plain(start),
            'answer': plain(correct) or None,
            'model_answer': model,
            'audio': audio,
            'is_example': is_example,
        }

    raw_text = first('text', block)
    stem_markup = raw_text

    answers = re.findall(r'<answer>(.*?)</answer>', block, re.S)
    if answers:
        options = []
        correct_options = []
        for a in answers:
            label = plain(first('text', a))
            if not label:
                continue
            options.append(label)
            if first('isCorrect', a).strip().lower() == 'true':
                correct_options.append(label)
        return {
            'shape': 'multiple_choice',
            'stem': plain(stem_markup),
            'options': options,
            'answers': correct_options,
            'multi_answer': 'isMultiAnswer="true"' in block,
            'model_answer': model,
            'audio': audio,
            'is_example': is_example,
        }

    stem, blanks = blanks_in(stem_markup)

    resolved = []
    for b in blanks:
        options = [plain(o) for o in b['options']]
        answers = [plain(a) for a in b['answers'] if a]
        if not answers and options:
            # An unmarked choice whose alternatives all appear in the model
            # answer is a question with more than one right answer, not a
            # question missing its answer: "am usually working / usually work".
            if model and all(present(o, model) for o in options):
                answers = options
        resolved.append({'index': b['index'], 'answers': answers, 'options': options})

    return {
        'shape': 'choice' if any(b['options'] for b in resolved) else 'fill_blank',
        'stem': plain(stem),
        'blanks': resolved,
        'model_answer': model,
        'audio': audio,
        'is_example': is_example,
    }


# Items that are only a label. Each exercise opens with two worked examples,
# and the disc lays them out as a heading block of their own followed by the
# sentence; the heading block arrives here as an item with nothing in it but
# the words "Example 1".
LABEL_ONLY = re.compile(r'^(?:example|question)\s*\d*[.:]?$', re.I)


def carries_content(item) -> bool:
    stem = (item.get('stem') or '').strip()
    if item.get('model_answer') or item.get('answer') or item.get('options'):
        return True
    if item.get('blanks'):
        return True
    return bool(stem) and not LABEL_ONLY.match(stem)


def read_exercise(path: Path):
    raw = path.read_text(encoding='utf-8', errors='replace')
    body = unwrap(raw)

    m = FILENAME.match(path.stem)
    if not m:
        return None
    unit, number, suffix = int(m.group(1)), int(m.group(2)), m.group(3)
    kind = KINDS.get(suffix, 'fill_blank')

    heading = plain(first('at_heading', body))
    # Every interaction on the page repeats `at_heading` with its own widget
    # name ("Text Edit Correction"); the first is the exercise's own, which is
    # the unit title.
    rubric = ''
    for candidate in re.findall(r'<instruction_str>(.*?)</instruction_str>', body, re.S):
        text = plain(candidate)
        if len(text) > len(rubric):
            rubric = text

    tokens = [plain(t) for t in re.findall(r'<token>(.*?)</token>', body, re.S)]
    tokens = [t for t in tokens if t and not t.startswith('[')]

    items = []
    for group in re.findall(r'<question_xml>(.*?)</question_xml>', body, re.S):
        for block in re.findall(r'<question\b[^>]*>(?:(?!</question>).)*</question>', group, re.S):
            item = read_question(block, kind)
            if not carries_content(item):
                continue
            item['position'] = len(items)
            items.append(item)

    return {
        'unit': unit,
        'number': number,
        'kind': kind,
        'file': path.name,
        'title': heading,
        'rubric': rubric,
        'tokens': sorted(set(tokens)),
        'items': items,
    }


def answerable(item) -> bool:
    """Can this be marked without inventing anything?"""
    if item['shape'] == 'correction':
        return bool(item.get('answer'))
    if item['shape'] == 'multiple_choice':
        return bool(item.get('answers')) and len(item.get('options', [])) > 1
    blanks = item.get('blanks') or []
    return bool(blanks) and all(b['answers'] for b in blanks)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--disc', required=True, help='the extracted CD-ROM directory')
    ap.add_argument('--out', default='docs/data/cdrom/grammar_advanced.json')
    args = ap.parse_args()

    root = Path(args.disc)
    objects = next((p for p in root.rglob('learning_objects') if p.is_dir()), None)
    if objects is None:
        raise SystemExit(f'no learning_objects directory under {root}')

    exercises = []
    for path in sorted(objects.glob('*/*.xml')):
        if NOT_AN_EXERCISE.match(path.stem):
            continue
        parsed = read_exercise(path)
        if parsed and parsed['items']:
            parsed['section'] = path.parent.name
            exercises.append(parsed)

    shapes = Counter()
    marked = 0
    unmarkable = []
    audio = 0
    for ex in exercises:
        for item in ex['items']:
            shapes[item['shape']] += 1
            if item.get('audio'):
                audio += 1
            if answerable(item):
                marked += 1
            elif not item['is_example']:
                unmarkable.append(f"{ex['file']} #{item['position']}")

    by_unit = defaultdict(list)
    for ex in exercises:
        by_unit[ex['unit']].append(ex)

    payload = {
        'key': 'grammar_advanced_cdrom',
        'source': 'Advanced Grammar in Use — interactive disc',
        'exercises': exercises,
        'units': sorted(by_unit),
        'summary': {
            'exercises': len(exercises),
            'items': sum(len(e['items']) for e in exercises),
            'markable_items': marked,
            'items_with_audio': audio,
            'shapes': dict(shapes),
            'unmarkable': unmarkable,
        },
    }

    out = ROOT / args.out
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, indent=1, ensure_ascii=False), encoding='utf-8')

    total = payload['summary']['items']
    print(f"exercises {len(exercises)} over units {min(by_unit)}-{max(by_unit)}")
    print(f"items {total}, markable {marked} ({marked / total:.0%}), with audio {audio}")
    for shape, n in shapes.most_common():
        print(f'   {shape:16s} {n:>5}')
    if unmarkable:
        print(f'   {len(unmarkable)} item(s) carry no answer and are flagged, not guessed')


if __name__ == '__main__':
    main()
