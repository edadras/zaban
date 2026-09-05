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

| Need | Count | Type |
|---|---|---|
| Lesson scene artwork | 983 | image |
| Conversation scenario artwork | 12 | image |
| Unit intro video (one per unit) | 362 | video |
| Lesson video (one per lesson) | 1,130 | video |

## How a Plus plan maps onto that

The 365-day unlimited tier on Plus covers **image** models only — Seedream 5.0
Lite, Flux.2 Pro (1K), Seedream 4.5, Nano Banana, Kling O1 Image, GPT Image.
Soul V2 and Cinema are a 3,000-generation allowance, not unlimited. No video
model appears in that list, so video is paid for out of the monthly credits.

**Images: comfortably covered.** 995 generations against an unlimited tier, and
they are a *one-off*, not a per-learner cost: `MediaGenerationService` caches by
request hash, so one lesson's artwork is generated once and served to every
learner who ever takes that lesson. Ten thousand students do not multiply this
number.

**Video: not covered by the unlimited tier.** 362 unit videos — let alone 1,130
lesson videos — come out of the credit balance, and 1,000 credits per month will
not fund that in one pass.

## The recommendation

Do not put a generated video on every lesson. It is the most expensive asset per
minute of learner attention, and the course already has real audio for every
single unit.

Spend video where it changes the learning, not where it decorates it:

1. **12 conversation scenario intros.** These set up a roleplay the learner then
   speaks their way through, so the video does actual teaching work.
2. **~30 unit intros for the highest-traffic units**, chosen from real usage once
   the platform has learners rather than guessed up front.
3. **Everything else: image + the book's own audio.** A still scene with real
   recorded narration is not a downgrade from a generated video — for
   listening practice it is often better, because the audio is authentic.

That is roughly 40-50 videos, which a monthly credit balance can absorb, spread
over the first months rather than spent at once.

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

It is real and complete: `image_to_3d` and `multi_image_to_3d` (Meshy) lift
images into textured GLB meshes with optional auto-rigging and animation,
`3d_rigging` rigs an existing GLB, `sam_3_3d_body` reconstructs a human body
from a photo, `tripo_3d` goes straight from text, and a Blender-backed scene
builder assembles and renders scenes. There is a 678-clip animation library.

## Why it is nonetheless the more expensive route here

**No 3D model is in the unlimited tier.** The models carrying unlimited
generations are images, audio, and several video models. Every 3D step is paid,
and the parameters say so explicitly: texturing "costs more credits", rigging
"adds cost", animation "adds cost". One character is four billable steps - mesh,
texture, rig, animate - and the rendered scene is a further step on top.

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

## Where 3D would genuinely pay

If the product later grows an interactive environment - a scene the learner
moves through rather than watches - the arithmetic inverts: the asset is built
once and rendered by the client for free thereafter. That is a different
product decision, not a cheaper way to make the same videos.

## What could not be verified here

Per-generation credit prices are not exposed by the catalogue API, so the
comparison above is structural - which steps are billable and how many there are
- not a priced quote. Run `media:generate --estimate` after the first real
generations to get observed cost per image before committing to any volume.
