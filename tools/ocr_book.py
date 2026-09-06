#!/usr/bin/env python3
"""
Read a scanned book off its page images.

Ten of the twelve new books have no usable text in them. Nine carry no text
layer at all - one or two characters per page, which is the page number - and
`Pronunciation in Use Advanced` carries something worse: an old OCR layer whose
letters are spaced apart, so "dictionary" is stored as "d i cti o n a ry".
Twenty per cent of its tokens are stray single letters. Neither can be parsed,
and no amount of work on the parser changes that.

What comes out of here is shaped to be a drop-in for `pdftotext -layout`,
because that is what the rest of the pipeline reads. Each page yields:

  * `text` - the page laid out on a character grid, columns still side by side,
    so the sentence miner's gutter detection and the "<unit>.<n>" exercise
    labels survive exactly as they do in a born-digital book;
  * `words` - every word with its box, its confidence and whether it is bold;
  * `mean_conf` - how sure the reader was, so a page that came out badly can be
    found rather than silently trusted.

Bold matters more than it looks. The "in Use" series sets every taught item in
bold, and that typography is the only authoritative headword list these books
contain. Tesseract 5 dropped font-attribute reporting, so it is recovered here
from the ink itself: a bold word puts more black inside its own box than a
regular word of the same size on the same page.

Resumable by page, because a book is two thousand images and a run should never
have to start over.
"""
import argparse
import json
import os
import re
import shutil
import statistics
import subprocess
import sys
import tempfile
from concurrent.futures import ProcessPoolExecutor
from html.parser import HTMLParser
from pathlib import Path

# Stamps the scans carry that are not part of the book. They are watermarks
# belonging to whoever digitised the file, and they have no business appearing
# in a lesson, in a search index, or in anything a learner reads.
WATERMARKS = [
    re.compile(r'\b(?:www\.)?ir\s*language\s*\.?\s*com\b', re.I),
    re.compile(r'\b(?:www\.)?shop\.?tabaenglish\.?ir\b', re.I),
    re.compile(r'\blanguagecentre\b', re.I),
    re.compile(r'\btabaenglish\b', re.I),
]


def scrub(text: str) -> str:
    for pat in WATERMARKS:
        text = pat.sub('', text)
    return text


def is_watermark(word: str) -> bool:
    stripped = word.strip(' .,;:()[]|')
    if not stripped:
        return False
    return any(pat.search(stripped) for pat in WATERMARKS)


class HocrReader(HTMLParser):
    """Pull ocr_line / ocrx_word boxes out of tesseract's hOCR.

    hOCR is XHTML in principle and not always in practice, so it is scanned
    with a forgiving parser rather than handed to an XML one: a single stray
    entity in a page of two thousand would otherwise lose the page.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.lines = []
        self._line = None
        self._word = None
        self._buf = []

    @staticmethod
    def _bbox(title):
        m = re.search(r'bbox (\d+) (\d+) (\d+) (\d+)', title or '')
        return tuple(int(g) for g in m.groups()) if m else None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        cls = a.get('class', '')
        title = a.get('title', '')

        if cls in ('ocr_line', 'ocr_header', 'ocr_caption', 'ocr_textfloat'):
            box = self._bbox(title)
            size = re.search(r'x_size ([\d.]+)', title)
            self._line = {
                'bbox': box,
                'x_size': float(size.group(1)) if size else None,
                'words': [],
            }
        elif cls == 'ocrx_word' and self._line is not None:
            conf = re.search(r'x_wconf (\d+)', title)
            self._word = {
                'bbox': self._bbox(title),
                'conf': int(conf.group(1)) if conf else 0,
            }
            self._buf = []

    def handle_data(self, data):
        if self._word is not None:
            self._buf.append(data)

    def handle_endtag(self, tag):
        if self._word is not None and tag == 'span':
            text = ''.join(self._buf).strip()
            if text and self._word['bbox']:
                self._word['text'] = text
                self._line['words'].append(self._word)
            self._word = None
            self._buf = []
            return
        if self._line is not None and tag in ('span', 'div', 'p') and self._line['words']:
            self.lines.append(self._line)
            self._line = None
        elif self._line is not None and tag in ('span', 'div', 'p'):
            self._line = None


# Below this the reader is guessing. The scans carry a digitiser's logo on
# every page, and a logo is not text: it comes back as "Ps", "nae", "|" with
# confidences in the twenties, which then read as words in a lesson. Genuine
# text on these pages sits in the eighties and nineties, so the floor discards
# the marks without touching the book.
MIN_WORD_CONFIDENCE = 45

# Page segmentation for the reversed pass; see where it is used.
REVERSED_PSM = 6


def read_hocr(path: Path):
    parser = HocrReader()
    parser.feed(path.read_text(encoding='utf-8', errors='replace'))
    return [ln for ln in parser.lines if ln['words']]


def ink_ratios(png: Path, lines):
    """How black each word is inside its own box.

    Returns one float per word, or None when the image cannot be opened. A
    bold word is not bigger than a regular word, it is heavier: the glyph
    strokes are thicker, so more of the box is ink. Comparing a word only with
    words of a similar size on the same page keeps a heading from being called
    bold merely for being large.
    """
    try:
        from PIL import Image
        import numpy as np
    except ImportError:
        return None

    try:
        img = Image.open(png).convert('L')
    except Exception:
        return None

    arr = np.asarray(img)
    # Otsu would be more careful, but these are clean bilevel-ish scans and a
    # midpoint threshold separates ink from paper on every page tested.
    dark = arr < 160
    h, w = dark.shape

    out = []
    for ln in lines:
        for word in ln['words']:
            x0, y0, x1, y1 = word['bbox']
            x0, y0 = max(0, x0), max(0, y0)
            x1, y1 = min(w, x1), min(h, y1)
            if x1 <= x0 or y1 <= y0:
                out.append(None)
                continue
            box = dark[y0:y1, x0:x1]
            out.append(float(box.mean()))
    return out


def mark_bold(lines, ratios):
    """Flag the words whose ink is heavy for their size band on this page."""
    if ratios is None:
        for ln in lines:
            for word in ln['words']:
                word['bold'] = False
        return

    flat = [w for ln in lines for w in ln['words']]
    for word, ratio in zip(flat, ratios):
        word['ink'] = ratio

    # Group by rounded height so a caption is compared with captions and body
    # text with body text.
    bands = {}
    for word in flat:
        if word.get('ink') is None:
            continue
        x0, y0, x1, y1 = word['bbox']
        # Short words carry too few strokes for the ratio to mean anything.
        if len(word['text']) < 3 or (x1 - x0) < 8 or (y1 - y0) < 8:
            continue
        bands.setdefault(round((y1 - y0) / 4), []).append(word)

    for band in bands.values():
        if len(band) < 8:
            continue
        median = statistics.median(w['ink'] for w in band)
        if median <= 0:
            continue
        for word in band:
            word['bold'] = word['ink'] / median >= 1.22

    for word in flat:
        word.setdefault('bold', False)
        word.pop('ink', None)


def lay_out(lines):
    """Put the page back on a character grid, columns still side by side.

    This is the whole point of the exercise. `pdftotext -layout` is what every
    parser downstream was written against: it keeps a two-column page as two
    columns on one line, which is what lets the gutter be found and the columns
    read separately. Tesseract's own text output reflows instead, which merges
    the columns into nonsense.

    The grid step is the median width of one character on the page, so a word
    lands in the column its ink is actually in.
    """
    widths = []
    for ln in lines:
        for word in ln['words']:
            x0, _, x1, _ = word['bbox']
            if len(word['text']) >= 3 and x1 > x0:
                widths.append((x1 - x0) / len(word['text']))
    step = statistics.median(widths) if widths else 12.0
    if step <= 0:
        step = 12.0

    heights = [ln['bbox'][3] - ln['bbox'][1] for ln in lines if ln['bbox']]
    line_height = statistics.median(heights) if heights else 40.0

    ordered = sorted(lines, key=lambda ln: (ln['bbox'][1] if ln['bbox'] else 0))

    out = []
    previous_bottom = None
    for ln in ordered:
        top = ln['bbox'][1] if ln['bbox'] else 0
        bottom = ln['bbox'][3] if ln['bbox'] else top

        if previous_bottom is not None:
            gap = top - previous_bottom
            # A paragraph break is a gap noticeably larger than the leading.
            for _ in range(min(3, int(gap // max(1.0, line_height * 0.9)))):
                out.append('')

        row = []
        for word in sorted(ln['words'], key=lambda w: w['bbox'][0]):
            text = word['text']
            if is_watermark(text) or word['conf'] < MIN_WORD_CONFIDENCE:
                continue
            column = int(round(word['bbox'][0] / step))
            # Two words never share a column and never touch: a grid collision
            # that merely appends would glue them into one token, and
            # "Thisunit" is not a word any parser downstream can recover from.
            if column <= len(row):
                column = len(row) + 1
            row.extend(' ' * (column - len(row)))
            row.extend(text)
        line_text = scrub(''.join(row).rstrip())
        out.append(line_text)
        previous_bottom = bottom

    # Collapse the runs of blanks the gap rule can produce at a column break.
    text = '\n'.join(out)
    return re.sub(r'\n{4,}', '\n\n\n', text).strip('\n')


# Tesseract is built against OpenMP and defaults to one thread per core. Run
# several of them at once and the threads spend their time spinning on each
# other rather than reading: a page that takes three seconds alone took over
# nine minutes with three workers, all four cores pinned and nothing coming
# out. Parallelism belongs at the process level here, so each reader is held to
# one thread and the pool provides the concurrency.
TESSERACT_ENV = {**os.environ, 'OMP_THREAD_LIMIT': '1', 'OMP_NUM_THREADS': '1'}


def render(pdf: Path, page: int, dpi: int, into: Path) -> Path:
    """One page of the PDF as a greyscale PNG."""
    prefix = into / 'page'
    subprocess.run(
        ['pdftoppm', '-r', str(dpi), '-gray', '-png',
         '-f', str(page), '-l', str(page), str(pdf), str(prefix)],
        check=True, capture_output=True,
    )
    found = sorted(into.glob('page-*.png'))
    if not found:
        raise RuntimeError(f'pdftoppm produced no image for page {page}')
    return found[0]


def in_bands(line, bands) -> bool:
    """Does this line sit inside a strip the second pass has already claimed?"""
    if not bands or not line['bbox']:
        return False
    middle = (line['bbox'][1] + line['bbox'][3]) / 2
    return any(top <= middle <= bottom for top, bottom in bands)


def read_page(png: Path, base: Path, psm: int):
    """Everything tesseract can see in one image."""
    subprocess.run(
        ['tesseract', str(png), str(base), '--psm', str(psm), 'hocr'],
        check=True, capture_output=True, env=TESSERACT_ENV,
    )
    return read_hocr(base.with_suffix('.hocr'))


def solid_blocks(arr):
    """Bounding boxes of the filled dark blocks on a page.

    Found on a coarse grid rather than pixel by pixel: a cell counts as solid
    when almost all of it is ink, and touching solid cells are one block. That
    finds a navy panel and ignores a bold word, which is what is wanted -
    letters have paper between their strokes, a printed panel does not.
    """
    import numpy as np

    cell = 8
    dark = arr < 110
    height, width = dark.shape
    rows, cols = height // cell, width // cell
    if rows < 2 or cols < 2:
        return []

    grid = dark[:rows * cell, :cols * cell].reshape(rows, cell, cols, cell).mean(axis=(1, 3))
    solid = grid > 0.92

    seen = np.zeros_like(solid, dtype=bool)
    boxes = []
    for r in range(rows):
        for c in range(cols):
            if not solid[r, c] or seen[r, c]:
                continue
            stack = [(r, c)]
            seen[r, c] = True
            r0 = r1 = r
            c0 = c1 = c
            cells = 0
            while stack:
                y, x = stack.pop()
                cells += 1
                r0, r1 = min(r0, y), max(r1, y)
                c0, c1 = min(c0, x), max(c1, x)
                for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
                    ny, nx = y + dy, x + dx
                    if 0 <= ny < rows and 0 <= nx < cols and solid[ny, nx] and not seen[ny, nx]:
                        seen[ny, nx] = True
                        stack.append((ny, nx))

            box_h = (r1 - r0 + 1)
            box_w = (c1 - c0 + 1)
            # Big enough to hold reversed type, and actually rectangular: a
            # ragged blob of the same area is a photograph, not a panel.
            if box_h < 3 or box_w < 3 or cells / (box_h * box_w) < 0.7:
                continue
            boxes.append((c0 * cell, r0 * cell, (c1 + 1) * cell, (r1 + 1) * cell))

    return boxes


def heading_bands(arr, boxes):
    """The horizontal strips of the page that contain a filled panel.

    A band is padded a little above and below so the type set beside the panel
    is inside it too - the section letter and the section title belong on one
    line, and the parser downstream pairs them by sharing a line.
    """
    bands = []
    for x0, y0, x1, y1 in boxes:
        pad = max(6, (y1 - y0) // 3)
        bands.append((max(0, y0 - pad), min(arr.shape[0], y1 + pad)))
    bands.sort()

    merged = []
    for top, bottom in bands:
        if merged and top <= merged[-1][1]:
            merged[-1] = (merged[-1][0], max(merged[-1][1], bottom))
        else:
            merged.append((top, bottom))
    return merged


def prepare_images(png: Path, work: Path):
    """Split off the strips of the page that are printed as reversed type.

    The series prints its section spine as reversed type - a white letter in a
    navy pill, with the section title in colour beside it - and a filled panel
    defeats a reader twice over. The panel reads as a picture, so the letter in
    it is lost; and because it shares a line with the title, it drags the title
    down with it. On the first page tried, "A  Make" came back as "Ps nae"
    while every other word on the page was read correctly.

    The obvious repair - paint the panels out and read what is left - costs
    more than it saves: with the table's header panel gone, the reader stopped
    recognising the table and dropped nine of its thirteen rows, a hundred
    words of the lesson. So the page itself is left exactly as it is, and only
    the strips containing a panel are lifted out, inverted, and read again.
    The main pass keeps everything outside those strips; the second pass owns
    everything inside them. Neither reads the other's ground.

    @return (bands image or None, list of (top, bottom) strips)
    """
    try:
        from PIL import Image
        import numpy as np
    except ImportError:
        return None, []

    try:
        arr = np.asarray(Image.open(png).convert('L'))
    except Exception:
        return None, []

    if (arr < 100).mean() > 0.30:
        # A page that is mostly ink is a photograph or a scanning fault, and
        # inverting it invents words.
        return None, []

    boxes = solid_blocks(arr)
    if not boxes:
        return None, []

    bands = heading_bands(arr, boxes)
    if not bands:
        return None, []

    strip = np.full_like(arr, 255)
    for top, bottom in bands:
        strip[top:bottom] = arr[top:bottom]
    for x0, y0, x1, y1 in boxes:
        strip[y0:y1, x0:x1] = 255 - arr[y0:y1, x0:x1]

    path = work / 'bands.png'
    Image.fromarray(strip).save(path)
    return path, bands


def ocr_page(args):
    pdf, page, dpi, out_dir, psm = args
    target = out_dir / f'{page:05d}.json'
    if target.exists():
        return page, 'skipped', 0.0

    work = Path(tempfile.mkdtemp(prefix=f'ocr{page}-'))
    try:
        png = render(Path(pdf), page, dpi, work)
        bands_png, bands = prepare_images(png, work)

        lines = read_page(png, work / 'main', psm)
        mark_bold(lines, ink_ratios(png, lines))
        lines = [ln for ln in lines if not in_bands(ln, bands)]

        if bands_png is not None:
            # Reversed type is display type: a section marker or a table
            # heading, never body text, so it is emphatic by definition.
            # The reversed image is not a page: it is a scatter of pills and
            # panel headings on white. Automatic layout analysis looks at that
            # and throws the isolated glyphs away - the section markers were
            # lost exactly this way - so it is read as one block instead.
            for line in read_page(bands_png, work / 'bands', REVERSED_PSM):
                for word in line['words']:
                    word['bold'] = True
                    # A section marker is one capital in a pill, and with no
                    # neighbouring text to set the scale a reader has nothing
                    # to judge case by: the marker for section A comes back as
                    # "a". Reversed single letters in these books are always
                    # capitals, and the parser downstream matches on capitals.
                    if len(word['text']) == 1 and word['text'].isalpha():
                        word['text'] = word['text'].upper()
                lines.append(line)

        text = lay_out(lines)

        words = []
        confs = []
        for ln in lines:
            for word in ln['words']:
                if is_watermark(word['text']):
                    continue
                x0, y0, x1, y1 = word['bbox']
                words.append({
                    'text': word['text'], 'x0': x0, 'y0': y0, 'x1': x1, 'y1': y1,
                    'conf': word['conf'], 'bold': word['bold'],
                })
                confs.append(word['conf'])

        mean_conf = round(sum(confs) / len(confs), 2) if confs else 0.0
        payload = {
            'number': page,
            'text': text,
            'char_count': len(text),
            'mean_conf': mean_conf,
            'word_count': len(words),
            'words': words,
        }
        tmp = target.with_suffix('.tmp')
        tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding='utf-8')
        tmp.replace(target)
        return page, 'ocr', mean_conf
    except Exception as exc:                     # noqa: BLE001 - reported, not raised
        return page, f'failed: {exc}', 0.0
    finally:
        shutil.rmtree(work, ignore_errors=True)


def dpi_for(pdf: Path, target_width: int, fallback: int) -> int:
    """The resolution that gives a page of about `target_width` pixels.

    Rendering resolution is meaningless on its own, because these PDFs do not
    agree on how big a page is. Most declare A4 - 595 points across - and 300
    dpi gives them a 2,479 pixel page, which is what a reader wants. One
    declares its pages 1,918 points across, over three times that, so the same
    300 dpi produced a 91-megapixel image of the same amount of text: fifteen
    times the work for no more detail, and slow enough to hold up the whole
    run.

    Asking for a width instead makes every book arrive at the reader looking
    the same size, whatever its own idea of a page is.
    """
    try:
        out = subprocess.run(['pdfinfo', str(pdf)], capture_output=True, text=True,
                             check=True).stdout
        m = re.search(r'^Page size:\s+([\d.]+) x', out, re.M)
        if not m:
            return fallback
        inches = float(m.group(1)) / 72.0
        if inches <= 0:
            return fallback
        return max(72, min(600, int(round(target_width / inches))))
    except Exception:                              # noqa: BLE001 - fall back, do not fail
        return fallback


def page_count(pdf: Path) -> int:
    out = subprocess.run(['pdfinfo', str(pdf)], capture_output=True, text=True, check=True).stdout
    m = re.search(r'^Pages:\s+(\d+)', out, re.M)
    if not m:
        raise RuntimeError(f'cannot read a page count from {pdf}')
    return int(m.group(1))


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('pdf')
    ap.add_argument('--out', required=True, help='directory for the per-page JSON')
    ap.add_argument('--dpi', type=int, default=300,
                    help='fallback resolution when the page size cannot be read')
    ap.add_argument('--target-width', type=int, default=2480,
                    help='pixels across the page; 0 to use --dpi as given')
    ap.add_argument('--psm', type=int, default=1, help='tesseract page segmentation mode')
    ap.add_argument('--jobs', type=int, default=max(1, (os.cpu_count() or 2) - 1))
    ap.add_argument('--first', type=int, default=1)
    ap.add_argument('--last', type=int, default=0)
    args = ap.parse_args()

    pdf = Path(args.pdf)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    dpi = args.dpi if args.target_width <= 0 else dpi_for(pdf, args.target_width, args.dpi)
    last = args.last or page_count(pdf)
    pages = list(range(args.first, last + 1))
    todo = [p for p in pages if not (out / f'{p:05d}.json').exists()]

    print(f'{pdf.name}: {len(pages)} pages, {len(todo)} to read, '
          f'{args.jobs} workers, {dpi} dpi')
    if not todo:
        print('   already complete')
        return

    done = 0
    confs = []
    failures = []
    work = [(str(pdf), p, dpi, out, args.psm) for p in todo]
    with ProcessPoolExecutor(max_workers=args.jobs) as pool:
        for page, status, conf in pool.map(ocr_page, work, chunksize=1):
            done += 1
            if status == 'ocr':
                confs.append(conf)
            elif status.startswith('failed'):
                failures.append((page, status))
            if done % 25 == 0 or done == len(todo):
                mean = f'{statistics.mean(confs):.1f}' if confs else '-'
                print(f'   {done}/{len(todo)}  mean confidence {mean}', flush=True)

    if failures:
        print(f'   {len(failures)} page(s) failed:')
        for page, why in failures[:10]:
            print(f'     p{page}: {why}')
        sys.exit(1)


if __name__ == '__main__':
    main()
