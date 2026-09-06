#!/usr/bin/env python3
"""
Move each book's recordings into the project under names that say what they are.

The archives name their files six different ways. One set buries the unit in a
directory ("01 Unit 1/a/1A_3.mp3"), one puts it in the filename ("UNIT 01 A.mp3"),
three number tracks per CD with no unit anywhere ("CD 01/Track No17.mp3"), and
the interactive disc names its clips by an internal asset id that only its own
exercise files can resolve. Two of them stamp a shop's address into every
directory name.

So nothing here is copied as it stands. Every file is renamed to one shape:

    U_014.B.002.mp3     unit 14, section B, third clip of that section
    APP_07.009.mp3      appendix 7, ninth clip
    T_B017.mp3          CD B, track 17 - the way the book itself cites it
    9611.mp3            an interactive-disc clip, cited by the disc's own id

and a manifest records where each one came from, so the renaming can always be
checked against the archive rather than believed.

The manifest is the point as much as the files are: a recording whose unit is
guessed is worse than one that is missing, because it will be played to a
learner in the wrong lesson. Files whose position cannot be read are moved with
the rest and listed under `unresolved`, never quietly dropped and never
attached to a unit on a hunch.
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Directory and file names that carry a digitiser's address. It is not part of
# the book and does not travel into the project.
STAMPED = re.compile(r'(www\.)?(irlanguage|languagecentre|shop\.?tabaenglish)', re.I)


def unit_section_index(path: Path, root: Path):
    """Unit and section from the directory, clip number from the filename.

    Basic Grammar in Use ships one directory per unit and one lettered
    subdirectory per section - `034 Unit 34/b/` - and inside them the files are
    named after the printed page rather than the unit: `Page71_3.mp3`. So the
    position comes from the path, which states it plainly, and only the clip's
    order within the section comes from the name.

    A few files repeat unit and section in the name (`1A_3.mp3`). Where they
    do, the two are cross-checked, and a file that contradicts the directory it
    sits in is left unplaced rather than filed under either reading.
    """
    parent = re.search(r'(\d{1,3})\s+Unit\s+(\d{1,3})', str(path.parent), re.I)
    section_dir = re.search(r'/([A-Ha-h])$', str(path.parent).replace('\\', '/'))
    name = path.stem

    named = re.match(r'^(\d{1,3})([A-Ha-h])[_-](\d{1,3})$', name)
    if named:
        unit, section, index = int(named.group(1)), named.group(2).upper(), int(named.group(3))
        if parent and int(parent.group(2)) != unit:
            return None
        found = {'unit': unit, 'section': section, 'index': index}
        if section_dir and section_dir.group(1).upper() != section:
            # Unit 30 of this set has six sections in five directories, so its
            # last two disagree with the letters they sit under. The filename
            # names the section outright and the directory only implies it, so
            # the filename is taken - and the disagreement is recorded rather
            # than smoothed over, because a clip in the wrong section is played
            # in the wrong lesson.
            found['section_conflict'] = section_dir.group(1).upper()
        return found

    # `appendix 1/1.2/Page073_4.mp3` - the appendix is the directory's, and the
    # numbered subdirectory is the entry within it. Both are needed: appendix 1
    # entry 1 and appendix 1 entry 2 each have their own page 73.
    appendix = re.search(r'appendix\s+(\d{1,2})', str(path.parent), re.I)
    if appendix:
        # The entry is the directory directly under the appendix, written
        # either as `1.2` or as a bare `2` depending on the appendix.
        entry = re.search(r'/(?:\d{1,2}\.)?(\d{1,3})$', str(path.parent).replace('\\\\', '/'))
        return {
            'appendix': int(appendix.group(1)),
            'entry': int(entry.group(1)) if entry else None,
            'index': clip_in(name),
            'page': page_in(name),
        }

    if parent and section_dir:
        return {
            'unit': int(parent.group(2)),
            'section': section_dir.group(1).upper(),
            'index': clip_in(name),
            'page': page_in(name),
        }
    return None


def page_in(name: str):
    """The printed page these files are named after, kept as evidence."""
    m = re.match(r'^Page\s*(\d{1,3})', name, re.I)
    return int(m.group(1)) if m else None


def clip_in(name: str) -> int:
    """Which clip of its page this is.

    `Page073_4.mp3` is the fourth; `Page073.mp3` is the only one, and is
    numbered zero so it cannot be confused with the first of a numbered run.
    A few files are named by the clip alone - `2.mp3` - and mean the same.
    """
    m = re.search(r'_(\d{1,3})$', name)
    if m:
        return int(m.group(1))
    if re.fullmatch(r'\d{1,3}', name):
        return int(name)
    return 0


def unit_in_name(path: Path, _root: Path):
    """`UNIT 01 A.mp3`, and `z 7.7 British English.mp3` for the appendices."""
    name = path.stem
    m = re.match(r'^UNIT\s*(\d{1,3})\s*([A-H])?$', name, re.I)
    if m:
        return {'unit': int(m.group(1)), 'section': (m.group(2) or '').upper() or None}

    # `z 1.3 Spelling.mp3`, and `z 1.3-2 Continued.mp3` when one appendix
    # entry runs to several clips. Without the part number the continuations
    # all claim the same name and get suffixed as if they were duplicates.
    m = re.match(r'^z\s*(\d{1,2})\.(\d{1,3})(?:-(\d{1,3}))?\s', name, re.I)
    if m:
        return {
            'appendix': int(m.group(1)),
            'index': int(m.group(2)),
            'part': int(m.group(3)) if m.group(3) else 1,
        }
    return None


def cd_track(path: Path, _root: Path):
    """A CD track, cited by the book as a letter and a number.

    The three pronunciation sets are recorded on numbered CDs and the books
    cite them as "B17" - CD two, track seventeen. Two of the sets put that
    label in the filename already; the third only numbers the track, so the
    letter comes from the CD directory. Which unit a track belongs to is not
    in either, and is not invented here: it is read from the book's own pages
    once they have been extracted.
    """
    disc = re.search(r'CD\s*0?(\d{1,2})', str(path.parent), re.I)
    if not disc:
        return None
    letter = chr(ord('A') + int(disc.group(1)) - 1)

    m = re.search(r'\b([A-Ea-e])\s*(\d{1,3})$', path.stem)
    if m:
        named = m.group(1).upper()
        # The filename's own letter wins only when it agrees with the disc it
        # is on; a disagreement means one of the two is wrong and neither is
        # trustworthy enough to file by.
        if named != letter:
            return None
        return {'disc': letter, 'track': int(m.group(2))}

    m = re.search(r'(?:Track\s*No\.?\s*)(\d{1,3})$', path.stem, re.I)
    if m:
        return {'disc': letter, 'track': int(m.group(1))}
    return None


def disc_asset(path: Path, _root: Path):
    """The interactive disc's own clip id, which its exercise files cite."""
    # The disc's exercise files cite their clips by filename, literally -
    # "learning_objects/assets/111.mp3" - so the id is whatever the file is
    # called. Most are plain numbers, some carry a letter ("4812e"), and a
    # handful were patched late and say so ("new9321e", "8524replace"). None
    # of that is normalised: a renamed clip is a clip the exercise cannot find.
    if path.parent.name == 'assets' and path.stem:
        return {'asset': path.stem}

    # The disc also carries a phonemic chart: forty-five recordings, one per
    # sound, each with the two example words the chart prints beside it. They
    # are numbered from zero in their own directory and would collide with the
    # exercise clips if filed by number alone.
    if path.parent.name == 'phoneme' and re.fullmatch(r'\d{1,3}', path.stem):
        return {'phoneme': int(path.stem)}
    return None


def canonical(placement) -> str:
    if placement is None:
        return ''
    if 'asset' in placement:
        return f"{placement['asset']}.mp3"
    if 'phoneme' in placement:
        return f"PHONEME_{placement['phoneme']:02d}.mp3"
    if 'appendix' in placement:
        page = placement.get('page')
        entry = placement.get('entry')
        if page is not None:
            head = f"APP_{placement['appendix']:02d}"
            if entry is not None:
                head += f'.{entry:02d}'
            return f"{head}.p{page:03d}.{placement.get('index') or 0:03d}.mp3"
        stem = f"APP_{placement['appendix']:02d}.{placement.get('index', 0):03d}"
        part = placement.get('part')
        return f'{stem}.{part:03d}.mp3' if part else f'{stem}.mp3'
    if 'disc' in placement:
        return f"T_{placement['disc']}{placement['track']:03d}.mp3"

    unit = f"U_{placement['unit']:03d}"
    section = placement.get('section')
    index = placement.get('index')
    page = placement.get('page')
    if section and page is not None:
        # index 0 means "the only clip of this page"; see clip_in.
        # One set names its clips after the printed page rather than the unit,
        # and a section can hold several pages, so the page is part of what
        # identifies the clip: without it `Page71.mp3` and `Page72.mp3` both
        # claim to be the first clip of the section.
        return f'{unit}.{section}.p{page:03d}.{index or 0:03d}.mp3'
    if section and index is not None:
        return f'{unit}.{section}.{index:03d}.mp3'
    if section:
        return f'{unit}.{section}.mp3'
    if index is not None:
        return f'{unit}.{index:03d}.mp3'
    return f'{unit}.mp3'


SETS = {
    'grammar_basic': unit_section_index,
    'grammar_intermediate': unit_in_name,
    'grammar_advanced': disc_asset,
    'pronunciation_elementary': cd_track,
    'pronunciation_intermediate': cd_track,
    'pronunciation_advanced': cd_track,
}


def transcode(source: Path, target: Path) -> bool:
    """Windows Media to MP3.

    One of the six sets was published as WMA, which neither the application nor
    the project's own duration reader can open, so it would have arrived as 351
    files nothing could play. Re-encoded once here at a rate that is
    transparent for speech.
    """
    result = subprocess.run(
        ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', str(source),
         '-codec:a', 'libmp3lame', '-b:a', '128k', '-ar', '44100', str(target)],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        print(f'   ! {source.name}: {result.stderr.strip()[:160]}', file=sys.stderr)
        return False
    return True


def place(key: str, source_dir: Path, move: bool):
    reader = SETS[key]
    target_dir = ROOT / 'sources' / 'audio' / key
    target_dir.mkdir(parents=True, exist_ok=True)

    files = sorted(
        p for p in source_dir.rglob('*')
        if p.is_file() and p.suffix.lower() in ('.mp3', '.wma')
    )

    placed, unresolved, collisions, failed = [], [], [], []
    used = {}

    for path in files:
        placement = reader(path, source_dir)
        name = canonical(placement)
        if not name:
            name = f'UNPLACED_{STAMPED.sub("", path.stem).strip(" _-") or path.stem}.mp3'
            unresolved.append(str(path.relative_to(source_dir)))

        if name in used and used[name] != str(path):
            stem, dot, ext = name.rpartition('.')
            n = 2
            while f'{stem}-{n}.{ext}' in used:
                n += 1
            collisions.append({'wanted': name, 'given': f'{stem}-{n}.{ext}',
                               'file': str(path.relative_to(source_dir))})
            name = f'{stem}-{n}.{ext}'
        used[name] = str(path)

        target = target_dir / name
        if not target.exists():
            if path.suffix.lower() == '.wma':
                if not transcode(path, target):
                    failed.append(str(path.relative_to(source_dir)))
                    continue
                if move:
                    path.unlink()
            elif move:
                shutil.move(str(path), target)
            else:
                shutil.copy2(path, target)

        placed.append({
            'file': name,
            'from': str(path.relative_to(source_dir)),
            **(placement or {}),
        })

    manifest = {
        'key': key,
        'source': str(source_dir.relative_to(ROOT)) if source_dir.is_relative_to(ROOT) else str(source_dir),
        'files': len(placed),
        'unresolved': unresolved,
        'collisions': collisions,
        'transcode_failures': failed,
        'placed': placed,
    }
    (target_dir / 'manifest.json').write_text(
        json.dumps(manifest, indent=1, ensure_ascii=False), encoding='utf-8')

    print(f'{key:28s} {len(placed):>5} placed  '
          f'{len(unresolved):>4} unresolved  {len(collisions):>3} renamed  '
          f'{len(failed):>3} failed')
    return manifest


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('key', choices=sorted(SETS))
    ap.add_argument('source', help='directory the archive was extracted to')
    ap.add_argument('--move', action='store_true',
                    help='move rather than copy (the archives are kept in version control)')
    args = ap.parse_args()
    place(args.key, Path(args.source), args.move)


if __name__ == '__main__':
    main()
