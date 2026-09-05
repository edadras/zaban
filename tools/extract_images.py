#!/usr/bin/env python3
"""
Image extractor for the source books.

The four books store artwork two different ways, so extraction is per-book:

  * Elementary / Pre-Intermediate / Upper-Intermediate are page-image PDFs - each
    page is one large scan with a text layer over it. Those page images are worth
    keeping: they back the vision fallback for pages the text parser could not
    read, and they are the artwork the exercises refer to.
  * Advanced is a vector PDF carrying ~3,130 image objects, almost all of which
    are 1-2px colour swatches and rules. Only objects above a real-illustration
    threshold are kept.

Emits sources/images/<book>/ plus a manifest mapping every image to its page.
"""
import json
import re
import shutil
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path('/home/user/zaban')
OUT = ROOT / 'sources' / 'images'

BOOKS = [
    ('elementary', 'sources/elementary_3rd.pdf'),
    ('pre_intermediate_intermediate', 'sources/pre_intermediate_intermediate_4th.pdf'),
    ('upper_intermediate', 'sources/upper_intermediate_4th.pdf'),
    ('advanced', 'sources/advanced_3rd.pdf'),
]

# Below this an "image" is a rule, swatch or anti-aliasing artefact, not artwork.
MIN_W = MIN_H = 100
# Ignore alpha masks; they are companions to a real image, not content themselves.
SKIP_TYPES = {'smask', 'stencil'}


def listing(pdf):
    out = subprocess.run(['pdfimages', '-list', str(pdf)],
                         capture_output=True, text=True).stdout.splitlines()
    rows = []
    for line in out[2:]:
        parts = line.split()
        if len(parts) < 6 or not parts[0].isdigit():
            continue
        rows.append({
            'page': int(parts[0]), 'num': int(parts[1]), 'type': parts[2],
            'width': int(parts[3]), 'height': int(parts[4]),
        })
    return rows


def extract(book, pdf_rel):
    pdf = ROOT / pdf_rel
    dest = OUT / book
    if dest.exists():
        shutil.rmtree(dest)
    dest.mkdir(parents=True, exist_ok=True)

    rows = listing(pdf)
    keep = {(r['page'], r['num']) for r in rows
            if r['type'] not in SKIP_TYPES and r['width'] >= MIN_W and r['height'] >= MIN_H}
    if not keep:
        return {'book': book, 'images': [], 'skipped': len(rows)}

    tmp = dest / '_raw'
    tmp.mkdir(exist_ok=True)
    # -p puts the page number in the filename; -all keeps each stream in its
    # native encoding so JPEG pages are not needlessly re-encoded.
    subprocess.run(['pdfimages', '-all', '-p', str(pdf), str(tmp / 'img')],
                   check=True, capture_output=True)

    by_page = defaultdict(int)
    manifest = []
    pat = re.compile(r'img-(\d+)-(\d+)\.(\w+)$')
    for f in sorted(tmp.iterdir()):
        m = pat.search(f.name)
        if not m:
            f.unlink(missing_ok=True)
            continue
        page, num, ext = int(m.group(1)), int(m.group(2)), m.group(3)
        if (page, num) not in keep:
            f.unlink(missing_ok=True)
            continue
        by_page[page] += 1
        idx = by_page[page]
        rel_name = f'p{page:04d}_{idx:02d}.{ext}'
        target = dest / rel_name
        f.rename(target)
        meta = next((r for r in rows if r['page'] == page and r['num'] == num), {})
        manifest.append({
            'path': f'sources/images/{book}/{rel_name}',
            'page': page,
            'index': idx,
            'width': meta.get('width'),
            'height': meta.get('height'),
            'bytes': target.stat().st_size,
            # A scan covering most of the page is the page itself, not a spot illustration.
            'is_page_scan': (meta.get('width', 0) >= 700 and meta.get('height', 0) >= 900),
        })
    shutil.rmtree(tmp, ignore_errors=True)
    return {'book': book, 'images': manifest, 'skipped': len(rows) - len(manifest)}


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    all_out = {}
    for book, pdf in BOOKS:
        res = extract(book, pdf)
        all_out[book] = res
        n = len(res['images'])
        scans = sum(1 for i in res['images'] if i['is_page_scan'])
        size = sum(i['bytes'] for i in res['images']) / 1048576
        print(f"{book:32s} kept={n:>4} (page scans {scans:>3}, spot art {n-scans:>3})  "
              f"skipped={res['skipped']:>4}  {size:>7.1f} MB")
    man = ROOT / 'docs' / 'data' / 'images.json'
    man.parent.mkdir(parents=True, exist_ok=True)
    man.write_text(json.dumps(all_out, indent=1))
    tot = sum(len(v['images']) for v in all_out.values())
    mb = sum(i['bytes'] for v in all_out.values() for i in v['images']) / 1048576
    print(f"{'TOTAL':32s} kept={tot:>4}  {mb:>7.1f} MB   manifest: docs/data/images.json")


if __name__ == '__main__':
    main()
