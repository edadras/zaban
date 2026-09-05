#!/usr/bin/env python3
"""
Structural analyser for the English Vocabulary in Use source PDFs.

Produces the Phase 2 curriculum/content extraction report and doubles as the
reference implementation for ingestion stages 3-7 (structure identification,
semantic segmentation, concept extraction, exercise extraction, answer
extraction). Each document is analysed independently - the books share a family
resemblance but differ in typesetting, gloss convention and section depth.
"""
import json
import re
import subprocess
import sys
import zipfile
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path('/home/user/zaban')
CACHE = Path('/tmp/extract')

BOOKS = [
    ('elementary',   'A1-A2', 'English_Vocabulary_in_Use_Elementary_3rd_Edition_www_languagecentre.pdf',
                              'English_Vocabulary_in_Use_Elementary_Audio_3rd_Edition_www_languagecentre.zip'),
    ('pre_int_int',  'A2-B1', 'English_Vocabulary_in_Use_Pre_Intermediate&Intermediate_4th_Edition.pdf',
                              'English_Vocabulary_in_Use_Pre_Intermediate&Intermediate_Audio_4th.zip'),
    ('upper_int',    'B2',    'English_Vocabulary_in_Use_Upper_Intermediate_4th_Edition_www_languagecentre.pdf',
                              'English_Vocabulary_in_Use_Upper_Intermediate_Audio_4th_Edition_www.zip'),
    ('advanced',     'C1-C2', 'English_Vocabulary_in_Use_Advanced_3rd_Edition_www_languagecentre.pdf',
                              'English_Vocabulary_in_Use_Advanced_Audio_3rd_Edition_www_languagecentre.zip'),
]

# An exercise label like "12.3" at the start of a line is the single most reliable
# structural marker in this series: it encodes unit and exercise number together.
RE_EXERCISE = re.compile(r'^\s{0,6}(\d{1,3})\.(\d{1,2})\s+(?=\S)', re.M)
# Section headers are a lone capital letter followed by a title.
RE_SECTION = re.compile(r'^\s{0,12}([A-H])\s{2,}([A-Z‘’\'][^\n]{2,80})$', re.M)
# Unit heading: number then title, on a teaching page.
RE_UNIT_HEAD = re.compile(r'^\s{0,10}(\d{1,3})\s{2,}([A-Z‘’\'][^\n]{2,70})$', re.M)
# Inline gloss used heavily in the Advanced edition: term ... [definition]
RE_GLOSS = re.compile(r'\[([^\[\]]{3,160})\]')
RE_PAGE_FOOTER = re.compile(r'English Vocabulary in Use\s+\w+', re.I)


def pages_of(txt_path):
    return txt_path.read_text(encoding='utf-8', errors='replace').split('\f')


def extract_text(pdf, txt):
    if not txt.exists():
        subprocess.run(['pdftotext', '-layout', str(pdf), str(txt)], check=True)
    return txt


def find_answer_key(pages):
    """Locate the answer-key run: the tail region where exercise labels cluster
    densely without any accompanying teaching prose."""
    starts = []
    for i, p in enumerate(pages):
        head = '\n'.join(p.strip().splitlines()[:6])
        if re.search(r'^\s*(Answer key|Key|Answers)\s*$', head, re.M | re.I):
            starts.append(i)
    if not starts:
        return None, None
    start = max(starts) if starts[-1] > len(pages) * 0.5 else starts[0]
    # extend to the last page still dominated by answer-shaped lines
    end = start
    for i in range(start, len(pages)):
        labels = len(RE_EXERCISE.findall(pages[i]))
        if labels >= 2 or i - end <= 1:
            end = i
    return start, end


def spacing_defect_rate(pages):
    """pdftotext loses inter-word spaces on some justified lines in this series.
    Rate of long alphabetic runs is the signal that a page needs vision fallback."""
    bad = total = 0
    for p in pages:
        for line in p.splitlines():
            line = line.strip()
            if len(line) < 20:
                continue
            total += 1
            # a "word" of 18+ letters is almost certainly collapsed spacing
            if re.search(r'[A-Za-z]{18,}', line):
                bad += 1
    return bad, total


def analyse(key, cefr, pdf_name, zip_name):
    pdf = ROOT / pdf_name
    txt = extract_text(pdf, CACHE / f'{key}.txt')
    pages = pages_of(txt)

    ak_start, ak_end = find_answer_key(pages)
    body_end = ak_start if ak_start else len(pages)

    units = {}            # unit_no -> {title, pages, sections, exercises}
    exercises = defaultdict(set)
    answers = defaultdict(set)
    sections = defaultdict(set)
    glosses = 0
    front_matter = 0

    for i, page in enumerate(pages):
        labels = RE_EXERCISE.findall(page)
        in_answer_key = ak_start is not None and i >= ak_start

        for unit_s, ex_s in labels:
            u = int(unit_s)
            if not (1 <= u <= 200):
                continue
            (answers if in_answer_key else exercises)[u].add(int(ex_s))

        if in_answer_key:
            continue

        glosses += len(RE_GLOSS.findall(page))

        # A teaching page opens with "<unit number>  <title>" as its very first
        # non-empty line and carries lettered section headers below it. Requiring
        # the heading to be first is what keeps the two-column contents pages and
        # the exercise pages out of the unit map.
        lines = page.splitlines()
        head = [ln for ln in lines if ln.strip()][:3]
        if not head:
            front_matter += 1
            continue

        u = title = None
        for idx, ln in enumerate(head):
            # Variant A: "<n>  <title>" as the opening line.
            # Variant B: "<n> <title>" with a single space (Upper-Int unit 62).
            m = re.match(r'^\s{0,12}(\d{1,3})\s{1,}([A-Z‘’\'][^\n]{2,70})$', ln)
            if m:
                u, title = int(m.group(1)), m.group(2).strip()
                # Variant C: the title wrapped and its first half sits on the line
                # above, with the number leading the second half (Advanced unit 8).
                if idx > 0:
                    prev = head[idx - 1].strip()
                    if (prev and not re.match(r'^\d', prev) and prev[0].isupper()
                            and not prev.endswith('.') and len(prev) < 70):
                        title = f'{prev} {title}'
                break
            # Variant D: a "Study unit" banner precedes the number (Pre-Int 1-4).
            m = re.match(r'^\s*unit\s+(\d{1,3})\s{1,}([A-Z‘’\'][^\n]{2,70})$', ln, re.I)
            if m:
                u, title = int(m.group(1)), m.group(2).strip()
                break

        if u is None or not (1 <= u <= 200):
            continue
        # drop a trailing page number and any second column that bled in
        title = re.sub(r'\s{3,}\d{1,3}\s*$', '', title).strip()
        title = re.sub(r'\s{3,}\d{1,3}\s+.*$', '', title).strip()
        title = re.sub(r'\s{3,}.*$', '', title).strip()
        if len(title) < 3 or RE_PAGE_FOOTER.search(title):
            continue

        page_sections = {mm.group(1) for mm in RE_SECTION.finditer(page)}
        if not page_sections:
            # no lettered sections: only trust it if the unit's exercises follow
            continue
        sections[u] |= page_sections
        if u not in units:
            units[u] = {'title': title, 'page': i + 1}

    bad_lines, total_lines = spacing_defect_rate(pages[:body_end])

    # ---- audio ----
    audio = {'files': 0, 'units_referenced': set(), 'unmapped': [], 'pattern': None,
             'folders': set()}
    zp = ROOT / zip_name
    if zp.exists():
        with zipfile.ZipFile(zp) as z:
            names = [n for n in z.namelist() if n.lower().endswith('.mp3')]
        audio['files'] = len(names)
        pat = re.compile(r'U_?(\d{1,3})(?:[._]([A-H]|\d{1,2}))?\.mp3$', re.I)
        for n in names:
            base = n.split('/')[-1]
            if '/' in n.strip('/'):
                parts = n.strip('/').split('/')
                if len(parts) > 2:
                    audio['folders'].add(parts[1])
            m = pat.search(base)
            if m:
                audio['units_referenced'].add(int(m.group(1)))
            else:
                audio['unmapped'].append(base)
        audio['pattern'] = 'U_<unit>.<section>.mp3'

    unit_nos = sorted(units)
    all_ex_units = sorted(set(exercises) | set(answers))
    return {
        'key': key, 'cefr': cefr, 'pdf': pdf_name, 'zip': zip_name,
        'pages_total': len(pages),
        'pages_body': body_end,
        'answer_key_pages': None if ak_start is None else [ak_start + 1, ak_end + 1],
        'front_matter_pages': front_matter,
        'units_found': len(units),
        'unit_range': [min(unit_nos), max(unit_nos)] if unit_nos else None,
        'unit_titles': {u: units[u]['title'] for u in unit_nos},
        'units_with_exercises': len(exercises),
        'exercise_items': sum(len(v) for v in exercises.values()),
        'units_with_answers': len(answers),
        'answer_items': sum(len(v) for v in answers.values()),
        'sections_total': sum(len(v) for v in sections.values()),
        'units_with_sections': len(sections),
        'inline_glosses': glosses,
        'exercise_units_missing_answers': sorted(set(exercises) - set(answers)),
        'answer_units_missing_exercises': sorted(set(answers) - set(exercises)),
        'spacing_defect_lines': bad_lines,
        'spacing_defect_total': total_lines,
        'spacing_defect_pct': round(100 * bad_lines / total_lines, 2) if total_lines else 0,
        'audio_files': audio['files'],
        'audio_units': sorted(audio['units_referenced']),
        'audio_unmapped': audio['unmapped'],
        'audio_pattern': audio['pattern'],
        'audio_folders': sorted(audio['folders']),
        'audio_units_without_book_unit': sorted(set(audio['units_referenced']) - set(all_ex_units)),
        'book_units_without_audio': sorted(set(all_ex_units) - set(audio['units_referenced'])),
    }


def main():
    out = [analyse(*b) for b in BOOKS]
    Path('/tmp/extract/report.json').write_text(json.dumps(out, indent=2, default=list))
    for r in out:
        print(f"===== {r['key']}  ({r['cefr']}) =====")
        print(f"  pages            {r['pages_total']} total, {r['pages_body']} body, "
              f"answer key {r['answer_key_pages']}")
        print(f"  units            {r['units_found']} titled, range {r['unit_range']}")
        print(f"  sections         {r['sections_total']} across {r['units_with_sections']} units")
        print(f"  exercises        {r['exercise_items']} items in {r['units_with_exercises']} units")
        print(f"  answers          {r['answer_items']} items in {r['units_with_answers']} units")
        print(f"  inline glosses   {r['inline_glosses']}")
        print(f"  audio            {r['audio_files']} mp3, pattern {r['audio_pattern']}, "
              f"units {len(r['audio_units'])}")
        if r['audio_folders']:
            print(f"  audio folders    {r['audio_folders']}")
        print(f"  text defects     {r['spacing_defect_pct']}% of lines "
              f"({r['spacing_defect_lines']}/{r['spacing_defect_total']}) lost word spacing")
        if r['exercise_units_missing_answers']:
            print(f"  !! units w/o answers: {r['exercise_units_missing_answers'][:12]}")
        if r['audio_units_without_book_unit']:
            print(f"  !! audio units not in book: {r['audio_units_without_book_unit'][:12]}")
        if r['book_units_without_audio']:
            print(f"  !! book units w/o audio: {r['book_units_without_audio'][:12]}")
        if r['audio_unmapped']:
            print(f"  !! unmapped audio files: {r['audio_unmapped'][:5]}")
        print()


if __name__ == '__main__':
    main()
