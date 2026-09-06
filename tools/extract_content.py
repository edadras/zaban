#!/usr/bin/env python3
"""
Content extractor for the "in Use" sources.

Turns each book into structured educational objects ready for database import.
Where analyze_sources.py reports *what* is in the books, this produces the actual
payload: units, lettered sections, the target language each section teaches,
worked examples, exercise instructions, the answer key, and the audio map.

Sixteen books across six series: vocabulary, grammar, pronunciation, phrasal
verbs, collocations and idioms. They share a house style closely enough for one
parser, and differ in exactly two places that matter - how a unit heading is
printed, and whether the pages carry text at all. Ten of them are scans and are
read from their page images by tools/ocr_book.py first; they arrive here in the
same shape as a born-digital page and nothing downstream can tell the
difference.

Target language is recovered from typography rather than guessed: the series
sets every taught item in bold, so bold runs are the authoritative headword
list. Each run is attributed to a section by vertical position on the page.
"""
import argparse
import html
import itertools
import json
import re
import subprocess
import sys
from collections import Counter, defaultdict
from pathlib import Path
from xml.etree import ElementTree

sys.path.insert(0, str(Path(__file__).resolve().parent))

from watermarks import scrub  # noqa: E402  (sibling module)

ROOT = Path('/home/user/zaban')
CACHE = Path('/tmp/extract')

# The six "in Use" series this project is built from.
#
# `skill` is the one every concept taken from the series is filed under. It is
# not decoration: the placement test reports a level per skill, and until these
# books arrived six of the seven skills had no items at all behind them, so the
# report was showing a starting guess and calling it a measurement.
#
# `concepts` says what a taught item is in this series, and it is the one place
# the six genuinely disagree. A vocabulary, phrasal verb, collocation or idiom
# book teaches words, and its bold runs are the headword list. A grammar or
# pronunciation book teaches a pattern, and its bold runs are forms
# illustrating that pattern: read as headwords they give "'m", "ing" and
# "are +" - 3,494 of them from one book - which is not a vocabulary anyone can
# learn. There the concept is the unit's own point, and the bold forms are kept
# with the lesson as what it drills.
#
# `heading` says how the series prints a unit heading, which is the one thing
# else that differs between them - see `find_units`.
SERIES = {
    'vocabulary': {
        'family': 'English Vocabulary in Use',
        'course': 'English Vocabulary',
        'skill': 'vocabulary',
        'concepts': 'headword',
        'heading': 'number_first',
    },
    'grammar': {
        'family': 'Grammar in Use',
        'course': 'English Grammar',
        'skill': 'grammar',
        'concepts': 'pattern',
        'heading': 'unit_above',
    },
    'pronunciation': {
        'family': 'English Pronunciation in Use',
        'course': 'English Pronunciation',
        'skill': 'pronunciation',
        'concepts': 'pattern',
        'heading': 'number_first',
    },
    'phrasal_verbs': {
        'family': 'English Phrasal Verbs in Use',
        'course': 'English Phrasal Verbs',
        'skill': 'vocabulary',
        'concepts': 'headword',
        'heading': 'number_first',
    },
    'collocations': {
        'family': 'English Collocations in Use',
        'course': 'English Collocations',
        'skill': 'vocabulary',
        'concepts': 'headword',
        'heading': 'number_first',
    },
    'idioms': {
        'family': 'English Idioms in Use',
        'course': 'English Idioms',
        'skill': 'vocabulary',
        'concepts': 'headword',
        'heading': 'number_first',
    },
}

# Every book, and where its pages and its recordings are.
#
# `level` names the LEVEL, and it must keep naming the level.
#
# An earlier pass renamed the vocabulary books to Foundation / Core / Advancing
# / Mastery, which broke two things at once. Searching the corpus for
# "elementary" or "advanced" - the words everyone actually uses for these books,
# and the words in their own filenames - returned nothing, so both looked empty
# when they were full. Worse, "Advancing" was the upper-intermediate book, so a
# search for "Advanc" landed confidently on the wrong one. A label that quietly
# points at a different book than its name suggests is a worse failure than an
# ugly label, so these track the filenames exactly.
#
# `pages` says where the text comes from. Ten of the sixteen books are scans:
# nine have no text layer at all, and `Pronunciation in Use Advanced` has
# something worse - an old OCR layer with the letters spaced apart, so
# "dictionary" is stored as "d i cti o n a ry" and a fifth of its tokens are
# stray single letters. Those are read from the page images by tools/ocr_book.py
# instead, and arrive here in the same shape as a born-digital page.
BOOKS = [
    {'key': 'elementary', 'series': 'vocabulary', 'cefr': 'A1-A2',
     'level': 'Elementary', 'title': 'English Vocabulary in Use — Elementary',
     'pdf': 'sources/elementary_3rd.pdf',
     'audio': 'sources/audio/elementary', 'pages': 'text'},
    {'key': 'pre_int_int', 'series': 'vocabulary', 'cefr': 'A2-B1',
     'level': 'Pre-intermediate and Intermediate',
     'title': 'English Vocabulary in Use — Pre-intermediate and Intermediate',
     'pdf': 'sources/pre_intermediate_intermediate_4th.pdf',
     'audio': 'sources/audio/pre_intermediate_intermediate', 'pages': 'text'},
    {'key': 'upper_int', 'series': 'vocabulary', 'cefr': 'B2',
     'level': 'Upper-intermediate', 'title': 'English Vocabulary in Use — Upper-intermediate',
     'pdf': 'sources/upper_intermediate_4th.pdf',
     'audio': 'sources/audio/upper_intermediate', 'pages': 'text'},
    {'key': 'advanced', 'series': 'vocabulary', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'English Vocabulary in Use — Advanced',
     'pdf': 'sources/advanced_3rd.pdf',
     'audio': 'sources/audio/advanced', 'pages': 'text'},

    {'key': 'grammar_basic', 'series': 'grammar', 'cefr': 'A1-A2',
     'level': 'Basic', 'title': 'Basic Grammar in Use',
     'pdf': 'sources/grammar_basic_4th.pdf',
     'audio': 'sources/audio/grammar_basic', 'pages': 'ocr'},
    {'key': 'grammar_intermediate', 'series': 'grammar', 'cefr': 'B1-B2',
     'level': 'Intermediate', 'title': 'English Grammar in Use — Intermediate',
     'pdf': 'sources/grammar_intermediate_5th.pdf',
     'audio': 'sources/audio/grammar_intermediate', 'pages': 'text'},
    {'key': 'grammar_advanced', 'series': 'grammar', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'Advanced Grammar in Use',
     'pdf': 'sources/grammar_advanced_3rd.pdf',
     'audio': 'sources/audio/grammar_advanced', 'pages': 'ocr'},

    {'key': 'pronunciation_elementary', 'series': 'pronunciation', 'cefr': 'A1-A2',
     'level': 'Elementary', 'title': 'English Pronunciation in Use — Elementary',
     'pdf': 'sources/pronunciation_elementary_2nd.pdf',
     'audio': 'sources/audio/pronunciation_elementary', 'pages': 'ocr'},
    {'key': 'pronunciation_intermediate', 'series': 'pronunciation', 'cefr': 'B1-B2',
     'level': 'Intermediate', 'title': 'English Pronunciation in Use — Intermediate',
     'pdf': 'sources/pronunciation_intermediate_2nd.pdf',
     'audio': 'sources/audio/pronunciation_intermediate', 'pages': 'ocr'},
    {'key': 'pronunciation_advanced', 'series': 'pronunciation', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'English Pronunciation in Use — Advanced',
     'pdf': 'sources/pronunciation_advanced_2nd.pdf',
     'audio': 'sources/audio/pronunciation_advanced', 'pages': 'ocr'},

    {'key': 'phrasal_verbs_intermediate', 'series': 'phrasal_verbs', 'cefr': 'B1-B2',
     'level': 'Intermediate', 'title': 'English Phrasal Verbs in Use — Intermediate',
     'pdf': 'sources/phrasal_verbs_intermediate_2nd.pdf',
     'audio': None, 'pages': 'ocr'},
    {'key': 'phrasal_verbs_advanced', 'series': 'phrasal_verbs', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'English Phrasal Verbs in Use — Advanced',
     'pdf': 'sources/phrasal_verbs_advanced_2nd.pdf',
     'audio': None, 'pages': 'text'},

    {'key': 'collocations_intermediate', 'series': 'collocations', 'cefr': 'B1-B2',
     'level': 'Intermediate', 'title': 'English Collocations in Use — Intermediate',
     'pdf': 'sources/collocations_intermediate_2nd.pdf',
     'audio': None, 'pages': 'ocr'},
    {'key': 'collocations_advanced', 'series': 'collocations', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'English Collocations in Use — Advanced',
     'pdf': 'sources/collocations_advanced_2nd.pdf',
     'audio': None, 'pages': 'ocr'},

    {'key': 'idioms_intermediate', 'series': 'idioms', 'cefr': 'B1-B2',
     'level': 'Intermediate', 'title': 'English Idioms in Use — Intermediate',
     'pdf': 'sources/idioms_intermediate_2nd.pdf',
     'audio': None, 'pages': 'ocr'},
    {'key': 'idioms_advanced', 'series': 'idioms', 'cefr': 'C1-C2',
     'level': 'Advanced', 'title': 'English Idioms in Use — Advanced',
     'pdf': 'sources/idioms_advanced_2nd.pdf',
     'audio': None, 'pages': 'ocr'},
]

# Where the OCR of a scanned book is kept, one JSON file per page.
OCR_ROOT = ROOT / 'sources' / 'ocr'

# The resolution the page images were read at. Word positions arrive in those
# pixels and are converted to points, so a rule written against a born-digital
# page - "the section title shares a line with its letter, within twelve" -
# means the same thing on a scan.
OCR_DPI = 300

RE_EXERCISE = re.compile(r'^\s{0,6}(\d{1,3})\.(\d{1,2})\s+(.*)$', re.M)
RE_GLOSS = re.compile(r'\[([^\[\]]{3,200})\]')
RE_SECTION_LETTER = re.compile(r'^[A-H]$')
# The running footer at the foot of every page of every book in the family.
RE_FOOTER = re.compile(r'\b(?:Vocabulary|Grammar|Pronunciation|Phrasal Verbs?|'
                       r'Collocations?|Idioms) in Use\b', re.I)
# Bold runs that are structural furniture rather than taught language.
RE_NOISE = re.compile(r'^(?:\d+|[A-H]|Common|mistakes|Language help|Tip|Note|Exercises|'
                      r'Answer key|Follow[- ]up|Study unit)\.?$', re.I)

# Single letters that really are English words. Everything else of length one is
# a fragment: these books bold the individual letters when spelling out an
# acronym, so "PIN" yields p, i, n as if each were vocabulary.
#
# Case matters for exactly one of them. The pronoun is always written "I", so a
# lowercase "i" is never a word - it is the middle letter of PIN. Matching it
# case-insensitively would let that one back in.
REAL_ONE_LETTER_WORDS = {'a', 'A', 'I'}


def is_headword(term):
    """Could this bold run be a word someone is meant to learn?

    Guards against three kinds of debris the bold-run extraction picks up, all
    of which reached the database as vocabulary before this existed:

      * pure punctuation and symbols - "…", "›", "•", "(", "£", "?"
      * single letters from spelled-out acronyms - the p, i, n of PIN
      * bare figures and references - "1.1", "2%", "2014-2016"

    Deliberately permissive about length beyond that: "an", "be", "go", "TV",
    "ID" are all real headwords, so only length one is treated as suspect.
    """
    term = term.strip()

    if not term:
        return False

    # Nothing to pronounce, nothing to learn.
    if not re.search(r'[A-Za-z]', term):
        return False

    # "+ -ing", "+ prepositions": the books' notation for what a pattern takes,
    # not a word anyone is asked to learn.
    if term.startswith('+'):
        return False

    if len(term) == 1 and term not in REAL_ONE_LETTER_WORDS:
        return False

    return True


def run(cmd):
    return subprocess.run(cmd, capture_output=True, text=True, check=True).stdout


def load_xml(pdf, key):
    CACHE.mkdir(parents=True, exist_ok=True)
    xml_path = CACHE / f'{key}.xml'
    if not xml_path.exists():
        out = run(['pdftohtml', '-xml', '-i', '-stdout', str(pdf)])
        xml_path.write_text(out, encoding='utf-8')
    # poppler emits stray raw ampersands in some titles; repair before parsing
    raw = xml_path.read_text(encoding='utf-8', errors='replace')
    raw = re.sub(r'&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)', '&amp;', raw)
    return ElementTree.fromstring(raw)


def load_word_lines(pdf, key):
    """Word-level extraction, joined with real spaces.

    pdftotext's run-level output occasionally drops inter-word spacing on
    justified lines ("Ifyoudonothaveapartner"). Extracting words individually and
    rejoining them reconstructs the true spacing, so we build a lookup keyed by
    the despaced form of each line and use it to repair anything that came
    through collapsed.
    """
    CACHE.mkdir(parents=True, exist_ok=True)
    cache = CACHE / f'{key}.words.json'
    if cache.exists():
        return json.loads(cache.read_text(encoding='utf-8'))

    raw = run(['pdftotext', '-bbox-layout', str(pdf), '-'])
    # poppler's bbox output is not reliably well-formed XML (unescaped entities in
    # glyph text), so scan it structurally instead of handing it to a strict parser.
    repair = {}
    for lm in re.finditer(r'<line\b[^>]*>(.*?)</line>', raw, re.S):
        words = [html.unescape(w).strip()
                 for w in re.findall(r'<word\b[^>]*>(.*?)</word>', lm.group(1), re.S)]
        words = [w for w in words if w]
        if len(words) < 2:
            continue
        proper = ' '.join(words)
        key_ = re.sub(r'\s+', '', proper)
        if len(key_) >= 12:
            repair.setdefault(key_, proper)
    cache.write_text(json.dumps(repair, ensure_ascii=False))
    return repair


# Typographic ligatures the PDFs set as single glyphs. pdftotext emits the
# glyph itself and often a stray space behind it, so "benefits" arrives as
# "beneﬁ ts" - a real word, unsearchable and unmatchable.
LIGATURES = {
    '\ufb00': 'ff', '\ufb01': 'fi', '\ufb02': 'fl', '\ufb03': 'ffi',
    '\ufb04': 'ffl', '\ufb05': 'st', '\ufb06': 'st',
}
RE_LIGATURE_SPACE = re.compile(r'(f[filfl]{1,2})\s+(?=[a-z])')


def unligature(text):
    """Expand ligature glyphs and close the space they leave behind.

    The trailing space is the part that matters. "beneﬁ ts" expands to
    "benefi ts", which is still not the word; only closing the gap gives
    "benefits". Restricted to a following lowercase letter so it cannot glue
    two genuine words together.
    """
    for glyph, plain in LIGATURES.items():
        text = text.replace(glyph, plain)

    return RE_LIGATURE_SPACE.sub(r'\1', text)


def repair_line(text, repair):
    """Restore spacing on a line that lost it, leaving correct lines untouched."""
    text = unligature(text)
    if not re.search(r'[A-Za-z]{16,}', text):
        return text
    fixed = repair.get(re.sub(r'\s+', '', text))
    return unligature(fixed) if fixed else text


def load_layout(pdf, key):
    """The pages of a PDF as `pdftotext -layout` renders them.

    Scrubbed on the way in. Two of these books carry a digitiser's address in
    their own text layer, so it arrives without going anywhere near the OCR
    reader that strips it from the scans - and it was reaching the extracted
    lessons.
    """
    CACHE.mkdir(parents=True, exist_ok=True)
    txt = CACHE / f'{key}.txt'
    if not txt.exists():
        subprocess.run(['pdftotext', '-layout', str(pdf), str(txt)], check=True)
    pages = scrub(txt.read_text(encoding='utf-8', errors='replace')).split('\f')
    # the final form feed yields a trailing empty element that is not a page
    if pages and not pages[-1].strip():
        pages.pop()
    return pages


def node_text(el):
    return scrub(html.unescape(''.join(el.itertext()))).strip()


def bold_spans(el):
    """Bold fragments inside a <text> node, including nested <b><i>."""
    out = []
    for b in el.iter():
        if b.tag == 'b':
            t = html.unescape(''.join(b.itertext())).strip()
            if t:
                out.append(t)
    return out


def load_ocr(key):
    """The pages of a scanned book, in the same shape as a born-digital one.

    tools/ocr_book.py writes one JSON file per page holding the page laid out
    on a character grid and every word with its box, its confidence and whether
    it is bold. Both halves are needed and neither replaces the other: the laid
    out text is what the unit, exercise and gloss parsers read, and the word
    boxes are what the section and vocabulary parsers read.

    Word positions arrive in image pixels and leave in points, so that a rule
    written for a PDF - "the section title is the run beside the letter, within
    twelve of its top" - holds here too rather than silently never matching.

    @return (page number -> runs, list of page texts)
    """
    directory = OCR_ROOT / key
    files = sorted(directory.glob('*.json'))
    if not files:
        raise SystemExit(
            f'{key}: no OCR under {directory}. Read the book first:\n'
            f'  python3 tools/ocr_book.py <pdf> --out {directory}')

    scale = 72.0 / OCR_DPI
    pages = {}
    layout = []
    expected = 1
    for path in files:
        page = json.loads(path.read_text(encoding='utf-8'))
        number = page['number']

        # A page that failed to read still occupies its place in the book, or
        # every page after it is numbered wrongly and every unit lands on the
        # wrong one.
        while expected < number:
            layout.append('')
            pages[expected] = []
            expected += 1

        layout.append(page['text'])
        pages[number] = runs_from_words(page.get('words', []), scale)
        expected = number + 1

    return pages, layout


def runs_from_words(words, scale):
    """Group read words back into the runs a PDF would have given.

    A run is a stretch of one line set the same way. That is what the section
    and vocabulary parsers expect, and it matters most for bold: the series
    marks every taught item in bold, so a bold stretch has to arrive as its own
    run or the headword list is empty.

    A run therefore ends where the weight changes, or where the gap to the next
    word is wide enough to be a column boundary rather than a space.
    """
    lines = {}
    for word in words:
        # Words on the same line rarely share a top exactly; a band a few
        # points deep groups them without merging two lines.
        band = round(word['y0'] * scale / 6)
        lines.setdefault(band, []).append(word)

    runs = []
    for band in sorted(lines):
        row = sorted(lines[band], key=lambda w: w['x0'])
        current = None
        for word in row:
            left = word['x0'] * scale
            top = word['y0'] * scale
            width = max(1.0, (word['x1'] - word['x0']) * scale)
            per_char = width / max(1, len(word['text']))

            gap = None if current is None else left - current['right']
            if (current is None
                    or word['bold'] != current['bold']
                    or gap > per_char * 3):
                if current is not None:
                    runs.append(current)
                current = {'top': int(round(top)), 'left': int(round(left)),
                           'text': word['text'], 'bold': word['bold'],
                           'right': left + width}
            else:
                current['text'] += ' ' + word['text']
                current['right'] = left + width
        if current is not None:
            runs.append(current)

    out = []
    for run in runs:
        out.append({
            'top': run['top'],
            'left': run['left'],
            'text': run['text'],
            'bold': [run['text']] if run['bold'] else [],
        })
    out.sort(key=lambda r: (r['top'], r['left']))
    return out


def parse_pages(root):
    """page number -> list of runs {top,left,text,bold[]}"""
    pages = {}
    for page in root.iter('page'):
        n = int(page.get('number'))
        runs = []
        for t in page.iter('text'):
            txt = node_text(t)
            if not txt:
                continue
            runs.append({
                'top': int(t.get('top', 0)),
                'left': int(t.get('left', 0)),
                'text': txt,
                'bold': bold_spans(t),
            })
        runs.sort(key=lambda r: (r['top'], r['left']))
        pages[n] = runs
    return pages


# A unit heading, in the two shapes the six series print it in.
#
#   number_first   "11   In"                     - the number opens the line
#   unit_above     "Unit" / " 9   Present ..."   - the word sits on its own line
#
# The distinction is not cosmetic. Read a Grammar in Use page with the
# number-first rule and it finds no units at all, because the number is on the
# second line; read a Vocabulary in Use page with the other and the same.
RE_NUMBER_FIRST = re.compile(r'^\s{0,12}(\d{1,3})\s{1,}(\S.{1,70})$')
RE_UNIT_INLINE = re.compile(r'^\s*unit\s+(\d{1,3})\s{1,}(\S.{1,70})$', re.I)
RE_UNIT_WORD = re.compile(r'^\s*units?\s*$', re.I)
# "Unit       Present continuous and present simple 1" - the word and the first
# half of a title that was too long for one line, with the number and the rest
# of it below.
RE_UNIT_WITH_TITLE = re.compile(r'^\s*units?\s{2,}(\S.{1,70})$', re.I)

# A page that carries a unit heading also carries the unit: a lettered section
# under it, or one of the unit's numbered exercises. Without that test the
# front matter is read as units - "60 units of vocabulary reference and
# practice" on the cover of Phrasal Verbs in Use became unit 60, displacing the
# real one.
RE_SECTION_LINE = re.compile(r'^\s{0,24}[A-H]\|?\s{2,}\S', re.M)
RE_EXERCISE_LABEL = re.compile(r'^\s{0,8}\d{1,3}\.\d{1,2}\s', re.M)


def heading_on_page(head, style):
    """The unit number and title at the top of a page, or (None, None).

    `head` is the first few non-empty lines. Only the top of the page is looked
    at, because a numbered exercise further down the page ("11 Complete the
    sentences") reads exactly like a unit heading and is not one.
    """
    for index, line in enumerate(head):
        if style == 'unit_above':
            m = RE_UNIT_INLINE.match(line)
            if m:
                return int(m.group(1)), m.group(2).strip()
            # "Unit" alone, then the number and the title on the next line.
            if RE_UNIT_WORD.match(line) and index + 1 < len(head):
                m = RE_NUMBER_FIRST.match(head[index + 1])
                if m:
                    return int(m.group(1)), m.group(2).strip()

            # The same, but the title was too long and started beside the word.
            opener = RE_UNIT_WITH_TITLE.match(line)
            if opener and index + 1 < len(head):
                m = RE_NUMBER_FIRST.match(head[index + 1])
                if m:
                    return int(m.group(1)), f'{opener.group(1).strip()} {m.group(2).strip()}'
            continue

        m = RE_NUMBER_FIRST.match(line)
        if m:
            title = m.group(2).strip()
            # A heading that wrapped: the line above is the first half of it.
            if index > 0:
                previous = head[index - 1].strip()
                if (previous and not re.match(r'^\d', previous)
                        and previous[:1].isupper() and not previous.endswith('.')
                        and len(previous) < 70):
                    title = f'{previous} {title}'
            return int(m.group(1)), title

        m = RE_UNIT_INLINE.match(line)
        if m:
            return int(m.group(1)), m.group(2).strip()

    return None, None


# A contents entry: the unit number, its title, and often the page it starts
# on. The page number is optional because the scanned books lose it to the
# gutter as often as not.
# A contents entry: the unit number, its title, and often the page it starts
# on. The page number is optional because the scanned books lose it to the
# gutter as often as not.
RE_CONTENTS_ENTRY = re.compile(r'^(\s{0,20})(\d{1,3})(\s{1,8})(\S.{1,70}?)(?:\s{2,}\d{1,3})?\s*$')
RE_BARE_NUMBER = re.compile(r'^\s{0,30}(\d{1,3})\s*[|\]).]?\s*$')
RE_CONTENTS_LINE = re.compile(r'^(\s{0,30})(\S.{1,70}?)(?:\s{2,}\d{1,3})?\s*$')


def tidy_title(text: str) -> str:
    """Close up the spacing, and put back the pronoun the scanner lost.

    A capital I standing alone is read as a vertical rule more often than not,
    so "I have... and I've got" arrives as "| have... and I've got". A pipe is
    never an English word, so the substitution cannot take anything away.
    """
    text = re.sub(r'\s{2,}', ' ', text).strip(' .')
    return re.sub(r'(?<=^)\|(?=\s)|(?<=\s)\|(?=\s)', 'I', text)


def reads_as_title(text: str) -> bool:
    """Is this a title, or is it what the scanner made of the numeral column?

    The numbers 2 to 9 of the Basic Grammar contents came back as the single
    line "WO WON HRW", sitting at the title column and counting as an entry -
    which shifted every title after it down by one unit. Every real title in
    these books contains ordinary lowercase words; a column of mangled digits
    does not.
    """
    return len(re.findall(r'[a-z]', text)) >= 2


def contents_rows(page):
    """Every line of a contents page, as (number or None, column, title).

    Both kinds of line are kept. The numbered ones are the entries; the
    unnumbered ones matter because the numeral column is the narrowest thing on
    a scanned page and the first to be lost - on the Basic Grammar contents the
    numbers 2 to 9 came back as "WO WON HRW" while their titles read perfectly.
    """
    rows = []
    pending_number = None
    for line in page.splitlines():
        # A number alone on its line, with its title on the next. The contents
        # of the pronunciation books come out this way whenever the number
        # column and the title column are far enough apart for the reader to
        # treat them as separate blocks.
        bare = RE_BARE_NUMBER.match(line)
        if bare:
            pending_number = int(bare.group(1))
            continue

        if pending_number is not None:
            m = RE_CONTENTS_LINE.match(line)
            if m:
                rows.append((pending_number, len(m.group(1)), tidy_title(m.group(2))))
                pending_number = None
                continue
            pending_number = None

        m = RE_CONTENTS_ENTRY.match(line)
        if m:
            title = tidy_title(m.group(4))
            column = len(m.group(1)) + len(m.group(2)) + len(m.group(3))
            rows.append((int(m.group(2)), column, title))
            continue
        m = RE_CONTENTS_LINE.match(line)
        if m:
            title = tidy_title(m.group(2))
            rows.append((None, len(m.group(1)), title))
    return [(n, c, t) for n, c, t in rows
            if len(t) >= 2 and sum(ch.isalpha() for ch in t) >= 2]


def number_contents(rows):
    """Give the unnumbered entries the numbers the scan lost.

    A contents list runs consecutively, so an entry between number 1 and
    number 10 with eight titles between them can only be 2 to 9. That is done
    only when it comes out exactly: if the count between two anchors does not
    fill the gap, nothing is assigned, because a title filed under the wrong
    unit is worse than a title missing.

    A line is only a candidate if it starts at or right of the column the
    numbered titles start in. That is what separates an entry from the section
    headings the contents also prints ("Present", "Prepositions"), which sit
    further left - while still keeping an entry whose own first words were lost
    to the scan and which therefore begins further right than its neighbours.

    A number can also be misread, and a misread number is worse than a missing
    one: it is a wrong title filed under a real unit. The Pronunciation in Use
    contents lists units 41 to 50 on one page, and "41]" came back as "4]", so
    unit 4 - forty pages earlier - was handed unit 41's title. An anchor whose
    step to the next one neither is small nor fills exactly is therefore
    dropped, once the entry after it shows which of the two was the misread.
    """
    numbered = [(n, c, t) for n, c, t in rows if n is not None]
    if len(numbered) < 4:
        return []

    columns = sorted(c for _, c, _ in numbered)
    title_column = columns[len(columns) // 2]
    candidates = [
        (n, t) for n, c, t in rows
        if n is not None or (c >= title_column - 3 and reads_as_title(t))
    ]

    anchors = [i for i, (n, _) in enumerate(candidates) if n is not None]

    out = []
    pending = []
    previous = None
    for position, (number, title) in enumerate(candidates):
        if number is None:
            pending.append(title)
            continue

        if previous is not None:
            step = number - previous
            fills = step - 1 == len(pending)
            near = 1 <= step <= 3 and not pending

            if not (fills or near):
                # One of the two anchors is a misread. The one the entry after
                # this continues from is the real one.
                following = next((candidates[i][0] for i in anchors if i > position), None)
                if following is not None and 1 <= following - number <= 3:
                    out = [entry for entry in out if entry[0] != previous]
                    pending = []
                    previous = number
                    out.append((number, title))
                    continue
                pending = []
                previous = number
                continue

            if fills and pending:
                out.extend((previous + 1 + i, t) for i, t in enumerate(pending))

        pending = []
        out.append((number, title))
        previous = number

    return out


def contents_pages(layout_pages, horizon=None):
    """Which pages are the contents list, in page order.

    Three things have to hold, and each one stops a different impostor. The
    page has to be in the front matter, which no exercises page is. It has to
    carry at least eight numbered entries in ascending order, which no prose
    page does. And its numbers have to reach into double figures, which the
    numbered items of an exercise do not - without that, "1 Do you live in a
    city?" off an exercises page was read as the title of unit 2.
    """
    if horizon is None:
        # Contents live in the front matter of every book in this family.
        horizon = max(8, int(len(layout_pages) * 0.15))
    horizon = min(len(layout_pages), horizon)

    found = []
    for index, page in enumerate(layout_pages[:horizon]):
        rows = contents_rows(page)
        entries = [(n, t) for n, _, t in rows if n is not None and 1 <= n <= 200]
        if len(entries) < 8 or max(n for n, _ in entries) < 10:
            continue
        ascending = sum(1 for a, b in zip(entries, entries[1:]) if b[0] > a[0])
        if ascending < len(entries) - 2:
            continue
        found.append(index)

    # Only the opening run counts. An exercises page deep in the book can look
    # like a contents list - a column of ascending numbers, each with text
    # beside it - and taking it would put the end of the front matter a hundred
    # pages too late.
    cluster = []
    for index in found:
        if cluster and index - cluster[-1] > 2:
            break
        cluster.append(index)
    return cluster


def titles_from_contents(layout_pages, horizon=None):
    """Unit titles as the book itself lists them.

    Reading a title off the teaching page works when the page prints one. Basic
    Grammar in Use does not - its unit number lives in a coloured tab, which is
    artwork - and on a scan the largest line of a page is as likely to be a
    caption or a mis-read as the heading: unit 5 came back titled
    "| like ice cream." and unit 2 "Affirmative Question".

    The contents pages state every title plainly, in one column, in order. That
    is a better source than any page heading, and it is the same list the book
    prints for its own readers.

    Three things have to hold before a page is read as contents, and each one
    stops a different impostor. It has to come before the first unit, which no
    exercises page does. It has to carry at least eight numbered entries in
    ascending order, which no prose page does. And its numbers have to reach
    into double figures, which the numbered items of an exercise do not - the
    first attempt at this had none of that and read "1 Do you live in a city?"
    off an exercises page as the title of unit 2.
    """
    titles = {}
    for index in contents_pages(layout_pages, horizon):
        for number, title in number_contents(contents_rows(layout_pages[index])):
            if 1 <= number <= 200:
                titles.setdefault(number, title)

    return titles


def trim_second_column(line: str) -> str:
    """Drop what follows a heading on the same line, if it is another column.

    A fixed number of spaces cannot tell the two apart. A born-digital page
    separates its words by one space and its columns by twenty, so cutting at
    three worked; a scanned page can put eight spaces between two words of the
    same title, and cutting at three took "Father and mother" down to "Father",
    while cutting at eight took "What is a collocation?" down to "What".

    What does tell them apart is the shape of the line: a column gap stands out
    from every other gap on it, and a title that is merely spaced out has no
    gap that stands out at all.
    """
    gaps = [(m.start(), len(m.group())) for m in re.finditer(r'\s{2,}', line)]
    if not gaps:
        return line

    widest = max(gaps, key=lambda g: g[1])
    others = [width for start, width in gaps if start != widest[0]]
    rival = max(others) if others else 0

    if widest[1] >= 8 and widest[1] >= 2 * max(rival, 1):
        return line[:widest[0]]
    return line


def candidate_headings(layout_pages, style):
    """Every page whose top reads like a unit heading, in page order."""
    found = []
    for i, page in enumerate(layout_pages):
        head = [ln for ln in page.splitlines() if ln.strip()][:4]
        if not head:
            continue

        number, title = heading_on_page(head, style)
        if number is None or not (1 <= number <= 200):
            continue

        title = tidy_title(trim_second_column(title))
        # Two characters is a real unit title in this family: Phrasal Verbs in
        # Use has units called "In", "Up" and "On".
        if len(title) < 2 or RE_FOOTER.search(title):
            continue

        found.append({
            'number': number,
            'title': title,
            'teaching_page': i + 1,
            'teaches': bool(RE_SECTION_LINE.search(page) or RE_EXERCISE_LABEL.search(page)),
        })
    return found


def find_units(layout_pages, style='number_first'):
    """unit number -> its teaching page (1-based).

    Three rounds, because a heading can be wrong in three different ways.

    Front matter fakes headings - "60 units of vocabulary reference and
    practice" on a cover reads exactly like unit 60 - so the first round only
    accepts a page past the contents list, or, where the contents could not be
    read, one that shows a lettered section or a numbered exercise. No cover
    does either.

    That is strict enough to lose a real unit: Phrasal Verbs in Use unit 11 is
    continuous prose on a page with neither marker. So the second round takes
    back any number the first missed, on one condition - that its page falls
    between the pages of the units either side of it. A book runs its units in
    order, and a cover cannot be between unit 10 and unit 12.

    The third round is for the books that print no unit number in their text at
    all - see units_behind_exercises and units_by_sequence.
    """
    found = candidate_headings(layout_pages, style)
    contents = contents_pages(layout_pages)
    front_matter = (max(contents) + 1) if contents else 0

    # The exercises page states its unit outright - "17.1", "17.2" - which is
    # stronger evidence than a heading the scanner half read, so it goes first.
    # Phrasal Verbs in Use Intermediate has both: 58 of its 70 units name
    # themselves on their exercises page, while three headings came through,
    # one of which put unit 1 on unit 6's page. Read in the other order that
    # heading won and unit 1 kept the wrong page.
    units = units_behind_exercises(layout_pages, {})

    for c in found:
        past_front_matter = c['teaching_page'] > front_matter if contents else c['teaches']
        if past_front_matter and c['number'] not in units:
            units[c['number']] = {k: c[k] for k in ('number', 'title', 'teaching_page')}

    # The exercises page says which unit it is, not what the unit is called;
    # its title is guessed from the largest readable line of the page before.
    # Where the heading itself was read, that is the title, so it wins.
    by_number = {}
    for c in found:
        by_number.setdefault(c['number'], c)
    for number, unit in units.items():
        heading = by_number.get(number)
        if heading and heading['teaching_page'] == unit.get('teaching_page'):
            unit['title'] = heading['title']

    if not units:
        units.update(units_by_sequence(layout_pages, titles_from_contents(layout_pages)))
        return units

    for c in found:
        if c['number'] in units:
            continue
        before = units.get(c['number'] - 1)
        after = units.get(c['number'] + 1)
        lower = before['teaching_page'] if before else 0
        upper = after['teaching_page'] if after else len(layout_pages) + 1
        if lower < c['teaching_page'] < upper:
            units[c['number']] = {k: c[k] for k in ('number', 'title', 'teaching_page')}

    contents_titles = titles_from_contents(layout_pages)
    units.update(units_by_sequence(layout_pages, contents_titles, units))
    units.update(units_by_stride(found, contents_titles, len(layout_pages), front_matter, units))
    units.update(units_by_pitch(layout_pages, contents_titles, front_matter, units))
    return drop_off_the_line(drop_out_of_order(units))


def drop_off_the_line(units):
    """A unit whose page does not lie where the book's own spacing puts it.

    `drop_out_of_order` only sees a page that both its neighbours contradict,
    which misses the ends of the run: Phrasal Verbs in Use Intermediate had
    unit 1 on page 18 with unit 7 on page 20, and since there was nothing
    before unit 1, nothing contradicted it - so unit 1 took the text and the
    vocabulary of unit 6.

    These books are laid out to a fixed pitch, so where most of the units agree
    on one, a unit far off it is a mis-read rather than an exception. The
    tolerance is three units' worth of pages, and the check only runs when the
    line is well supported, so a book that is not laid out this way is left
    alone.
    """
    placed = [(n, u['teaching_page']) for n, u in units.items() if u.get('teaching_page')]
    if len(placed) < 20:
        return units

    strides = Counter()
    for (n1, p1), (n2, p2) in zip(sorted(placed), sorted(placed)[1:]):
        if n2 - n1 == 1 and 1 <= p2 - p1 <= 6:
            strides[p2 - p1] += 1
    if not strides:
        return units

    stride, _ = strides.most_common(1)[0]
    offsets = Counter(page - stride * number for number, page in placed)
    offset, support = offsets.most_common(1)[0]
    if support < 0.7 * len(placed):
        return units

    for number, page in placed:
        if abs(page - (stride * number + offset)) > 3 * stride:
            units[number]['teaching_page'] = None
            units[number]['off_the_line'] = True
            units[number]['needs_title_review'] = (
                units[number].get('title_source') != 'contents'
            )

    # And put the ones that came off it back where the line says they are, if
    # nothing else is there. Unit 1 of Phrasal Verbs in Use Intermediate was
    # read onto page 18; the line puts it on page 8, which is where it is.
    taken_pages = {u['teaching_page'] for u in units.values() if u.get('teaching_page')}
    highest = max(taken_pages) if taken_pages else 0

    for number, unit in units.items():
        if unit.get('teaching_page'):
            continue
        page = stride * number + offset
        if page < 1 or page > highest + stride or page in taken_pages:
            continue
        unit['teaching_page'] = page
        unit['page_source'] = 'line'
        taken_pages.add(page)

    return units


def units_by_pitch(layout_pages, contents, front_matter, taken):
    """Number the units off the rhythm of the exercises pages.

    The last resort, for the two books where nothing else survives the scan.
    Collocations in Use and Phrasal Verbs in Use Intermediate print their unit
    numbers and their contents numbers in coloured boxes - artwork - so the
    contents came back with no numbers at all and the headings with three unit
    numbers between them. Read that way, Collocations gave 44 units of 60 and
    Phrasal Verbs gave 68 of 70, eight of which were not units.

    Both books say what they are on their own first page: *the book has 60
    two-page units*. That regularity is visible without reading it - the
    exercises pages come every second page from the first to the last - and it
    is a far better guide than three misread headings. So the run is fitted to
    the pitch, and the units are counted along it.

    The fit has to explain almost every exercises page found. A book whose
    exercises do not come at a fixed interval produces no fit and nothing is
    numbered.
    """
    pages = [i + 1 for i, page in enumerate(layout_pages)
             if is_exercises_page(page) and i + 1 > front_matter]
    if len(pages) < 20:
        return {}

    gaps = Counter(b - a for a, b in zip(pages, pages[1:]))
    pitch, _ = gaps.most_common(1)[0]
    if not (2 <= pitch <= 4):
        return {}

    first, last = pages[0], pages[-1]
    predicted = list(range(first, last + 1, pitch))
    actual = set(pages)
    explained = sum(1 for p in predicted if p in actual)
    if explained < 0.85 * len(pages) or explained < 0.85 * len(predicted):
        return {}

    count = len(predicted)

    # Where the headings did give a run of units, the fit has to agree with it
    # roughly, or one of the two is reading a different book.
    known = [n for n in taken if taken[n].get('teaching_page')]
    if len(known) >= 20 and not (0.8 * max(known) <= count <= 1.25 * max(known)):
        return {}

    # Whether the fit merely fills gaps or replaces what the headings said.
    #
    # A book whose headings mostly read gets its pages from them, and the fit
    # only fills what is missing. A book where they mostly do not - Phrasal
    # Verbs in Use Intermediate gave twelve headings for seventy units, and put
    # unit 1 on unit 6's page - is better described by its own rhythm than by
    # the handful that came through, so the fit takes over completely. Leaving
    # those twelve in place would pin a dozen units to the wrong pages and
    # block the right ones from being filled in around them.
    placed = sum(1 for n in range(1, count + 1)
                 if n in taken and taken[n].get('teaching_page'))
    authoritative = placed < 0.4 * count

    claimed = set()
    if not authoritative:
        claimed = {u['teaching_page'] for u in taken.values() if u.get('teaching_page')}

    filled = {}
    for number, page in enumerate(predicted, start=1):
        if page - 1 <= front_matter or (page - 1) in claimed:
            continue
        if number in taken and not authoritative:
            continue

        title = contents.get(number) or (
            taken[number]['title'] if number in taken and taken[number].get('title_source') == 'contents'
            else None
        )
        filled[number] = {
            'number': number,
            'title': title or teaching_title(layout_pages[page - 2]) or f'Unit {number}',
            'teaching_page': page - 1,
            'title_source': 'contents' if title else None,
            'page_source': 'pitch',
            'needs_title_review': title is None,
        }
        claimed.add(page - 1)

    return filled


def units_by_stride(found, contents, page_count, front_matter, taken):
    """Fill in the units whose heading the scan lost, from the book's pitch.

    These books are laid out to a fixed pitch: one teaching page, one exercises
    page, all the way through. So the pages of the units that were read state
    where the rest are - in Pronunciation in Use Elementary every unit that came
    through sat on page 2n+9, and only fourteen of its sixty came through,
    because the number is set small and pale beside a large title and the reader
    dropped it about three times in four.

    The pitch is voted on rather than assumed: pairs of found units each imply a
    stride and an offset, and the fit is only used when most of the units agree
    on the same one. Two or three headings that happen to be evenly spaced
    cannot carry it - and where a book is not laid out to a pitch, no majority
    forms and nothing is filled.

    Units placed this way are marked, because their page is inferred from the
    book's rhythm rather than read off the page.
    """
    seen = [(c['number'], c['teaching_page']) for c in found
            if c['teaching_page'] > front_matter]
    if len(seen) < 6:
        return {}

    votes = Counter()
    for (n1, p1), (n2, p2) in itertools.combinations(seen, 2):
        if n1 == n2:
            continue
        span = p2 - p1
        gap = n2 - n1
        if gap == 0 or span % gap != 0:
            continue
        stride = span // gap
        if not (1 <= stride <= 4):
            continue
        votes[(stride, p1 - stride * n1)] += 1

    if not votes:
        return {}

    (stride, offset), _ = votes.most_common(1)[0]
    agreeing = [n for n, page in seen if page == stride * n + offset]
    if len(agreeing) < 6 or len(agreeing) < 0.6 * len(seen):
        return {}

    # How far the run of units goes. The contents says so when it was read;
    # otherwise trust only as far as the headings themselves reach.
    last = max(contents) if contents else max(agreeing)

    claimed = {u['teaching_page'] for u in taken.values() if u.get('teaching_page')}
    filled = {}
    for number in range(1, last + 1):
        if number in taken:
            continue
        page = stride * number + offset
        if page <= front_matter or page + 1 > page_count or page in claimed:
            continue
        title = contents.get(number)
        filled[number] = {
            'number': number,
            'title': title or f'Unit {number}',
            'teaching_page': page,
            'title_source': 'contents' if title else None,
            'page_source': 'stride',
            'needs_title_review': title is None,
        }
        claimed.add(page)

    return filled


def drop_out_of_order(units):
    """A book prints its units in order; a unit that does not is a mis-read.

    Advanced Grammar in Use gave unit 3 page 113, from a stray line a hundred
    pages into the answer key that happened to read like a heading, while units
    2 and 4 sat on pages 15 and 19. A unit page that is out of order takes the
    wrong page's sections, vocabulary and exercises with it, so the page is
    dropped and the unit kept - it still has its title, its audio and its place
    in the sequence, and it is flagged for review rather than pointed at the
    wrong page.
    """
    ordered = sorted(units)
    for index, number in enumerate(ordered):
        page = units[number].get('teaching_page')
        if page is None:
            continue

        before = next((units[n].get('teaching_page') for n in reversed(ordered[:index])
                       if units[n].get('teaching_page')), None)
        after = next((units[n].get('teaching_page') for n in ordered[index + 1:]
                      if units[n].get('teaching_page')), None)

        # Only when both neighbours agree the page is impossible: one neighbour
        # could be the wrong one instead.
        if before is not None and after is not None and not (before < page < after):
            units[number]['teaching_page'] = None
            units[number]['needs_title_review'] = units[number].get('title_source') != 'contents'

    return units


def units_by_sequence(layout_pages, contents, taken=None):
    """Number the units by counting their exercises pages.

    Advanced Grammar in Use prints its unit number and its exercise numbers
    inside coloured discs, which are artwork: eleven of its hundred exercises
    pages give up a readable label and the rest give nothing, so neither the
    heading rule nor the label rule can find the units. The book itself is
    perfectly regular, though - one teaching page and one exercises page per
    unit, in order - and its contents list says how many units there are.

    So when the number of exercises pages matches the number of units the
    contents lists exactly, the nth exercises page is the nth unit. The
    equality is the whole safeguard: one page more or fewer and the mapping
    would be off by one somewhere, quietly filing every later unit's exercises
    under its neighbour, so nothing is assigned at all.
    """
    taken = taken or {}
    if not contents:
        return {}

    # How many units the book has, rather than how many the contents gave up:
    # a few entries are always lost to the scan, and the last number is what
    # says where the list ends.
    # One exercises page per unit, so counting them counts the units. The
    # contents is the check on that rather than the source of it: a scan always
    # loses entries - Advanced Grammar lost its first eight and Pronunciation in
    # Use Advanced its last eight - so the contents' largest number is a floor,
    # not the total. It has to fall inside the count and close to it, or the
    # pages being counted are not one per unit and nothing is assigned.
    pages = [i + 1 for i, page in enumerate(layout_pages) if is_exercises_page(page)]
    unit_count = len(pages)
    listed = max(contents)
    if unit_count == 0 or listed > unit_count or listed < 0.8 * unit_count:
        return {}

    claimed = {u['teaching_page'] for u in taken.values() if u.get('teaching_page')}

    found = {}
    for number, page in zip(range(1, unit_count + 1), pages):
        if number in taken or page - 1 < 1 or (page - 1) in claimed:
            continue
        title = contents.get(number)
        found[number] = {
            'number': number,
            'title': title or f'Unit {number}',
            'teaching_page': page - 1,
            'title_source': 'contents' if title else None,
            'needs_title_review': title is None,
        }
    return found


RE_EXERCISES_HEADING = re.compile(r'^\W{0,4}exercises?\b', re.I)


def is_exercises_page(page_text) -> bool:
    """Does this page open the unit's exercises?

    The heading is not always the first thing on the page: the pronunciation
    books print a running section header above it ("Section A Sounds and
    spelling"), so looking only at the first line found two exercises pages in
    a book that has sixty.
    """
    head = [ln.strip() for ln in page_text.splitlines() if ln.strip()][:3]
    return any(RE_EXERCISES_HEADING.match(ln) for ln in head)
# An exercise label at the start of a line, allowing for the box the scanned
# books print around it - the OCR reads its left edge as "|", "(" or "[".
# An exercise label at the start of a line. The scanned books print a box
# around it and the reader turns the box's edges into "|", "(" or "[", so those
# are allowed on either side of the number - without that, two thirds of Basic
# Grammar in Use's exercises are invisible.
RE_LABEL_ANCHORED = re.compile(
    r'^[ \t]{0,10}[|(\[]?[ \t]{0,2}(\d{1,3})\.(\d{1,2})[ \t]{0,2}[|)\]]?(?=[ \t]|$)', re.M)


def units_behind_exercises(layout_pages, taken):
    """Find a unit by its exercises when its own page does not name it.

    Basic Grammar in Use prints no unit number on its teaching pages at all -
    the number is in a coloured tab in the margin, which is artwork, not text.
    Every unit of it therefore arrived titleless and pageless, 113 of them,
    even though the book was read cleanly.

    The facing page gives it away. Exercises are numbered `<unit>.<n>`, so an
    exercises page states the unit outright, and the teaching page is the one
    before it. The title is then the first real line of that page, which in
    this series is the grammar point itself - "am/is/are", "I am doing
    (present continuous)".

    Only units that no other rule found are taken this way, and only when the
    page before is not already another unit's, so this can add units to a
    book but never move one.
    """
    found = {}
    claimed = {u['teaching_page'] for u in taken.values()}

    for i, page in enumerate(layout_pages):
        if not is_exercises_page(page):
            continue

        numbers = [int(m[0]) for m in RE_LABEL_ANCHORED.findall(page)]
        if not numbers:
            continue
        unit = Counter(numbers).most_common(1)[0][0]
        if unit in taken or unit in found or not (1 <= unit <= 200):
            continue

        teaching_page = i          # the page before, 1-based
        if teaching_page < 1 or teaching_page in claimed:
            continue

        title = teaching_title(layout_pages[teaching_page - 1])
        found[unit] = {
            'number': unit,
            'title': title or f'Unit {unit}',
            'teaching_page': teaching_page,
            'needs_title_review': title is None,
        }
        claimed.add(teaching_page)

    return found


def teaching_title(page_text):
    """The grammar point at the top of a teaching page, if it reads as one."""
    for line in page_text.splitlines():
        line = line.strip()
        if len(line) < 3 or len(line) > 70:
            continue
        letters = sum(c.isalpha() for c in line)
        # A line of scanner noise is mostly punctuation; a heading is mostly
        # letters.
        if letters < 3 or letters / len(line) < 0.55:
            continue
        if RE_FOOTER.search(line) or RE_EXERCISES_HEADING.match(line):
            continue
        # One word is a fragment, not a title. The scanner reads the first
        # words of a heading and loses the rest often enough that unit 1 of
        # Phrasal Verbs in Use came back titled "fan" and a Collocations unit
        # "Business" - each the opening of a real title, each useless on its
        # own and worse than saying the title is unknown.
        title = re.sub(r'\s{2,}', ' ', line)
        if len(title.split()) < 2:
            continue
        return title
    return None


def sections_on_page(runs):
    """Lettered sections: a run that is exactly one capital letter, paired with the
    title run sharing its vertical band. Returns [{letter,title,top}] in order."""
    out = []
    for r in runs:
        if not RE_SECTION_LETTER.match(r['text']):
            continue
        title = ''
        for c in runs:
            if c is r or c['left'] <= r['left']:
                continue
            if abs(c['top'] - r['top']) <= 12:
                title = c['text']
                break
        out.append({'letter': r['text'], 'title': title, 'top': r['top']})
    out.sort(key=lambda s: s['top'])
    # a page should not report the same letter twice
    seen, uniq = set(), []
    for s in out:
        if s['letter'] in seen:
            continue
        seen.add(s['letter'])
        uniq.append(s)
    return uniq


def glosses_on_page(page_text):
    """Pull the bracketed glosses off a page, each with the words before it.

    Glosses cannot be recovered from the reassembled section bodies. Those are
    built from pdftohtml runs, which split at every font change and, on a
    two-column page, interleave the columns - so a marginal note like
    "[NOT He/She born]" arrives as two runs with unrelated text between them and
    the regex has nothing contiguous to match. That is why the Advanced book
    captured 99% of its glosses and the other three about 20%: Advanced sets
    them inline in running prose, where one run holds the whole bracket, while
    the others set them as margin notes.

    pdftotext -layout keeps reading order, so the bracket survives intact here.
    The 60 characters before it are returned as an anchor, which is what lets a
    gloss be attributed back to the section it belongs to.

    @return list[tuple[str, str]] (gloss, preceding text)
    """
    out = []
    for m in RE_GLOSS.finditer(page_text):
        gloss = ' '.join(m.group(1).split())
        if len(gloss) < 3:
            continue
        before = page_text[max(0, m.start() - 60):m.start()]
        out.append((gloss, ' '.join(before.split())))
    return out


def attribute_gloss(anchor_text, sections):
    """Which section does this gloss belong to?

    Decided by the words in front of it: the section whose body shares the most
    of that anchor's trailing words owns the gloss. A gloss whose anchor matches
    nothing is returned unattributed rather than guessed at - a definition filed
    under the wrong headword teaches the wrong thing, which is worse than one
    that is merely uncategorised.
    """
    words = [w.lower().strip('.,;:!?()') for w in anchor_text.split()[-6:]]
    words = [w for w in words if len(w) > 2]

    if not words or not sections:
        return None

    best, score = None, 0
    for sec in sections:
        body = sec['body'].lower()
        hits = sum(1 for w in words if w in body)
        if hits > score:
            best, score = sec, hits

    # One incidental word in common is not evidence of ownership.
    return best if score >= 2 else None


def extract_unit(runs, unit, secs):
    """Attribute every bold run and body line to the section above it."""
    bounds = [(s['top'], s) for s in secs]
    def owner(top):
        cur = None
        for t, s in bounds:
            if top >= t - 4:
                cur = s
            else:
                break
        return cur

    per = {s['letter']: {'letter': s['letter'], 'title': s['title'],
                         'vocabulary': [], 'lines': []} for s in secs}
    unit_title_low = unit['title'].lower()

    for r in runs:
        sec = owner(r['top'])
        if sec is None:
            continue
        bucket = per[sec['letter']]
        line = r['text']
        if RE_FOOTER.search(line):
            continue
        if line != sec['title'] and line != sec['letter']:
            bucket['lines'].append(line)
        for b in r['bold']:
            b = unligature(b).strip(' .,;:')
            if not b or RE_NOISE.match(b) or len(b) > 60 or not is_headword(b):
                continue
            if b.lower() in (sec['title'] or '').lower() or b.lower() == unit_title_low:
                continue
            bucket['vocabulary'].append({'term': b, 'example': line})
    return per


def dedupe_vocab(items):
    seen, out = {}, []
    for it in items:
        k = it['term'].lower()
        if k in seen:
            # keep the longest example: it carries the most context
            if len(it['example']) > len(seen[k]['example']):
                seen[k]['example'] = it['example']
            continue
        seen[k] = dict(it)
        out.append(seen[k])
    return out


def split_labelled_blocks(page, unit_no):
    """Every "<unit>.<n>" label on a page owns the text up to the next label.

    Capturing the whole block keeps the numbered items under each rubric, which
    a rubric-only capture silently discards.
    """
    marks = []
    for m in RE_LABEL_ANCHORED.finditer(page):
        marks.append((m.start(), int(m.group(1)), int(m.group(2)), m.end()))
    out = {}
    for i, (start, u, n, body_at) in enumerate(marks):
        if u != unit_no:
            continue
        end = marks[i + 1][0] if i + 1 < len(marks) else len(page)
        block = page[body_at:end].rstrip()
        first, _, rest = block.partition('\n')
        out[n] = {
            'instructions': first.strip(),
            'body': block.strip(),
        }
    return out


def extract_exercises(layout_pages, unit_no, teaching_page, answer_start):
    """Exercise blocks live on the page(s) after the teaching page; the matching
    answers live in the answer-key run at the back. Both are captured whole."""
    ex, ans = {}, {}
    for i, page in enumerate(layout_pages):
        in_key = answer_start is not None and i >= answer_start
        if not in_key and teaching_page and not (teaching_page <= i + 1 <= teaching_page + 2):
            continue
        if not in_key and not teaching_page and answer_start is not None and i >= answer_start:
            continue
        blocks = split_labelled_blocks(page, unit_no)
        for num, blk in blocks.items():
            target = ans if in_key else ex
            if num not in target:
                target[num] = blk

    if not ex and teaching_page:
        whole = whole_exercises_page(layout_pages, teaching_page)
        if whole is not None:
            ex[1] = whole

    return ex, ans


def whole_exercises_page(layout_pages, teaching_page):
    """The unit's exercises page, kept entire when its labels cannot be read.

    Advanced Grammar in Use numbers its exercises inside coloured discs, which
    are artwork rather than text: eleven of its hundred exercises pages give up
    a readable label. Splitting the page into numbered drills is impossible
    there - but the drills themselves are on the page, and dropping a hundred
    pages of practice because their numbering was printed as a picture is not
    an option. The page is kept as one block, for review rather than for
    serving, and the disc's own exercise data supplies the numbered items.
    """
    index = teaching_page          # the page after, 0-based
    if index >= len(layout_pages):
        return None

    page = layout_pages[index]
    if not is_exercises_page(page):
        return None

    lines = [ln for ln in page.splitlines() if ln.strip()]
    rubric = next((ln.strip() for ln in lines
                   if not RE_EXERCISES_HEADING.match(ln.strip()) and len(ln.strip()) > 25), '')
    if not rubric:
        return None

    return {
        'instructions': rubric,
        'body': page.strip(),
        'unlabelled': True,
    }


def find_answer_start(layout_pages):
    """The page the answer key begins on, or None.

    Each series titles it differently - "Answer key", "Key", "Key to
    Exercises" - and a scan spaces the letters of a display heading out, so
    "Answer Key to Exercises" arrives with four spaces between its words.
    Getting this wrong costs the whole key: everything after the page is read
    as answers and everything before it as exercises.
    """
    heading = re.compile(
        r'^\W{0,4}(?:answers?\s+key|answers?|key)'
        r'(?:\s+to\s+(?:the\s+)?exercises)?\W{0,4}$',
        re.I)

    hits = []
    for i, page in enumerate(layout_pages):
        for line in page.strip().splitlines()[:6]:
            if heading.match(re.sub(r'\s+', ' ', line).strip()):
                hits.append(i)
                break

    if not hits:
        return None
    # The contents page names the key too. The real one is at the back.
    late = [h for h in hits if h > len(layout_pages) * 0.5]
    return late[0] if late else hits[0]


# Filenames the placement tool gives every recording, whatever the archive
# called it. See tools/place_audio.py for how each set is read.
#
#   U_014.B.002.mp3   unit 14, section B, third clip
#   U_014.B.p071.003  the same, for the set that numbers clips by printed page
#   APP_07.009.mp3    appendix 7
#   T_B017.mp3        CD B track 17 - how the pronunciation books cite audio
RE_AUDIO_UNIT = re.compile(
    r'^U_?(\d{1,3})(?:\.([A-H]))?(?:\.p(\d{1,3}))?(?:\.(\d{1,3}))?\.mp3$', re.I)
RE_AUDIO_TRACK = re.compile(r'^T_([A-E])(\d{1,3})\.mp3$', re.I)


def audio_map(book):
    """The recordings for each unit of this book.

    Only files whose unit the placement tool could read are attached here. The
    pronunciation sets are cited by CD track rather than by unit, and the
    interactive disc by an internal clip id, so those are carried in the
    inventory and mapped by the pages that reference them - not guessed from a
    filename that does not name a unit.
    """
    by_unit = defaultdict(list)
    if not book.get('audio'):
        return by_unit, []

    base = ROOT / book['audio']
    if not base.is_dir():
        return by_unit, []

    unattached = []
    for f in sorted(base.rglob('*.mp3')):
        rel = f.relative_to(ROOT).as_posix()
        m = RE_AUDIO_UNIT.match(f.name)
        if m:
            by_unit[int(m.group(1))].append({
                'path': rel,
                'extracted_path': rel,
                'exists': True,
                'section': (m.group(2) or '').upper() or None,
                'page': int(m.group(3)) if m.group(3) else None,
                'index': int(m.group(4)) if m.group(4) else None,
            })
            continue

        track = RE_AUDIO_TRACK.match(f.name)
        entry = {'path': rel, 'extracted_path': rel, 'exists': True, 'section': None}
        if track:
            entry['track'] = f'{track.group(1).upper()}{int(track.group(2))}'
        unattached.append(entry)

    for v in by_unit.values():
        v.sort(key=lambda a: (a['section'] or '', a['page'] or 0, a['index'] or 0))
    return by_unit, unattached


# How the pronunciation books cite a recording: the CD's letter and the track
# number, printed inside a small headphone icon beside the exercise.
RE_TRACK_MARKER = re.compile(r'(?<![A-Za-z0-9])([A-E])\s?(\d{1,3})(?![0-9A-Za-z])')


def attach_tracks(units, layout, unattached):
    """Give the pronunciation recordings to the units that cite them.

    These three books number their audio by CD track, not by unit, so a
    filename says "CD B, track 17" and nothing about where it belongs. The
    books themselves say: each exercise prints the track it needs beside it.

    The marker is set inside a headphone icon, which is artwork, so the reader
    gets perhaps half of them - 213 markers against 386 tracks in the Advanced
    book. Half is what is attached. The rest stay in the inventory without a
    unit rather than being spread over the units in proportion, because a
    recording played in the wrong lesson is worse than one a learner has to
    find for themselves.

    @return number of recordings attached
    """
    by_label = {}
    for entry in unattached:
        label = entry.get('track')
        if label:
            by_label.setdefault(label, entry)
    if not by_label:
        return 0

    attached = 0
    for unit in units:
        page = unit.get('source_page')
        if not page:
            continue

        # The unit's own spread: the teaching page and the exercises facing it.
        text = ' '.join(layout[i] for i in (page - 1, page)
                        if 0 <= i < len(layout))

        for letter, number in RE_TRACK_MARKER.findall(text):
            entry = by_label.get(f'{letter}{int(number)}')
            if entry is None or entry.get('unit') is not None:
                continue
            entry['unit'] = unit['number']
            unit['audio'].append({**entry, 'section': None})
            attached += 1

    return attached


def load_pages(book):
    """Everything the parsers need to read this book, however it was stored."""
    key = book['key']
    if book['pages'] == 'ocr':
        xml_pages, layout = load_ocr(key)
        return xml_pages, layout, {}

    pdf = ROOT / book['pdf']
    xml_pages = parse_pages(load_xml(pdf, key))
    return xml_pages, load_layout(pdf, key), load_word_lines(pdf, key)


def build(book):
    key = book['key']
    series = SERIES[book['series']]

    xml_pages, layout, repair = load_pages(book)
    answer_start = find_answer_start(layout)
    units_meta = find_units(layout[:answer_start] if answer_start else layout,
                            series['heading'])

    # The book's own contents list is a better title than anything a single
    # page yields, and on a scan it is much better - see titles_from_contents.
    # It fills the gaps everywhere, and on a scanned book it wins outright.
    # The same view of the contents the unit finder used, rather than one
    # derived from where the first unit turned out to be: a single unit read
    # onto page 2 would otherwise pull the horizon in front of the contents and
    # lose every title in the book.
    contents = titles_from_contents(layout)
    for number, meta in units_meta.items():
        listed = contents.get(number)
        if not listed:
            continue
        if book['pages'] == 'ocr' or meta.get('needs_title_review') or not meta.get('title'):
            meta['title'] = listed
            meta['needs_title_review'] = False
            meta['title_source'] = 'contents'
    audio, unattached_audio = audio_map(book)

    # Any unit number that owns exercise labels but whose heading the parser could
    # not read still exists in the book. Register it (flagged for review) so its
    # exercises, answers and audio are never orphaned.
    seen_in_exercises = set()
    for i, page in enumerate(layout[:answer_start] if answer_start else layout):
        for m in RE_LABEL_ANCHORED.finditer(page):
            u = int(m.group(1))
            if 1 <= u <= 200:
                seen_in_exercises.add(u)
    for u in sorted((seen_in_exercises | set(audio)) - set(units_meta)):
        if not (1 <= u <= 200):
            continue
        units_meta[u] = {
            'number': u,
            'title': f'Unit {u}',
            'teaching_page': None,
            'needs_title_review': True,
        }

    units = []
    for n in sorted(units_meta):
        meta = units_meta[n]
        runs = xml_pages.get(meta['teaching_page'], []) if meta['teaching_page'] else []
        secs = sections_on_page(runs)
        per = extract_unit(runs, meta, secs) if secs else {}
        ex, ans = extract_exercises(layout, n, meta['teaching_page'] or 0, answer_start)

        sections = []
        for s in secs:
            b = per[s['letter']]
            for v in b['vocabulary']:
                v['example'] = repair_line(v['example'], repair)
            vocab = dedupe_vocab(b['vocabulary'])
            body = '\n'.join(repair_line(ln, repair) for ln in b['lines'])
            sections.append({
                'letter': s['letter'],
                'title': s['title'],
                'body': body,
                'vocabulary': vocab,
                'glosses': [],
            })

        # Glosses come from the page in reading order, not from the
        # reassembled bodies - see glosses_on_page for why.
        page_index = (meta['teaching_page'] or 0) - 1
        page_text = layout[page_index] if 0 <= page_index < len(layout) else ''
        unplaced = []

        for gloss, anchor_text in glosses_on_page(page_text):
            target = attribute_gloss(anchor_text, sections)
            # The anchor travels with the gloss. Without it the importer has no
            # way to tell which headword a definition belongs to, and would be
            # back to guessing from a body whose word order the run-splitting
            # already destroyed.
            entry = {'text': gloss, 'anchor': anchor_text}
            if target is not None:
                target['glosses'].append(entry)
            else:
                unplaced.append(entry)

        units.append({
            'number': n,
            'title': meta['title'],
            'needs_title_review': meta.get('needs_title_review', False),
            'source_page': meta['teaching_page'],
            'sections': sections,
            'exercises': [{'number': k, **v} for k, v in sorted(ex.items())],
            'answers': [{'number': k, 'text': v['body'], 'instructions': v['instructions']}
                        for k, v in sorted(ans.items())],
            'audio': audio.get(n, []),
            # Glosses whose anchor matched no section. Kept at unit level rather
            # than dropped: a definition filed under the wrong headword teaches
            # the wrong thing, but one thrown away teaches nothing at all.
            'unplaced_glosses': unplaced,
            'vocabulary_count': sum(len(s['vocabulary']) for s in sections),
            'gloss_count': sum(len(s['glosses']) for s in sections) + len(unplaced),
        })

    pages_out = []
    for i, txt in enumerate(layout):
        body = '\n'.join(repair_line(ln, repair) for ln in txt.splitlines())
        pages_out.append({
            'number': i + 1,
            'text': body,
            'char_count': len(body),
            'is_answer_key': answer_start is not None and i >= answer_start,
        })

    attach_tracks(units, layout, unattached_audio)

    all_audio = []
    for unit_no, files in sorted(audio.items()):
        for a in files:
            all_audio.append({**a, 'unit': unit_no})
    all_audio.extend(unattached_audio)

    return {
        'key': key,
        'series': book['series'],
        'series_title': series['family'],
        'course_title': series['course'],
        'skill': series['skill'],
        'concept_source': series.get('concepts', 'headword'),
        'title': book['title'],
        'cefr': book['cefr'],
        'course': book['level'],
        'level': book['level'],
        'text_source': book['pages'],
        'source_pdf': book['pdf'],
        'source_audio': book.get('audio'),
        'copyright_status': 'owned',
        'answer_key_page': (answer_start + 1) if answer_start is not None else None,
        'pages': pages_out,
        'audio_inventory': all_audio,
        'units': units,
    }


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--book', action='append', default=[],
                    help='extract only this book key (repeatable)')
    ap.add_argument('--series', action='append', default=[],
                    help='extract only this series (repeatable)')
    args = ap.parse_args()

    wanted = [b for b in BOOKS
              if (not args.book or b['key'] in args.book)
              and (not args.series or b['series'] in args.series)]
    if not wanted:
        raise SystemExit('no book matched')

    outdir = ROOT / 'docs' / 'data' / 'curriculum'
    outdir.mkdir(parents=True, exist_ok=True)

    rows = []
    for book in wanted:
        try:
            data = build(book)
        except SystemExit as exc:
            print(f"{book['key']:28s} skipped: {exc}")
            continue
        (outdir / f'{data["key"]}.json').write_text(
            json.dumps(data, indent=1, ensure_ascii=False))
        rows.append((
            data['key'],
            len(data['units']),
            sum(len(u['sections']) for u in data['units']),
            sum(u['vocabulary_count'] for u in data['units']),
            sum(u.get('gloss_count', 0) for u in data['units']),
            sum(len(u['exercises']) for u in data['units']),
            sum(len(u['answers']) for u in data['units']),
            len(data['audio_inventory']),
        ))
        k, *n = rows[-1]
        print(f'{k:28s} units={n[0]:>3} sections={n[1]:>4} vocab={n[2]:>5} '
              f'glosses={n[3]:>4} exercises={n[4]:>4} answers={n[5]:>4} audio={n[6]:>5}')

    if len(rows) > 1:
        t = [sum(r[i] for r in rows) for i in range(1, 8)]
        print(f'{"TOTAL":28s} units={t[0]:>3} sections={t[1]:>4} vocab={t[2]:>5} '
              f'glosses={t[3]:>4} exercises={t[4]:>4} answers={t[5]:>4} audio={t[6]:>5}')


if __name__ == '__main__':
    main()
