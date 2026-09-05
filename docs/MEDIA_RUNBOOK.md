# Media backfill runbook

Everything the course needs is planned, prompted and ordered. Nothing has been
generated. This is what happens on the day the generation window is bought.

## What is planned

| kind | count | model | shape |
|---|---:|---|---|
| Character portraits | 14 | `nano_banana_pro` | 1:1, 2K |
| Lesson scenes | 1,130 | `gpt_image_2` | 16:9, 2K |
| Vocabulary cards | 1,372 | `gpt_image_2` | 1:1, 2K |
| **Total** | **2,516** | | |

A further 11,044 vocabulary senses are recorded as `skipped` with a reason
rather than dropped. Almost all of them have no example sentence that survives
the quality gate, and a card grounded in an extraction fragment illustrates the
fragment. They are visible in `media_briefs` and can be revisited.

## Render order

`media_briefs.priority`, lowest first. The window is time-boxed, so if it runs
out the manifest must already have made the more useful half:

1. **Portraits (10).** The cast anchors every scene that features them, so a
   scene rendered before its characters exist is a scene rendered twice.
2. **Lesson scenes (100 + level).** A lesson with no artwork is visibly
   incomplete.
3. **Vocabulary cards (300 + level).** A card without a picture still teaches.

Within each band, lower CEFR levels go first: a beginner depends on the picture
to carry meaning an advanced learner takes from the text.

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

At roughly 30s per image with twelve in flight, 2,516 images is about six hours
of continuous rendering. The seven-day window is not the constraint; the
constraint is that its clock starts at purchase, which is why the manifest is
built first.

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
