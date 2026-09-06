"""The multiple-choice bank the grammar books print at the back.

Each of these books ends with a Study guide: a hundred and fifty numbered
sentences with a gap, two to five printed alternatives, and - in the margin -
the units that teach the point. A key a few pages later states which letters are
right, and more than one may be.

It is the only place in the corpus where the authors themselves wrote the wrong
answers, which makes every item here provable in a way a derived one is not:
the distractors are wrong because the people who wrote the unit say so. The
margin's unit numbers are the other half of the value - they say which lesson
each item belongs to, so nothing has to be guessed from the wording.

    1.4        How                           now? Better than before?          4
               A you are feeling       B do you feel  C are you feeling

    1.4      B, C
"""

import re
from collections import Counter

RE_ITEM = re.compile(r'^(\s*(\d{1,3})\.(\d{1,3})\s+)(\S.*)$')
RE_OPTIONS_START = re.compile(r'^\s*A\s+\S')
RE_UNITS = re.compile(r'\s{2,}(\d{1,3}(?:\s*,\s*\d{1,3})*)\s*$')
RE_KEY = re.compile(r'(?<!\d)(\d{1,3})\.(\d{1,3})\s+([A-E](?:\s*,\s*[A-E])*)(?![A-Za-z])')

BLANK = '______'

#: A gap is printed as a run of spaces. Two would be ordinary word spacing on a
#: line the layout has stretched, so it takes three to count.
RE_GAP = re.compile(r'\s{3,}')

#: The space between the two halves of a printed exchange - "'How long … ?'
#: 'A long time.'" - is set as wide as a gap and is not one.
RE_TURN = re.compile(r'([’”"?!.])\s{3,}(?=[‘“"])')


def read_guide(pages):
    """The items printed across these pages, before the key is applied.

    @param pages list[str] the guide's pages as pdftotext -layout wrote them
    @return dict "4.1" -> {stem, options, units}
    """
    out = {}
    for page in pages:
        lines = page.splitlines()
        starts = [RE_ITEM.match(line) for line in lines]
        column = _stem_column([m for m in starts if m])
        if column is None:
            continue

        for i, match in enumerate(starts):
            if not match:
                continue
            block = []
            for line in lines[i + 1:]:
                if RE_ITEM.match(line) or not line.strip():
                    break
                block.append(line)
            item = _read_item(lines[i], block, column)
            if item is not None:
                out[f'{match.group(2)}.{match.group(3)}'] = item

    return out


def read_key(pages):
    """Which letters the key states, per item id."""
    out = {}
    for page in pages:
        for m in RE_KEY.finditer(page):
            letters = [x.strip() for x in m.group(3).split(',')]
            out[f'{m.group(1)}.{m.group(2)}'] = letters
    return out


def items(guide_pages, key_pages):
    """Every study-guide item that can be asked as it is printed."""
    guide = read_guide(guide_pages)
    key = read_key(key_pages)

    out = []
    for number, item in sorted(guide.items(), key=_ordinal):
        correct = key.get(number)
        if not correct:
            continue
        letters = {letter for letter, _ in item['options']}
        # An item whose key names a letter the page does not print was read
        # wrongly on one side or the other, and there is no telling which.
        if not letters or not set(correct) <= letters:
            continue
        if item['stem'].count(BLANK) != 1:
            continue
        if len(item['options']) < 2 or len(correct) >= len(item['options']):
            continue
        if any(not text.strip() for _, text in item['options']):
            continue

        out.append({
            'number': number,
            'stem': item['stem'],
            'units': item['units'],
            'options': [{'label': letter, 'text': text, 'is_correct': letter in correct}
                        for letter, text in item['options']],
        })

    return out


def _ordinal(pair):
    a, b = pair[0].split('.')
    return int(a), int(b)


def _stem_column(matches):
    """Where the sentences start, so a gap printed before the first word shows.

    Every item on the page is set to the same left edge. A sentence that begins
    with its gap therefore begins further right than that edge, and without
    knowing the edge there is no way to tell that from ordinary indentation.
    """
    if not matches:
        return None
    widths = Counter(len(m.group(1)) for m in matches)
    return widths.most_common(1)[0][0]


def _read_item(line, block, column):
    body = line[column:] if len(line) > column else line[len(line):]
    lines = [body]
    options = []

    for extra in block:
        if options or RE_OPTIONS_START.match(extra):
            options.append(extra.strip())
        else:
            lines.append(extra[column:] if len(extra) > column else extra.strip())

    if not options:
        return None

    stem, units = _stem(lines)
    if BLANK not in stem:
        return None

    # Options wrap to a second line, and the join has to leave the gap that
    # tells a label from a capital letter inside an alternative.
    return {'stem': stem, 'units': units, 'options': _split_options('   '.join(options))}


def _stem(lines):
    """The sentence with its gap marked, and the units printed beside it."""
    units = []
    cleaned = []
    for line in lines:
        found = RE_UNITS.search(line)
        if found:
            units = [int(n) for n in re.split(r'\s*,\s*', found.group(1))]
            line = line[:found.start()]
        cleaned.append(line)

    text = RE_TURN.sub(r'\1 ', '\n'.join(cleaned))
    # A gap at the very front of the sentence: the line starts further right
    # than the column every other item starts at.
    lead = BLANK + ' ' if RE_GAP.match(text) else ''
    text = RE_GAP.sub(f' {BLANK} ', text.strip())

    return ' '.join((lead + text).split()), units


def _split_options(text):
    """The printed alternatives, as (letter, text) in the order they are set.

    Spacing cannot be trusted to tell a label from a capital letter inside an
    alternative: the line "A A friend of me    B A friend of mine" prints three
    capital As, and "A in   B for     Ca        D the" prints a label with no
    space after it at all. What can be trusted is the order. The labels run A,
    B, C … , so each is looked for only after the one before it, and the
    alternative that begins "A friend" is never searched for an A.
    """
    out = []
    at = 0
    for i in range(5):
        letter = chr(ord('A') + i)
        # A space after the label as well as before it. Without it the D of
        # "C Did you eat" is taken for the label of a fourth alternative, and
        # the learner is offered "id you eat".
        found = re.compile(r'(?:(?<=\s)|^)' + letter + r'\s+(?=\S)').search(text, at)
        if not found:
            break
        if out:
            out[-1] = (out[-1][0], ' '.join(text[at:found.start()].split()))
        out.append((letter, ''))
        at = found.end()

    if len(out) < 2:
        return []

    out[-1] = (out[-1][0], ' '.join(text[at:].split()))

    return out
