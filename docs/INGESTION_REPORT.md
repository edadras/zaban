# Source Ingestion Report — Phase 1–2

Automated analysis of the four source books and their audio packs. Produced by
`tools/analyze_sources.py`, which is also the reference implementation for ingestion
stages 3–7 (structure identification, semantic segmentation, concept extraction,
exercise extraction, answer extraction).

## 1. Sources

| Book | CEFR target | PDF pages | Audio files |
|---|---|---|---|
| elementary | A1-A2 | 177 | 205 |
| pre_int_int | A2-B1 | 266 | 318 |
| upper_int | B2 | 281 | 351 |
| advanced | C1-C2 | 304 | 288 |
| **total** | Pre-A1 → C2 | **1028** | **1162** (757 MB) |

All four PDFs carry a real text layer; none required OCR. The audio archives arrived
as Git LFS pointers and were fetched with `git lfs pull`.

## 2. Copyright status

The four source books are owned by the operator, who has confirmed rights to the
material. They are registered as **`copyright_status = 'owned'`**, so the pipeline may
both store and deliver source content according to that ownership: original prose,
exercises, artwork references and audio can be served directly as well as used to derive
generated material.

Provenance is still recorded on every derived row regardless of copyright status —
`source_document_id`, `source_page`, `source_section`, `source_reference` and
`generation_method` — so any content item can always be traced back to the page it came
from, and generated material stays distinguishable from extracted material.

## 3. Structure discovered

| Book | Units titled | Sections | Exercises | Answer-key items | Inline glosses |
|---|---|---|---|---|---|
| elementary | 58/60 | 192 | 287 | 287 | 169 |
| pre_int_int | 99/100 | 301 | 466 | 457 | 754 |
| upper_int | 100/101 | 343 | 503 | 430 | 704 |
| advanced | 95/101 | 263 | 454 | 413 | 932 |
| **total** | **352/362** | **1099** | **1710** | **1587** | **2559** |

Shared conventions across the series:

- A unit occupies a two-page spread: left page teaches (lettered sections `A`, `B`, `C`…),
  right page drills (`Exercises`).
- Exercise labels encode their own address: `12.3` = unit 12, exercise 3. This is the
  most reliable structural marker in the series and anchors the whole extraction.
- A consolidated answer key sits at the back of each book.
- The Advanced and Upper-Intermediate editions gloss vocabulary inline in square
  brackets — 2,559 glosses in total, a direct feed for the `definitions` table.

### Layout variants requiring per-book handling

The books are *not* uniformly typeset. Four distinct unit-heading layouts were found:

| Variant | Example | Books affected |
|---|---|---|
| number, wide gap, title | `2   Birth, marriage and death` | all |
| number, single space, title | `62 Condition` | Upper-Intermediate |
| title wraps, number leads line 2 | `Describing people: positive and` / `8   negative qualities` | Advanced |
| `Study unit` banner before number | `Study` / `unit  1  Learning vocabulary` | Pre-Int, Upper-Int |

## 4. Audio mapping

Filenames follow `U_<unit>.<section>.mp3`, mapping deterministically onto the unit and
section structure recovered from the PDFs.

| Book | mp3 files | Units referenced | Unmapped | Units missing audio | Orphan audio units |
|---|---|---|---|---|---|
| elementary | 205 | 60 | 0 | 0 | 0 |
| pre_int_int | 318 | 100 | 0 | 0 | 0 |
| upper_int | 351 | 101 | 0 | 0 | 0 |
| advanced | 288 | 101 | 0 | 0 | 0 |

**All 1,162 audio files map to a book unit, and every book unit has audio.** Mapping
method is `filename` at confidence 1.00, so none enters the low-confidence review queue.
The Pre-Intermediate archive splits into `Part 1` / `Part 2` folders, which does not
affect unit resolution.

## 5. Extraction quality and open items

| Book | Lines with collapsed word spacing | Units needing manual title review |
|---|---|---|
| elementary | 18/3528 (0.51%) | [58, 59] |
| pre_int_int | 0/7568 (0.0%) | [72] |
| upper_int | 2/7869 (0.03%) | [99] |
| advanced | 21/9174 (0.23%) | [1, 8, 9, 10, 24, 75] |

Three known issues. The first two are routed to admin review rather than silently
accepted; the third has been corrected.

1. **10 unit titles (2.8%) could not be resolved** from the text layer. Those pages use
   a heading layout none of the four variants covers; they need page-image understanding
   (§6) or manual entry.
2. **41 lines lost inter-word spacing** during extraction (e.g. `Ahandhasfive`),
   concentrated in the Elementary edition's justified exercise text. Affected lines are
   flagged for vision fallback; at 0.0–0.51% the rate does not threaten the unit map.
3. **190 footnote glosses had been paired with the wrong word** and are now corrected.
   A page carries two or three lettered sections, and each one numbers its own
   footnotes from 1. The gloss reader took the page whole, so section A's terms were
   paired against section B's list, number for number: the flashcard for *cram* showed
   "diagram that lays out ideas for a topic and how they are connected to one another",
   which is the book's gloss for *mind map*. `PageGlossParser` now cuts the page to the
   lesson's own section before reading anything — the scope the book numbers within —
   and the same pass recovered **466 senses that had no definition at all**, footnotes
   the old forward-only scan walked past because the scanner moves a margin column to
   the foot of the page. Migrations `..._000800` and `..._000900` carry the corrections
   and put the flashcards back in step with them.

Two defects found in the same review are **reported but not fixed**, because both are
product decisions rather than extraction faults:

- **1,662 `listen_and_choose` blocks offer nothing to choose.** The builder writes
  `config.concept_ids`; the client reads `config.options` / `config.choices`, and no
  stage converts one into the other. None of the blocks carries a graded exercise
  either, so every "Listen and choose what you hear" step in the app is a play button
  and a Continue button. A real choice item needs audio cut per item — the recording
  attached is the whole lesson, and all six candidate words are in it — so either the
  block says what it really is (listen and follow the text) or per-item audio gets cut
  first. Inventing a key over a 1:24 track would be guessing.
- **10,582 flashcards show an example sentence where the meaning should be.** The
  builder falls back to the example when a sense has no definition, under the label
  "Tap the card to reveal the meaning", so *heart* reveals "The moment I met Rob, I
  could see he was a man after my own heart." The 466 definitions recovered above
  reduce this; the rest needs either more glosses or a label that matches what the
  card actually shows.

Exercise/answer asymmetries (Pre-Int units 17, 18, 99; Upper-Int unit 9) reflect
open-ended tasks that legitimately have no fixed key. They are marked as productive
items for AI grading, not treated as extraction failures.

## 6. Proposed curriculum mapping

| Source book | Course | CEFR band | Units |
|---|---|---|---|
| Elementary (3rd ed.) | Foundation | A1–A2 | 60 |
| Pre-Intermediate & Intermediate (4th ed.) | Core | A2–B1 | 100 |
| Upper-Intermediate (4th ed.) | Advancing | B2 | 101 |
| Advanced (3rd ed.) | Mastery | C1–C2 | 101 |

Thematic categories carried through from the contents pages: people, the body, daily
life, home, food, school and university, work and business, leisure, travel and tourism,
the natural world, society and social issues, communication and technology, functional
language, word formation (prefixes, suffixes), phrase building, verb constructions,
linking devices, and register.

The 362 units become `units` rows; their 1,099 lettered sections become `lessons`; each
lesson is decomposed into interactive `lesson_blocks`. The 1,710 source exercises are
**not** copied — they supply exercise-type distribution and difficulty banding per unit,
from which original items are generated.

## 7. Reproducing this report

```bash
git lfs pull                      # fetch the four audio archives
python3 tools/analyze_sources.py  # writes /tmp/extract/report.json
```

`report.json` holds the full machine-readable output, including every extracted unit
title and the complete audio-to-unit mapping.