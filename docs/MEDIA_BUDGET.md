# Media budget

What the platform actually needs generating, and what a Higgsfield plan covers.

## What is already owned — no generation needed

| Asset | Count | Source |
|---|---|---|
| Audio recordings | 1,162 | The source books' own audio, mapped to unit and section at confidence 1.00 |
| Book artwork | 917 | Extracted from the source PDFs (721 page scans + 196 illustrations) |

This matters more than it looks: the single largest media cost in a language
course is normally narration, and none of it has to be generated.

## What still needs generating

Counted from `media_briefs`, which is the manifest the render loop actually
works through. (Earlier versions of this table were estimated from the contents
pages and were roughly half the real figure.)

| Need | Count | Type |
|---|---:|---|
| Lesson scene artwork | 2,421 | image |
| Vocabulary card artwork | 1,384 | image |
| Dialogue clips | 80 | video |
| Lesson clips | 1,846 | video |

A further 18,496 briefs are recorded as `skipped` with a reason rather than
dropped: senses with no example sentence that survives the quality gate, and
lessons that teach the language itself, where there is no footage of a suffix.

## What it costs, measured

Prices below were read from the provider on 11 September 2026 by asking for a
cost without submitting a job, on the account the course actually uses. They are
not estimates.

| Kind | Model | Shape | Credits each |
|---|---|---|---:|
| Character portrait | `nano_banana_pro` | 1:1, 2K | 2.0 |
| Lesson scene | `gpt_image_2` | 16:9, 2K, quality low | 0.5 |
| Vocabulary card | `gpt_image_2` | 1:1, 2K, quality low | 0.5 |
| Any 5-second clip | `seedance_2_0` | 16:9, 1080p | 22.5 |

Against the 5,745 briefs in the manifest that is **about 45,300 credits** for the
whole plan — 1,930 of it images, 43,400 of it video.

### The unlimited tier does not apply to this account

The 365-day unlimited image tier is a **free-trial** allowance. On this Plus
subscription the provider reports it as unavailable: every image model in the
catalogue says `supports_unlim: true` and `unlim.available: false`. So images are
paid for out of credits at the prices above, and the earlier reading of this
document — "images: comfortably covered" — is wrong in practice. Re-check before
planning a large run: if the allowance is ever live, the image half becomes free
and the arithmetic below changes completely.

### Quality tier is not worth paying for

`gpt_image_2` at `quality: medium` costs 2.0 credits against 0.5 for `low` — four
times the price. The two were rendered from the same lesson prompt and compared:
at the size a lesson card draws them there was no visible difference. Low is what
the run uses, and four times as many lessons get a picture for the same money.

### Throughput

Eight concurrent jobs, plan-wide. A batch of twelve is accepted, but a second
batch submitted alongside it is rejected with a rate-limit error per request over
the ceiling. Render in rounds of about twelve and re-submit whatever bounces;
an image takes roughly 30-60 seconds.

## The recommendation

**Video, at 22.5 credits for five seconds, buys forty-five lesson stills.** That
is the trade, and at this balance it is not close. A whole credit balance of 838
buys 37 clips — just over three minutes of footage — or 1,670 lesson scenes.

The twelve conversation scenarios were the one case the older version of this
document argued for video. They no longer need it: those scenarios are now acted
out in the browser as interactive 3D scenes with real recorded voices, where the
learner speaks their way through. A five-second clip in front of that is
decoration.

So: **render stills; do not buy video yet.** Revisit only when a specific lesson
is demonstrably failing without motion, and then buy that one clip.

Within stills, `media_briefs.priority` already orders the queue by usefulness -
cast, then lesson scenes, then vocabulary cards, lower CEFR first inside each
band. Render from the top and stop whenever the budget says stop; whatever got
made is the more useful half.

## What has actually been rendered

| Kind | Rendered | Planned | Credits spent |
|---|---:|---:|---:|
| Character portraits | 14 | 14 | 28.0 |
| Lesson scenes | 103 | 2,421 | 51.5 |
| Everything else | 0 | — | 0 |

Plus 2.5 credits on the two quality probes. **82 credits spent, 756.66 left.**

Those files were rendered in a throwaway container and are not in git.
`docs/data/rendered-media.json` re-imports the whole run for nothing while the
provider URLs are still live — see `MEDIA_RUNBOOK.md`.
The cast is complete; the lesson scenes are the first 103 of the queue in
priority order, which is the opening of the elementary book.

Seven lesson briefs were marked `skipped` rather than rendered, with the reason
stored on the row: five teach written text (shop signs, on-screen labels, a
menu, an order form, printed notices) and every brief forbids writing in the
image, so the artwork could not contain the thing being taught; one teaches
word-building, which has nothing to photograph; and one is a list of
nationalities, where a picture could only be caricature.

## Guard rails already in the code

- `media:generate --estimate` reports the backlog and its observed cost per
  image **before** anything is spent, and reports the cost as unknown rather
  than guessing when no generation has completed yet.
- Every generation is written to `ai_requests` with its credits and cost, so
  spend is auditable per feature and per user.
- Per-plan and per-user ceilings live in `ai_usage_limits` and are checked
  *before* the provider is called, not after the bill arrives.
- Cache hits and reuse counts are reported in the admin AI overview, which is
  how you confirm the generate-once policy is actually holding.
- Media generation runs on its own queue connection with a 1800s retry window,
  because a render released mid-flight is a render paid for twice.


---

# Appendix: is 3D a cheaper route than video?

Asked directly: build the characters as 3D models, then film the scenarios with
them plus synthesised voice. Checked against the live Higgsfield catalogue
rather than assumed.

## What the 3D pipeline actually offers

Two different things, and the distinction turns out to be the whole answer.

**3D Jutsu** (the `scene_builder_3d_*` tools) is a hosted Blender 5.2 with
arbitrary Python execution, a 994-model GLB catalogue, and rendering. **It is
free.** Measured, not inferred: on a free account holding 7 credits, creating a
project and running a 480x270 Eevee render that published a PNG artifact left
the balance at 7. No credits were spent by either step.

**The 3D generation models** are the separate, billable half: `image_to_3d` and
`multi_image_to_3d` (Meshy) lift images into textured GLB meshes with optional
auto-rigging and animation, `3d_rigging` rigs an existing GLB, `sam_3_3d_body`
reconstructs a human body from a photo, `tripo_3d` goes from text. There is a
678-clip animation library.

## Why it is nonetheless the more expensive route here

**The free catalogue has no people in it.** All 994 assets are environments,
props, vehicles, buildings, interiors and nature. Searching it for `person
character human`, `woman`, `teacher student`, and `body head face` returns
nothing; `man` returns only Mangrove, Manhole, Manacles, Mantel, Ottoman and
Permanent Way Trolley. The free half of the pipeline builds sets, not a cast.

**The two halves do not connect.** `scene_builder_3d_import_asset` states it
"accepts catalog assets only" and takes a catalog asset ID rather than a URL,
and the Python runtime forbids network fetches and embedded model bytes. So a
character bought from the paid half cannot be walked into the free scene at all.
The free renderer can only ever film an empty street.

**No 3D model carries unlimited generations.** Querying the catalogue for models
that accept free-trial unlimited generations, filtered to 3D output, returns an
empty list - the unlimited tiers are images, audio and several video models.
Every character is therefore billed, and the parameters say where: texturing
"costs more credits", rigging "adds cost", animation "adds cost".

**The animation library is body actions, not speech.** The clips are walk, run,
jump, wave, dance. There are no visemes. A rigged character can gesture; it
cannot form English mouth shapes.

That last point is decisive for *this* product. A language learner watching a
dialogue needs to see articulation - it is part of how pronunciation is learned.
A 3D character with a convincing body idle and a static mouth is pedagogically
worse than a still photograph with accurate lip-sync, however much better it
looks in a trailer.

## What the question was really getting at

The instinct behind it is right: a recurring cast is worth having, and the
reason to want 3D is that a GLB is identical every time while image models
drift. That problem is real. 3D is just not the cheapest fix for it.

The purpose-built fix is a trained character identity (Higgsfield Soul), which
anchors every later generation to the same person. `characters` now carries
`soul_id` and a canonical `reference_media_asset_id`, and both are passed
through `MediaRequest` into the provider, so a character is reproducible rather
than merely described. `Character::hasStableIdentity()` reports which of the
cast are actually pinned.

## The cheap talking-character route

1. One portrait per character - image tier, anchored to the character's identity.
2. The line as speech - audio tier.
3. Lip-sync the portrait to the audio - the only step that spends credits.

`MediaGenerationService::characterLine()` does the first two and deliberately
stops there, because the third is the expensive one and should be a conscious
decision per scene rather than a default.

## Where 3D does genuinely pay

The free half is worth using for what it is actually good at. 994 catalogue
assets covering a city, a suburb, a farm, a living room, a market and a railway
are exactly the *settings* the course dialogues take place in, and rendering
them costs nothing. A scene can be built once and re-rendered from any camera
angle for free, which makes it a cheap source of consistent backgrounds - the
shop, the station platform, the classroom - behind a cast produced by the image
tier. That is a real saving on the largest category of lesson artwork, and it
does not depend on buying anything.

If the product later grows an interactive environment - a scene the learner
moves through rather than watches - the arithmetic inverts further: the asset is
built once and rendered by the client for free thereafter. That is a different
product decision, not a cheaper way to make the same videos.

## What could not be verified here

Per-generation credit prices for the paid models are not exposed by the
catalogue API, so the cost comparison above is structural - which steps are
billable and how many there are - not a priced quote. Run
`media:generate --estimate` after the first real generations to get observed
cost per image before committing to any volume.

The free-render measurement was taken on a free account. Nothing suggests a paid
plan would start charging for what a free one gives away, but it is one
observation, not a published price; re-check the balance around the first
production render.
