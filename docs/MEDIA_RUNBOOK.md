# Media backfill runbook

Everything the course needs is planned, prompted and ordered. The loop below has
now been run for real: the whole cast and the first 103 lesson scenes are
rendered, imported and attached. What follows is therefore a description of a
path that works, not a plan for one.

Costs, throughput and what is left are in `MEDIA_BUDGET.md`, measured rather
than estimated. The short version: the unlimited image tier is not available on
this account, so every image is paid for; a five-second clip costs as much as
forty-five lesson stills; render stills and do not buy video yet.

## What is planned

**Stills — 2,516**

| kind | count | model | shape |
|---|---:|---|---|
| Character portraits | 14 | `nano_banana_pro` | 1:1, 2K |
| Lesson scenes | 1,130 | `gpt_image_2` | 16:9, 2K |
| Vocabulary cards | 1,372 | `gpt_image_2` | 1:1, 2K |

**Video — 908 clips, 76 minutes**

| kind | count | seeded from | shape |
|---|---:|---|---|
| Dialogue clips | 80 | text-to-video | 16:9, 1080p, 5s |
| Lesson clips | 828 | that lesson's own scene still | 16:9, 1080p, 5s |

A further 11,044 vocabulary senses and 302 lessons are recorded as `skipped`
with a reason rather than dropped. The senses have no example sentence that
survives the quality gate. The lessons teach the language itself — prefixes,
word-building, register, punctuation — and there is no footage of a suffix;
filming them would spend real quota on clips that cannot teach anything the
still does not teach better.

## Why lesson clips animate their own still

Video models drift far worse than image models. A cast of fourteen would be
unrecognisable across eight hundred independently generated clips, and each
clip would invent its own room. Seeding every clip from the scene image that
was already generated and approved for that lesson keeps the same people, the
same setting and the same framing.

That makes the dependency real, not cosmetic: a lesson clip is **not
renderable** until its still has been imported. `media:briefs` reports how many
are waiting, `MediaBrief::blocked()` lists them, and they unblock automatically
as the stills land. So the order is stills first, clips second — which is also
the cheaper order, because a bad still is caught before it is animated.

## Render order

`media_briefs.priority`, lowest first. The quota is finite, so if it runs out
the manifest must already have made the more useful half:

| band | priority | why |
|---|---:|---|
| Character portraits | 10 | the cast anchors every scene that features them |
| Lesson scenes | 100 | a lesson with no artwork is visibly incomplete |
| Vocabulary cards | 300 | a card without a picture still teaches |
| Dialogue clips | 400 | a real exchange: the situation *is* the lesson |
| Scenario clips | 500 | the lesson is itself a situation |
| Action clips | 600 | motion carries the meaning — cooking, sport, travel |
| Ambient clips | 700 | the still already teaches this; the clip only stops it feeling dead |

Within each band, lower CEFR levels go first: a beginner depends on the picture
to carry meaning an advanced learner takes from the text.

## What the clips deliberately do not do

They are written as behaviour, never as speech. These models move a mouth
without forming English phonemes, so a clip that appears to deliver the line
teaches the wrong articulation — worse than a still with accurate audio over
it. The prompts say so explicitly (`do not emphasise the mouths`). The words
arrive as audio; the clip carries the situation.

Dialogue clips are also not given their own script. The stored summary is the
raw transcript, speaker labels and numbers included; fed to a video model it
comes back as a scene trying to depict "B: She is 1.85 metres tall", which is
not a picture of anything.

## The loop

```bash
php artisan media:preflight                      # is everything ready?
php artisan media:manifest --limit=12 --claim    # next batch, provider-ready JSON
#   ... render those 12, collect {brief id: url} ...
php artisan media:import results.json            # download, store, link
```

`--limit=12` matches the provider's batch size. `--claim` marks the exported
briefs `generating` so two runs cannot collide.

The plan allows **eight concurrent jobs**. Twelve submitted together is fine —
the extras queue — but a second batch sent while the first is still running is
rejected per request over the ceiling, with the failures named in the response.
Re-submit those rather than treating the round as lost.

A claim that never comes back would otherwise sit at `generating` for ever, and
`renderable()` skips those, so an abandoned round silently shrinks the queue and
the lessons it covered never get a picture. `media:manifest` therefore releases
anything claimed more than `--release=6` hours ago before it exports (pass
`--release=0` to turn that off). The notice goes to stderr so the manifest on
stdout can still be piped straight into a runner.

`media:import` reads `{"results": {"<brief id>": "<url>"}}` — the same
index-in/index-out shape the batch tools return.

### Nine at a time

One generation can carry nine lessons. `media:sheet` groups the next briefs into
a contact sheet and composes one prompt for all of them; `media:import` cuts the
returned sheet back apart and attaches each cell to its own lesson.

```bash
php artisan media:sheet --cells=9 --sheets=4 --claim > sheets.json
#   ... render each sheet's prompt at its aspect_ratio and resolution ...
php artisan media:import results.json          # {"sheets": [{url, cols, rows, cells, prompt}]}
```

0.083 credits a lesson instead of 0.5. `MEDIA_BUDGET.md` has the arithmetic and
the resolution trade; the short version is that a 3x3 cell is about 1080x810 and
the client draws these at roughly 1200x900, so nothing visible is lost, while
4x4 cells at 810x610 are visibly soft.

The `cells` list is the only thing tying a cell to a lesson - there is no signal
in the image - so it goes back into `media:import` untouched, in the order it
came out. If the slicer cannot find the grid it was told to expect, the whole
sheet is refused and nothing is imported: nine lessons quietly given slivers of
each other's artwork is worse than a failed round, because nobody would find out
until a learner did.

A sheet is only exported when it is full. Asking for nine cells while naming
four scenes is how a sheet comes back as something the slicer refuses.

### When a brief should not be rendered at all

Reading a batch before paying for it turns up lessons no picture can teach:
shop signs, an on-screen order form, a printed menu. The artwork would have to
contain the words it teaches, and every brief forbids writing in the image.

```bash
php artisan media:skip 487 490 491 --reason="teaches written text; artwork may not contain writing"
```

This locks the decision. A plain status update does not survive the next
`media:briefs`, which put seven of them straight back in the queue; a locked
skip is left alone and reported instead.

### When the brief cannot be sent as written

An entry may be an object rather than a bare URL, carrying the prompt that was
actually sent:

```json
{"results": {"438": {"url": "https://…", "prompt": "One single photograph: …"}}}
```

This is needed more often than it sounds. Briefs are generated from the course
data and the course data is OCR: one unit title arrives as a phonetic
transcription, and the "Expressions" lessons list grammar fragments — "How
about", "It's up to you" — which are not pictures of anything. The operator
rewrites those into a situation that can be photographed. The `media_assets` row
is the only record of how a picture was made, so it stores what was sent and
keeps the brief's own wording beside it under `brief_prompt` when the two
differ. Seventy-five of the first 103 scenes went out this way.

Some briefs should not be sent at all. Seven are marked `skipped` with a reason
on the row: five teach written text — shop signs, on-screen labels, a menu, an
order form, printed notices — and every brief forbids writing in the image, so
the artwork could not contain the thing being taught; one teaches word-building,
which has nothing to photograph; one is a list of nationalities, where a picture
could only be caricature.

At roughly 30–60s per image and eight at a time, a thousand stills is a few
hours of continuous rendering. The constraint is not the clock, it is the
balance: see `MEDIA_BUDGET.md`.

Video also costs delivery, not just render: 908 clips is on the order of 3-4 GB.
That is a real product cost on mobile data, and a reason to render the manifest
from the top rather than to the bottom.

## Where this run actually lives, and how to get it back

The images rendered so far are about a gigabyte of PNGs under
`storage/app/private/generated/`, with their `media_assets` rows in the local
database. Neither is in git, and neither survives a rebuilt container. The
credits are spent either way, so the run is recorded as text instead:

    docs/data/rendered-media.json

That is a `media:import` payload — every brief id, the provider URL it came
back from, and, where the operator rewrote it, the prompt that was actually
sent. Sheet cells are regrouped into `sheets` entries rather than listed one by
one, because nine briefs share a single URL and importing that URL against one
of them would give that lesson the whole contact sheet. On a host that keeps its
storage:

```bash
php artisan media:import docs/data/rendered-media.json
```

and the same 207 images are downloaded, cut where they need cutting, and
attached again for nothing. It has been tested the only way that means
anything: by clearing a sheet's nine lessons and replaying them from the file.

**This has a shelf life.** Provider URLs expire. If they have gone by the time
this is run, the import fails per file rather than silently, and re-rendering
those 207 costs 91 credits at today's prices — recoverable, but not free. So the
real lesson is the obvious one: **run the loop where the storage persists.**
Doing it in a throwaway container means paying for artwork that the container
takes with it.

## Why generation runs outside the application

The Higgsfield CLI needs an interactive browser sign-in this environment cannot
complete, so `HiggsfieldProvider` cannot be driven from a queue worker here. The
manifest/import seam exists so that the backend still owns what exists and what
remains, while the rendering itself is done by whatever can reach the provider.
When the CLI is available, `media:generate` drives the same briefs through the
normal provider chain and nothing else changes.

## Safety properties

- **Re-planning does not re-bill.** Every brief ends with the same shared list
  of exclusions, so adding one word to that list changes all 24,000 prompts at
  once. `media:briefs` leaves briefs that have already been rendered exactly as
  they are and reports how many now carry an out-of-date prompt; `--rerender`
  requeues them deliberately, and is charged in full. Without that guard a
  one-word edit orders the whole catalogue a second time.
- **Idempotent import.** A 2,500-file run will be interrupted. Re-running
  re-attaches what is already on disk instead of re-downloading, identical
  output for two subjects is stored once by checksum, and a failed download
  stays in the queue rather than vanishing.
- **Idempotent planning.** `media:briefs` re-run writes nothing when nothing
  changed, and never downgrades an already-rendered brief.
- **Traceable.** Every generated `media_assets` row carries the brief id, model
  and full prompt that produced it.
- **Exclusions actually applied.** No image model in the catalogue accepts a
  negative-prompt argument, so the content rules — no text, no watermark, no
  brand names — are folded into the prompt body. `media:preflight` fails if any
  brief still carries a negative its model would ignore.

## Known limits

- **"Culturally neutral" is unconditional, and sometimes wrong.** Every scene
  prompt forbids national flags and region-specific signage, which is right
  almost everywhere and exactly backwards for the UK culture unit, where the
  lesson *is* the local detail. Those four were sent with the clause removed and
  a line saying British settings are correct here. The builder has no way to
  tell the difference; a lesson about a named country needs the operator to
  notice.
- **Some target words are not things.** They come straight out of the book, and
  the extractor cannot tell a heading from a headword: the conjunctions unit
  arrives as "example, use, and, but, or, because", the irregular-verb units as
  "got, It's got, ve got, haven't got", and cross-references get caught
  mid-word as "Unit 32: T, ravelling". Handed to an image model those produce a
  confidently meaningless picture. `PromptBuilder` now drops the entries that
  contain nothing a camera could point at, and falls back to asking for a
  moment in which the language would be used when almost nothing survives. The
  test is deliberately conservative in the other direction: a coarser filter
  throws away "adjectives describing appearance" and "expressions about
  clothes", and both photograph perfectly well.
- **The irregular-verb units cannot be told apart.** Two lessons inside
  "Have / had / had" are both titled, in the source, just "Have". There is no
  per-lesson distinction in the data to draw a distinct picture from, so
  several lessons in those units get similar artwork.
- **Target words are taken verbatim from `concepts`.** Where the source book
  prints a phrase, OCR sometimes ran the words together, and the label is then
  wrong both in the artwork prompt and on screen in front of the learner. Three
  such labels were found by reading a batch of prompts before paying for them
  and corrected in a migration. Nothing checks for this.

- `soul_id` is unset on every character. Appearance text holds a character
  together across generations, which is weaker than a trained identity but costs
  nothing. Train Souls from the portraits once they exist, then rebuild the
  briefs to anchor later scenes.
- The Advanced book's contents parse leaves "The media" spanning 38 units;
  some headings between units 47 and 84 were not detected. Ordering is right,
  granularity is coarse.
- Two module titles are truncated where the book wrapped them onto a second
  line ("Fixed expressions and", "Phrasal verbs and verb-based").
- The Pre-intermediate/Intermediate book's contents failed its sanity checks
  and kept its mechanical grouping, by design: a wrong category is a
  navigational lie, "Units 1–10" is merely dull.
