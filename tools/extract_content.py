#!/usr/bin/env python3
"""
Content extractor for the English Vocabulary in Use sources.

Turns each book into structured educational objects ready for database import.
Where analyze_sources.py reports *what* is in the books, this produces the actual
payload: units, lettered sections, the target vocabulary each section teaches,
worked examples, exercise instructions, the answer key, and the audio map.

Target vocabulary is recovered from typography rather than guessed: the series
sets every taught item in bold, so bold runs in the PDF's text layer are the
authoritative headword list. Each run is attributed to a section by vertical
position on the page.
"""
import html
import json
import re
import subprocess
import sys
import zipfile
from collections import defaultdict
from pathlib import Path
from xml.etree import ElementTree

ROOT = Path('/home/user/zaban')
CACHE = Path('/tmp/extract')

# (key, cefr span, course label, pdf, audio directory)
#
# The course label names the LEVEL, and it must keep naming the level.
#
# An earlier pass renamed these to Foundation / Core / Advancing / Mastery,
# which broke two things at once. Searching the corpus for "elementary" or
# "advanced" - the words everyone actually uses for these books, and the words
# in their own filenames - returned nothing, so both looked empty when they were
# full. Worse, "Advancing" was the upper-intermediate book, so a search for
# "Advanc" landed confidently on the wrong one.
#
# A label that quietly points at a different book than its name suggests is a
# worse failure than an ugly label, so these track the filenames exactly.
BOOKS = [
    ('elementary',  'A1-A2', 'Elementary',
     'sources/elementary_3rd.pdf', 'sources/audio/elementary'),
    ('pre_int_int', 'A2-B1', 'Pre-intermediate and Intermediate',
     'sources/pre_intermediate_intermediate_4th.pdf',
     'sources/audio/pre_intermediate_intermediate'),
    ('upper_int',   'B2', 'Upper-intermediate',
     'sources/upper_intermediate_4th.pdf', 'sources/audio/upper_intermediate'),
    ('advanced',    'C1-C2', 'Advanced',
     'sources/advanced_3rd.pdf', 'sources/audio/advanced'),
]

RE_EXERCISE = re.compile(r'^\s{0,6}(\d{1,3})\.(\d{1,2})\s+(.*)$', re.M)
RE_GLOSS = re.compile(r'\[([^\[\]]{3,200})\]')
RE_SECTION_LETTER = re.compile(r'^[A-H]$')
RE_FOOTER = re.compile(r'English Vocabulary in Use', re.I)
# Bold runs that are structural furniture rather than taught language.
RE_NOISE = re.compile(r'^(?:\d+|[A-H]|Common|mistakes|Language help|Tip|Note|Exercises|'
                      r'Answer key|Follow[- ]up|Study unit)\.?$', re.I)


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


def repair_line(text, repair):
    """Restore spacing on a line that lost it, leaving correct lines untouched."""
    if not re.search(r'[A-Za-z]{16,}', text):
        return text
    fixed = repair.get(re.sub(r'\s+', '', text))
    return fixed if fixed else text


def load_layout(pdf, key):
    CACHE.mkdir(parents=True, exist_ok=True)
    txt = CACHE / f'{key}.txt'
    if not txt.exists():
        subprocess.run(['pdftotext', '-layout', str(pdf), str(txt)], check=True)
    pages = txt.read_text(encoding='utf-8', errors='replace').split('\f')
    # the final form feed yields a trailing empty element that is not a page
    if pages and not pages[-1].strip():
        pages.pop()
    return pages


def node_text(el):
    return html.unescape(''.join(el.itertext())).strip()


def bold_spans(el):
    """Bold fragments inside a <text> node, including nested <b><i>."""
    out = []
    for b in el.iter():
        if b.tag == 'b':
            t = html.unescape(''.join(b.itertext())).strip()
            if t:
                out.append(t)
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


def find_units(layout_pages):
    """Reuse the heading rules proven in analyze_sources.py: returns
    unit_no -> teaching page index (1-based)."""
    units = {}
    for i, page in enumerate(layout_pages):
        head = [ln for ln in page.splitlines() if ln.strip()][:3]
        if not head:
            continue
        u = title = None
        for idx, ln in enumerate(head):
            m = re.match(r'^\s{0,12}(\d{1,3})\s{1,}([A-Z‘’\'][^\n]{2,70})$', ln)
            if m:
                u, title = int(m.group(1)), m.group(2).strip()
                if idx > 0:
                    prev = head[idx - 1].strip()
                    if (prev and not re.match(r'^\d', prev) and prev[:1].isupper()
                            and not prev.endswith('.') and len(prev) < 70):
                        title = f'{prev} {title}'
                break
            m = re.match(r'^\s*unit\s+(\d{1,3})\s{1,}([A-Z‘’\'][^\n]{2,70})$', ln, re.I)
            if m:
                u, title = int(m.group(1)), m.group(2).strip()
                break
        if u is None or not (1 <= u <= 200):
            continue
        title = re.sub(r'\s{3,}.*$', '', title).strip()
        if len(title) < 3 or RE_FOOTER.search(title):
            continue
        if u not in units:
            units[u] = {'number': u, 'title': title, 'teaching_page': i + 1}
    return units


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
            b = b.strip(' .,;:')
            if not b or RE_NOISE.match(b) or len(b) > 60:
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
    for m in re.finditer(r'^\s{0,6}(\d{1,3})\.(\d{1,2})\s+', page, re.M):
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
    return ex, ans


def find_answer_start(layout_pages):
    hits = [i for i, p in enumerate(layout_pages)
            if re.search(r'^\s*(Answer key|Key|Answers)\s*$',
                         '\n'.join(p.strip().splitlines()[:6]), re.M | re.I)]
    if not hits:
        return None
    late = [h for h in hits if h > len(layout_pages) * 0.5]
    return late[0] if late else hits[0]


# Where each book's audio was unpacked to inside the project.
AUDIO_DIRS = {
    'elementary': 'sources/audio/elementary',
    'pre_int_int': 'sources/audio/pre_intermediate_intermediate',
    'upper_int': 'sources/audio/upper_intermediate',
    'advanced': 'sources/audio/advanced',
}


def audio_map(_unused, book_key):
    """Scan the extracted audio tree for this book.

    The mp3 files live in the project under sources/audio/<book>/, so the
    inventory is read from disk rather than from an archive.
    """
    by_unit = defaultdict(list)
    base = ROOT / AUDIO_DIRS[book_key]
    if not base.is_dir():
        return by_unit
    pat = re.compile(r'U_?(\d{1,3})(?:[._]([A-H]|\d{1,2}))?\.mp3$', re.I)
    for f in sorted(base.rglob('*.mp3')):
        m = pat.search(f.name)
        if not m:
            continue
        rel = f.relative_to(ROOT).as_posix()
        by_unit[int(m.group(1))].append({
            'path': rel,
            'extracted_path': rel,
            'exists': True,
            'section': (m.group(2) or '').upper() or None,
        })
    for v in by_unit.values():
        v.sort(key=lambda a: (a['section'] or ''))
    return by_unit


def build(key, cefr, course, pdf_rel, zip_rel):
    pdf = ROOT / pdf_rel
    root = load_xml(pdf, key)
    xml_pages = parse_pages(root)
    layout = load_layout(pdf, key)
    repair = load_word_lines(pdf, key)
    answer_start = find_answer_start(layout)
    units_meta = find_units(layout[:answer_start] if answer_start else layout)
    audio = audio_map(None, key)

    # Any unit number that owns exercise labels but whose heading the parser could
    # not read still exists in the book. Register it (flagged for review) so its
    # exercises, answers and audio are never orphaned.
    seen_in_exercises = set()
    for i, page in enumerate(layout[:answer_start] if answer_start else layout):
        for m in re.finditer(r'^\s{0,6}(\d{1,3})\.(\d{1,2})\s+', page, re.M):
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
            glosses = [g.strip() for g in RE_GLOSS.findall(body)]
            sections.append({
                'letter': s['letter'],
                'title': s['title'],
                'body': body,
                'vocabulary': vocab,
                'glosses': glosses,
            })

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
            'vocabulary_count': sum(len(s['vocabulary']) for s in sections),
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

    all_audio = []
    for unit_no, files in sorted(audio.items()):
        for a in files:
            all_audio.append({**a, 'unit': unit_no})

    return {
        'key': key, 'cefr': cefr, 'course': course,
        'source_pdf': pdf_rel, 'source_audio': zip_rel,
        'copyright_status': 'owned',
        'pages': len(layout),
        'answer_key_page': (answer_start + 1) if answer_start is not None else None,
        'pages': pages_out,
        'audio_inventory': all_audio,
        'units': units,
    }


def main():
    outdir = ROOT / 'docs' / 'data' / 'curriculum'
    outdir.mkdir(parents=True, exist_ok=True)
    grand = {}
    for b in BOOKS:
        data = build(*b)
        (outdir / f'{data["key"]}.json').write_text(
            json.dumps(data, indent=1, ensure_ascii=False))
        v = sum(u['vocabulary_count'] for u in data['units'])
        s = sum(len(u['sections']) for u in data['units'])
        e = sum(len(u['exercises']) for u in data['units'])
        a = sum(len(u['answers']) for u in data['units'])
        au = sum(len(u['audio']) for u in data['units'])
        g = sum(len(sec['glosses']) for u in data['units'] for sec in u['sections'])
        grand[data['key']] = (len(data['units']), s, v, g, e, a, au)
        print(f"{data['key']:14s} units={len(data['units']):>3} sections={s:>4} "
              f"vocab={v:>5} glosses={g:>4} exercises={e:>4} answers={a:>4} audio={au:>4}")
    t = [sum(x[i] for x in grand.values()) for i in range(7)]
    print(f"{'TOTAL':14s} units={t[0]:>3} sections={t[1]:>4} vocab={t[2]:>5} "
          f"glosses={t[3]:>4} exercises={t[4]:>4} answers={t[5]:>4} audio={t[6]:>4}")


if __name__ == '__main__':
    main()
