# Media backfill runbook

Everything the course needs is planned, prompted and ordered. Nothing has been
generated. This is what happens on the day the generation window is bought.

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

`media:import` reads `{"results": {"<brief id>": "<url>"}}` — the same
index-in/index-out shape the batch tools return.

At roughly 30s per image with twelve in flight, 2,516 stills is about six hours
of continuous rendering. Clips are slower and run four at a time rather than
twelve, so 908 of them is roughly another twelve hours. The rented window is not
the constraint; the constraint is that its clock starts at purchase, which is
why the manifest is built first.

Video also costs delivery, not just render: 908 clips is on the order of 3-4 GB.
That is a real product cost on mobile data, and a reason to render the manifest
from the top rather than to the bottom.

## Why generation runs outside the application

The Higgsfield CLI needs an interactive browser sign-in this environment cannot
complete, so `HiggsfieldProvider` cannot be driven from a queue worker here. The
manifest/import seam exists so that the backend still owns what exists and what
remains, while the rendering itself is done by whatever can reach the provider.
When the CLI is available, `media:generate` drives the same briefs through the
normal provider chain and nothing else changes.

## Safety properties

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
