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
