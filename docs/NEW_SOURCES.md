# The 26 new source files

Extracted from the `new` branch on 2026-09-06. Nothing is imported yet; this is
what came out of the archives and what state each book is in.

Staged under `sources/incoming/` (3.1 GB, untracked). The archives themselves are
in Git LFS on `new` and were deleted locally after extraction — nothing is lost
by that, and re-extracting is one `git show … | git lfs smudge` away.

## What is genuinely new

Eleven books across five series the corpus did not have, and 6,192 new
recordings. The largest gap this closes is grammar: the corpus had **zero**
grammar concepts and zero grammar items, and three grammar books arrive here.

| Series | Book | Pages | Text layer |
|---|---|---|---|
| Grammar in Use | Basic (4th) | 319 | **scanned** |
| Grammar in Use | Intermediate | 394 | text |
| Grammar in Use | Advanced | 306 | **scanned** |
| Pronunciation in Use | Elementary | 168 | **scanned** |
| Pronunciation in Use | Intermediate | 201 | **scanned** |
| Pronunciation in Use | Advanced | 191 | text |
| Phrasal Verbs in Use | Intermediate | 202 | **scanned** |
| Phrasal Verbs in Use | Advanced | 194 | text |
| Collocations in Use | Intermediate | 193 | **scanned** |
| Collocations in Use | Advanced | 189 | **scanned** |
| Idioms in Use | Intermediate | 181 | **scanned** |
| Idioms in Use | Advanced | 182 | **scanned** |

### Three books can be imported today

`English Grammar in Use — Intermediate` (956k characters), `Phrasal Verbs in Use
— Advanced` (527k) and `Pronunciation in Use — Advanced` (484k) carry a real text
layer and go through the existing extraction pipeline unchanged.

### Nine books are page images

1,941 pages with no text in them at all — one or two characters per page, which
is the page number. `pdftotext` returns nothing usable and no amount of parser
work changes that; they have to be read by OCR before any of the pipeline
applies. No OCR tool is installed in this environment (`tesseract` and
`ocrmypdf` are both absent), and the project's own vision path is the designed
answer: `source_pages.used_vision` exists for exactly this, and the AI layer can
read a page image. That is a real cost — roughly 1,941 page images through a
vision model — and needs a decision before it is spent.

## Audio

| Source | Files | Format | Size |
|---|---|---|---|
| Advanced Grammar CD-ROM | 2,445 | mp3 | 355 MB |
| Basic Grammar in Use | 2,421 | mp3 | 276 MB |
| Grammar in Use Intermediate | 580 | mp3 | 288 MB |
| Pronunciation in Use Advanced | 386 | mp3 | 214 MB |
| Pronunciation in Use Intermediate | 360 | mp3 | 258 MB |
| Pronunciation in Use Elementary | 351 | **wma** | 318 MB |

6,192 new mp3 plus 351 WMA, against the 1,162 recordings the project holds now.

The Elementary pronunciation set is Windows Media Audio, which neither the app
nor `media:measure` can read. It needs transcoding to mp3, and `ffmpeg` is not
installed here either.

## The Advanced Grammar CD-ROM is the find

`34-Advanced Grammar in Use CD_ROM.zip` is not a book — it is the interactive
disc, and it holds the exercises as **structured data**:

- 226 exercise XML files across 14 thematic sections covering units 1–100
- each carrying its rubric, its items, the wrong and corrected forms, and
  per-item audio references
- 2,445 mp3 files those items point at

For example, one file gives the instruction "Are the underlined phrases correct?
If not, correct them with one of the phrases in brackets", then the wrong form
"All of my family don't live", the right form "Not all of my family live", and
the sentence they complete.

This matters because the readiness report has been blocked on exactly this:
*"answers exist as raw answer-key prose, not per-blank values"*. The books'
printed exercises import as one row per instruction because their numbered parts
live on the page. Here the parts and their answers are machine-readable.

## What is a duplicate

Five of the 26 files are copies of material already in the repository, verified
by checksum rather than by name:

- all four `English Vocabulary in Use` PDFs — byte-identical to `sources/*.pdf`
- all four vocabulary audio archives — 1,162 of 1,162 files byte-identical to
  `sources/audio/` (205 elementary, 318 pre-int/int, 351 upper-int, 288 advanced)

They need no action.

## Sizes

3.1 GB extracted. The vocabulary duplicates were removed after verification.
