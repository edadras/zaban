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

BOOKS = [
    ('elementary',  'A1-A2', 'Foundation',
     'sources/elementary_3rd.pdf', 'sources/elementary_3rd_audio.zip'),
    ('pre_int_int', 'A2-B1', 'Core',
     'sources/pre_intermediate_intermediate_4th.pdf',
     'sources/pre_intermediate_intermediate_4th_audio.zip'),
    ('upper_int',   'B2', 'Advancing',
     'sources/upper_intermediate_4th.pdf', 'sources/upper_intermediate_4th_audio.zip'),
    ('advanced',    'C1-C2', 'Mastery',
     'sources/advanced_3rd.pdf', 'sources/advanced_3rd_audio.zip'),
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
    return txt.read_text(encoding='utf-8', errors='replace').split('\f')


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


def extract_exercises(layout_pages, unit_no, teaching_page, answer_start):
    """Exercise instructions live on the page(s) after the teaching page; the
    matching answers live in the answer-key run at the back."""
    ex, ans = {}, {}
    for i, page in enumerate(layout_pages):
        in_key = answer_start is not None and i >= answer_start
        if not in_key and not (teaching_page <= i + 1 <= teaching_page + 2):
            continue
        for m in RE_EXERCISE.finditer(page):
            if int(m.group(1)) != unit_no:
                continue
            num = int(m.group(2))
            text = m.group(3).strip()
            if in_key:
                ans.setdefault(num, text)
            else:
                ex.setdefault(num, text)
    return ex, ans


def find_answer_start(layout_pages):
    hits = [i for i, p in enumerate(layout_pages)
            if re.search(r'^\s*(Answer key|Key|Answers)\s*$',
                         '\n'.join(p.strip().splitlines()[:6]), re.M | re.I)]
    if not hits:
        return None
    late = [h for h in hits if h > len(layout_pages) * 0.5]
    return late[0] if late else hits[0]


def audio_map(zip_path):
    by_unit = defaultdict(list)
    if not zip_path.exists():
        return by_unit
    with zipfile.ZipFile(zip_path) as z:
        names = [n for n in z.namelist() if n.lower().endswith('.mp3')]
    pat = re.compile(r'U_?(\d{1,3})(?:[._]([A-H]|\d{1,2}))?\.mp3$', re.I)
    for n in names:
        m = pat.search(n.split('/')[-1])
        if not m:
            continue
        by_unit[int(m.group(1))].append({
            'path': n,
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
    audio = audio_map(ROOT / zip_rel)

    units = []
    for n in sorted(units_meta):
        meta = units_meta[n]
        runs = xml_pages.get(meta['teaching_page'], [])
        secs = sections_on_page(runs)
        per = extract_unit(runs, meta, secs) if secs else {}
        ex, ans = extract_exercises(layout, n, meta['teaching_page'], answer_start)

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
            'source_page': meta['teaching_page'],
            'sections': sections,
            'exercises': [{'number': k, 'instructions': v} for k, v in sorted(ex.items())],
            'answers': [{'number': k, 'text': v} for k, v in sorted(ans.items())],
            'audio': audio.get(n, []),
            'vocabulary_count': sum(len(s['vocabulary']) for s in sections),
        })

    return {
        'key': key, 'cefr': cefr, 'course': course,
        'source_pdf': pdf_rel, 'source_audio': zip_rel,
        'copyright_status': 'owned',
        'pages': len(layout),
        'answer_key_page': (answer_start + 1) if answer_start is not None else None,
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
