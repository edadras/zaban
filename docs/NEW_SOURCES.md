# The twelve new books

Extracted from the `new` branch on 2026-09-06, read, and imported. This is what
came out of the archives, what state each book turned out to be in, and what
had to be built to use it.

## Correction to the first survey

The first pass through these files sorted them by how many characters
`pdftotext` returned, and got one of them wrong. `Pronunciation in Use
Advanced` returns 484,237 characters and was counted as a book with a text
layer. It is not. What it carries is an old OCR layer with the letters spaced
apart, so "dictionary" is stored as `d i cti o n a ry` and `/stɔːk/` as
`/st:): ki`. **A fifth of its tokens are stray single letters.**

Measured rather than eyeballed, on three samples from each book:

| Book | Stray single letters | Words in a dictionary |
|---|---|---|
| Grammar in Use — Intermediate | 0.8% | 95% |
| Phrasal Verbs in Use — Advanced | 0.7% | 97% |
| **Pronunciation in Use — Advanced** | **20.4%** | 89% |
| the other nine | — | no text at all |

So two books had a usable text layer, not three, and ten had to be read from
their page images. Re-read with `tools/ocr_book.py`, the same page of
Pronunciation in Use Advanced comes back as *"Use a dictionary with IPA to help
you match the words with their pronunciations."*

## What is in the corpus now

| Series | Book | Pages | Text from | Units |
|---|---|---|---|---|
| Vocabulary | Elementary | 176 | text layer | 60 |
| Vocabulary | Pre-intermediate and Intermediate | 265 | text layer | 100 |
| Vocabulary | Upper-intermediate | 280 | text layer | 101 |
| Vocabulary | Advanced | 303 | text layer | 101 |
| Grammar | Basic | 319 | OCR | 113 |
| Grammar | Intermediate | 394 | text layer | 145 |
| Grammar | Advanced | 306 | OCR | 99 |
| Pronunciation | Elementary | 168 | OCR | 49 |
| Pronunciation | Intermediate | 201 | OCR | 62 |
| Pronunciation | Advanced | 191 | OCR | 60 |
| Phrasal Verbs | Intermediate | 202 | OCR | 69 |
| Phrasal Verbs | Advanced | 194 | text layer | 60 |
| Collocations | Intermediate | 193 | OCR | 60 |
| Collocations | Advanced | 189 | OCR | 60 |
| Idioms | Intermediate | 181 | OCR | 58 |
| Idioms | Advanced | 182 | OCR | 60 |

Six series where there were one, 1,257 units where there were 362, and 10.8
million characters of source text. Grammar is the largest gap this closes: the
corpus had **zero** grammar concepts and zero grammar items, while claiming a
grammar level in every placement report.

Ten of those books were read from their page images: 2,132 pages, 924,538
words, at a mean word confidence between 77 and 90 depending on the scan. 107
pages - five per cent - came back below 70, and 63 of those are in Basic
Grammar in Use, which teaches through pictures. Every page carries its own
confidence, so a weak one can be found rather than silently trusted.

## Reading a scanned page

`tools/ocr_book.py` renders each page and reads it, and returns it in the shape
the rest of the pipeline already expects — the page laid out on a character
grid with its columns still side by side, plus every word with its box, its
confidence and whether it is bold. Three things in it were not obvious.

**Tesseract fights itself.** It is built against OpenMP and takes a thread per
core. Run four readers at once and they spend their time spinning on each
other: a page that reads in three seconds took over nine minutes, with all four
cores pinned and nothing coming out. Each reader is now held to one thread and
the pool provides the concurrency.

**Reversed type defeats a reader twice.** The series prints its section spine
as a white letter in a navy pill. The panel reads as a picture, so the letter
in it is lost — and because the panel shares a line with the section title, it
drags that down too: `A  Make` came back as `Ps nae` while every other word on
the page read correctly. Painting the panels out costs more than it saves; with
the table's header gone, the reader stopped recognising the table and dropped
nine of its thirteen rows. So the page is left exactly as it is and only the
strips containing a panel are lifted out, inverted and read again.

**Bold is the headword list.** These books set every taught item in bold, and
Tesseract 5 no longer reports font weight. It is recovered from the ink: a bold
word fills more of its own box than a regular word of the same size on the same
page. Checked against the printed page, the detection is exact on the
Collocations table — every phrase the book bolds, and nothing else.

Render resolution is set by page width rather than fixed, because these PDFs do
not agree on how big a page is. Most declare A4; one declares its pages 1,918
points across, so a fixed 300 dpi produced a 91-megapixel image of the same
amount of text — fifteen times the work for no more detail.

## Finding the units

Four rules, each added because a book broke the one before it.

* **Grammar in Use** prints `Unit` on its own line with the number below, and
  puts the first half of a long title beside the word. Read with the
  number-first rule it has no units at all.
* **"60 units of vocabulary reference and practice"** on a cover reads exactly
  like unit 60. A heading page now has to come after the contents list — except
  that Phrasal Verbs unit 11 is prose on a page with no other marker, so a
  second round takes back any unit whose page falls between its neighbours',
  which a cover never does.
* **Basic Grammar in Use prints no unit number on a teaching page at all.** The
  number is in a coloured tab, which is artwork. All 113 units arrived
  titleless. The facing page gives it away: exercises are numbered
  `<unit>.<n>`, so the exercises page names the unit and the teaching page is
  the one before it.
* **Advanced Grammar prints its exercise numbers in coloured discs too**, and
  only eleven of its hundred exercises pages give up a readable label. The book
  is regular — one teaching page and one exercises page per unit — and its
  contents says how many units there are, so when the exercises pages number
  exactly as many as the units, the nth is the nth. That equality is the whole
  safeguard.
* **Pronunciation in Use Elementary** loses about three unit numbers in four to
  a small pale figure beside a large title. Every unit that did come through
  sat on page 2n+9, so the pitch is voted on and the rest filled in from it.
* **Collocations, Idioms and Phrasal Verbs Intermediate** print their unit
  numbers *and* their contents numbers in coloured boxes, so neither survives:
  Collocations gave 44 units of 60 and Phrasal Verbs 68 of 70, eight of which
  were not units and one of which put unit 1 on unit 6's page. Each of these
  books states its own shape on its first page — *the book has 60 two-page
  units* — and that shape is visible without reading it, in the exercises pages
  coming every second page from the first to the last. Where the fit explains
  almost every exercises page and the headings explain almost none, the fit
  takes over rather than merely filling gaps.

A unit's page also has to lie where the book's own spacing puts it. Checking
only that both neighbours agree misses the ends of a run — nothing came before
unit 1 to contradict it — so units that fall off the line are put back on it.

Titles come from the book's own contents list, which beats any page heading and
beats it badly on a scan, where the largest line is as likely to be a caption.
The numeral column is the first thing a scan loses — units 2 to 9 of Basic
Grammar came back as the single line `WO WON HRW` — so an entry with no number
is given one only when the gap between its neighbours fills exactly.

## Audio

| Book | Files | Named by |
|---|---|---|
| Advanced Grammar (disc) | 2,445 | the disc's own clip ids |
| Basic Grammar | 2,421 | unit directory, section directory, printed page |
| Grammar Intermediate | 580 | unit and section in the filename |
| Pronunciation Advanced | 386 | CD and track |
| Pronunciation Intermediate | 360 | CD and track |
| Pronunciation Elementary | 351 | CD and track, as **WMA** |

6,543 recordings, against the 1,162 the project had. `tools/place_audio.py`
renames every one to a shape that says what it is, and records where it came
from, so the renaming can be checked against the archive rather than believed.
The WMA set is transcoded, since neither the application nor the project's own
duration reader can open it. Two of the archives stamped a shop's address into
every directory name; none of that travels into the project.

Nothing is filed on a hunch. The pronunciation books number their audio by CD
track and say nothing about units, so those are attached only where the book
itself prints the track beside an exercise. The marker is set inside a
headphone icon, which is artwork, so about half of them are read — 168 of 386
in the Advanced book. The rest stay in the inventory without a unit rather than
being spread over the units in proportion, because a recording played in the
wrong lesson is worse than one a learner has to find for themselves.

Everything is measured: **51.6 hours** of audio, every file sized and timed.

## The disc is the find

`Advanced Grammar in Use CD_ROM` is not a book. It is the same book's exercises
published as data: 226 files across 14 sections covering units 1–100, each
carrying its rubric, its items, which blank takes which words, the wrong form
and the corrected form for the correction drills, the options for the choice
drills, and a recording per item.

That matters because the readiness report has been stuck on exactly this line:
*answers exist as raw answer-key prose, not per-blank values*. A printed
exercise imports as one row per instruction — its numbered parts are typography
and its answers are sixty pages away — which is why no exercise from any book
has ever been servable.

From the disc: **2,328 items, 2,302 of them markable, 2,281 imported as
servable**, with 2,790 answers, 2,130 options and 1,956 recordings attached.
17 items whose answer the disc does not state are flagged rather than guessed,
and 25 are held as draft: marking a learner wrong on an answer we invented is
worse than not asking them.

Half of the correction drills — 170 of 360 — are sentences that are already
right, because the rubric asks *whether* they are correct before asking to fix
them. Those are marked as such, or an interface that only offers "edit this"
would mark a learner wrong for correctly changing nothing.

The disc's 226 exercise files and its 45-sound phonemic chart are kept in the
repository at `sources/cdrom` — 5.6 MB of XML, against the 355 MB disc image —
so the extraction can be rerun without it.

## What is a duplicate

Five of the twenty-six files are copies of material already in the repository,
verified by checksum rather than by name: all four `English Vocabulary in Use`
PDFs are byte-identical to `sources/*.pdf`, and all four vocabulary audio
archives are 1,162 of 1,162 files byte-identical to `sources/audio/`.
