# Architecture

How Zaban is put together and, more usefully, why. Every claim here is about code
that exists in this repository; where a piece is designed but not yet built it is
marked **planned** rather than described in the present tense.

---

## 1. The shape of the problem

Zaban turns four printed vocabulary courses into an adaptive English learning
platform. That framing drives almost every decision below, because it creates
three constraints that a greenfield app would not have:

1. **The content is fixed and finite, and it is not ours to reinvent.** 362 units
   across four books, 1,099 lettered sections, 1,162 audio files. The system's
   job is to lose none of it, prove that it lost none of it, and then make it
   interactive.
2. **A page in a book is not an activity.** A two-page spread that teaches
   vocabulary on the left and drills it on the right has to become something a
   learner can tap, answer, and be graded on — and the platform has to be honest
   about which parts of that transformation are faithful derivation and which are
   generation.
3. **Learning is measured over weeks, not requests.** Mastery, forgetting,
   difficulty and placement are stateful models of a person. They belong on the
   server, they must survive a reinstalled app, and the client must never be able
   to assert them.

Everything that follows is downstream of those three.

---

## 2. System map

```
sources/*.pdf, sources/audio/           four books + 1,162 mp3s (Git LFS)
        │
        │  tools/analyze_sources.py      structure discovery, audio mapping
        │  tools/extract_content.py      units, sections, taught terms, examples, answer key
        │  tools/extract_images.py       page images and illustrations
        ▼
docs/data/curriculum/*.json             committed extraction output
        │
        │  php artisan content:import            lossless load, with provenance
        │  php artisan content:build-activities  derive blocks and gradable items
        │  php artisan content:audit             prove nothing was dropped
        │  php artisan content:readiness         prove the engines can actually run
        ▼
MySQL 8 — 126 tables
        │
        ├── concept graph ────────► adaptive engines ──► /api/v1  ──► Flutter client
        ├── item bank      ────────► CAT placement
        └── learner state  ────────► progress, reviews, remediation
                                          │
                                    AiOrchestrator ──► Anthropic / Higgsfield /
                                          │              whisper.cpp / espeak-ng
                                    queues: default, content, media,
                                            speech, ai-high, ai-low
```

---

## 3. Ingestion: extraction outside PHP, loading inside it

The pipeline is deliberately split at the JSON boundary.

**Outside PHP** (`tools/*.py`) is anything that has to understand a PDF. The
extractors run `pdftotext -layout`, `pdftohtml -xml` and `pdfimages` from poppler
and reconstruct the books' structure from the text layer. The single most
important trick is typographic: *the series sets every taught item in bold*, so
bold runs in the PDF's text layer are the authoritative headword list, attributed
to a section by vertical position on the page. Guessing which words a unit
teaches would have been the weakest link in the whole system; reading it off the
typesetting is close to free and close to exact.

Structure is anchored on exercise labels. `12.3` means unit 12, exercise 3, and
that convention holds across all four books, which makes it a far more reliable
spine than headings — the books use *four* different unit-heading layouts, and 10
of 362 titles still cannot be resolved from text alone. Those go to a review
queue instead of being silently guessed.

**Inside PHP** (`app/Console/Commands/ImportCurriculum.php`) is the load. It is
idempotent by construction: re-running updates rather than duplicating, so the
import can be repeated every time the extractor improves. Every derived row
carries `source_document_id`, `source_page`, `source_section`, `source_reference`
and `generation_method`, so any item on a learner's screen can be traced back to
the page it came from, and extracted material stays distinguishable from
generated material forever.

### Why there are two verification commands, not zero

`content:audit` re-reads the PDFs and the audio tree from disk and compares them
with what is stored — deliberately *not* trusting the importer's own counters,
because an importer that miscounts will also miscount its own report. It shells
out to `pdfinfo` for the page count for exactly this reason.

`content:readiness` asks a different and harder question: not "is it stored?" but
"can the engines run on it?". It checks that exercises are linked to concepts
(otherwise `AdaptiveLearningService` cannot select practice for a weakness), that
items have gradable answers, that lessons have interactive blocks rather than
just source text, and that enough placement-eligible items exist to run an
adaptive test. A database can be full and the product still broken; this command
is the difference between the two.

`content:build-activities` sits between them and derives interactive blocks and
gradable exercises **deterministically** from what the books already give: the
taught term, its example sentence, its gloss, its audio. It invents nothing. Its
stoplist exists because bold runs sometimes split across text runs and leave a
stray `got` behind — such terms stay in the knowledge graph (the books really do
teach some function words) but never become a flashcard, because a flashcard for
"the" teaches nothing.

---

## 4. The knowledge model: one concept graph

The schema's central design decision is the `concepts` table.

Vocabulary senses, grammar concepts, pronunciation items, phrases and subskills
are five different things with five different tables. Each of them also gets
exactly one row in `concepts` (a polymorphic `conceptable` morph). That single
registry is what lets mastery tracking, prerequisites, exercise tagging and the
adaptive engine all work in **one id space** instead of five parallel polymorphic
joins. Without it, "what is this learner weakest at?" is five queries and a merge;
with it, it is one index scan on `learner_concepts`.

Two properties live on the concept itself:

- `difficulty` — on the same logit scale as learner ability, which is what makes
  the difficulty engine's arithmetic possible at all (§6.3).
- `importance` — how central the node is to the syllabus, used to weight
  remediation priority. Not everything worth teaching is worth chasing.

Prerequisites (`concept_prerequisites`) are **directed edges with a strength**,
and `is_blocking` defaults to false. A hard gate would be simpler to implement and
worse to learn under: most prerequisites are "this helps" rather than "this is
required", and treating them all as gates produces a curriculum that refuses to
teach anything until everything before it is perfect.

`exercise_concepts` is the join the whole learning engine leans on: it records
which knowledge-graph nodes an exercise actually tests, and mastery updates fan
out through it.

---

## 5. The API layer

- **Versioned prefix.** Everything is under `/api/v1`, so the Flutter client can
  be upgraded independently of the server.
- **One envelope.** `App\Support\ApiResponse` produces `{"data": …}` on success
  and `{"data": null, "error": {code, message, details?}}` on failure, with
  pagination folded into `meta`. One shape means the client has one parser and one
  error path. See [API.md](API.md).
- **JSON always.** `ForceJsonResponse` is prepended to the API middleware group
  and sets `Accept: application/json`, so even framework-level failures
  (validation, 404, 500) come back as JSON rather than HTML.
- **Sanctum bearer tokens.** The mobile client is stateless; tokens are issued per
  device at register/login and revoked on logout.
- **A thin controller layer.** Controllers extend `ApiController`, which supplies
  `ok()`/`created()`/`fail()` and a `learner()` helper. The real work lives in
  `app/Services/*`, because the same mastery update has to be reachable from an
  HTTP submit, a queued job and an artisan command.

---

## 6. The adaptive engines

Four services in `app/Services/Learning`, each with one job, composed by a fifth.

### 6.1 Mastery — `MasteryService`

Mastery is a number in [0, 1] with named bands (introduced 0.20, developing 0.40,
competent 0.60, strong 0.80, mastered 0.95). Two rules define what the number
means:

1. **A single success cannot push a concept past "introduced".** One right answer
   is evidence of nothing much.
2. **Successes only count toward the higher bands when they are spaced.** The
   service requires 20 hours between successes for one to count as spaced;
   unspaced ones are capped at "developing".

That second rule is the whole point. Mastery is meant to be evidence of *durable
retrieval*, and answering the same item twice in one sitting demonstrates
short-term recall. Without the cap, a learner could grind a concept to "mastered"
in ten minutes and the system would then stop showing it to them — actively
harming the person it was trying to help.

Other deliberate choices:

- A wrong answer does not reset to zero; it falls back toward "developing"
  (`current × 0.55`, floored at introduced). The learner has still met the
  concept.
- Hints reduce credit (up to 60%): assisted retrieval is worth less than
  unassisted retrieval.
- `confidence` tracks how much to trust the mastery figure: it grows with the
  volume of evidence and shrinks when results are mixed, so a 50/50 record scores
  low confidence even after many attempts.
- `difficulty_performance` buckets success by item difficulty, which is what
  separates "knows it" from "gets the easy ones right".

Every update writes a `review_history` row with mastery and interval before and
after, so the model's behaviour is auditable after the fact rather than only
observable in aggregate.

### 6.2 Spaced repetition — `SpacedRepetitionService`

SM-2 derived, with per-learner adaptation. Learning steps are 1, 3, 7 days, then
the interval scales by the concept's ease factor, capped at a year. The ease
factor moves with *this learner's* history on *this concept*, bounded to
[1.3, 2.8], so a word one person finds hard comes back sooner for them than for
someone who finds it easy.

A lapse resets the repetition ladder but only *decrements* ease by 0.20 rather
than resetting it — repeated lapses therefore compound, which is the correct
signal: a concept you have failed four times is not the same as a new one.

Review ordering does not use the due date alone. `forgettingProbability()`
evaluates an exponential forgetting curve against elapsed time and the concept's
memory strength, and due items are sorted by it, so the most at-risk material is
reviewed first when a backlog exists.

### 6.3 Difficulty targeting — `DifficultyService`

Item difficulty and learner ability share one logit scale, so success probability
is a two-parameter logistic (with an optional guessing parameter). This is the
piece that makes item selection arithmetic instead of heuristics.

The engine targets a **70–85% success band**. Below it the learner stalls; above
it, nothing is being learned. It picks the candidate whose predicted success is
closest to the middle of that band — except 12% of the time, when it deliberately
targets 0.60 to offer a stretch item, because a learner who never meets anything
hard never sees themselves improve.

`updateAbility()` is an online gradient step weighted by Fisher information: more
informative items move the estimate further and shrink the standard error faster.
The same function is used after ordinary practice and between placement items,
which is why placement results and day-to-day performance stay on one scale
instead of being two unrelated numbers.

### 6.4 Remediation — `RemediationService`

Showing the same question again is the one thing that reliably does not work, so
the strategy escalates with the failure count:

| Failures | Strategy | What it does |
|---|---|---|
| ≤1 | `retest` | try again |
| 2 | `different_item` | another item on the same concept |
| 3 | `easier_framing` | the easiest available item, to rebuild footing |
| 4 | `change_modality` | recognition or listening instead of production |
| 5+ | `explicit_instruction` | stop testing, teach it, then retest |

Items seen in the last three days are excluded from all of these. The service
also emits `guidance` — a plain-English instruction the AI tutor can act on
("contrast it with what the learner keeps confusing it with") — which is how a
mechanical escalation ladder ends up producing teaching rather than shuffling.

Errors are recorded in `learner_errors` and *incremented* on repeat rather than
duplicated. That is what makes "keeps getting this wrong" a measurable quantity
instead of a log to be grepped.

### 6.5 Composition — `AdaptiveLearningService`

The session builder is where the engines meet. It allocates roughly one activity
per planned minute across five competing demands, starting from
review 30 / curriculum 30 / weakness 20 / speaking 10 / exploration 10 and
shifting per learner:

- a review backlog over 25 items pulls weight from new material;
- no due reviews pushes weight into curriculum and exploration;
- `frustration_index` above 0.5 moves weight to weakness and review and *away*
  from curriculum — the response to a struggling learner is to consolidate, not
  to push harder.

The chosen activities are then **interleaved** so the same type never runs three
times in a row, drawing from whichever type has the most remaining so nothing
bunches at the end. Variety is not decoration here; a session that is twelve
flashcards in a row is a worksheet, and people stop doing worksheets.

Every activity persists its `selection_reason` as JSON — the driver, the
forgetting probability, the mastery at selection time, the remediation strategy.
The engine can therefore always answer "why am I being shown this?", which
matters both for the learner-facing explanation and for debugging a bad session
after the fact.

---

## 7. Placement: computer adaptive testing

`app/Services/Placement/PlacementService.php` implements a genuine CAT, not a
fixed quiz.

- Every learner starts at ability 0.0 (around B1) with a standard error of 1.5.
  Starting in the middle of the ladder minimises the expected number of items.
- Each dimension (skill) is estimated separately, with its own minimum (4) and
  maximum (12) item counts and its own target precision (SE ≤ 0.32).
- Item selection is **maximum information**: among placement-eligible items for
  the chosen skill within ±2.0 logits of the current estimate, pick the one that
  most reduces uncertainty. Items far from the estimate carry almost no
  information and waste the learner's time.
- Dimensions **stop independently**. A confident reader finishes reading in four
  items while their speaking estimate is still being refined — which is the
  entire reason to do this adaptively rather than to administer 40 fixed questions.
- The global ability is an information-weighted mean of the dimensions, so a
  precisely measured skill counts for more than a barely sampled one.
- If the item bank runs dry in a band, the dimension is closed out rather than
  asked an uninformative question.

On completion the per-skill CEFR levels and abilities are written to
`learner_skill_states` and the overall estimate to `learner_profiles`, where the
curriculum picks them up. `stop_reason` records whether the test ended on
precision or on the item cap, which is the first thing to look at when placements
start coming out wrong.

---

## 8. AI orchestration

`app/AI/AiOrchestrator.php` is the single door to every AI vendor. Nothing else
in the application may talk to a provider directly.

That rule is the design. The cost ledger, the reuse cache, the per-plan limits
and the fallback chain are each worthless if even one caller can bypass them, and
"most calls go through the orchestrator" is operationally the same as "none of
them do".

### 8.1 Chains, not clients

`config/ai.php` defines an ordered chain per capability
(`text`, `image`, `video`, `audio`, `stt`), populated from environment variables.
`ProviderRegistry` resolves the chain and filters it to providers that are both
configured and report `isAvailable()`. The orchestrator walks the chain and uses
the first one that succeeds.

The defaults say what the fallbacks are for:

| Capability | Chain | Why |
|---|---|---|
| text | `anthropic` | judgement tasks — grading, feedback, item writing |
| image | `higgsfield,placeholder` | a captioned card still lays the lesson out correctly |
| video | `higgsfield` | no meaningful fallback exists; the feature degrades |
| audio | `higgsfield,espeak` | a robotic voice still teaches; silence does not |
| stt | `whisper` | runs locally, so recordings stay off third-party services |

Switching vendors or inserting a fallback is therefore a configuration change,
never a code change.

Two provider details are worth knowing:

- **Higgsfield ships a CLI, not a REST API.** `HiggsfieldProvider` shells out to
  the binary and parses its JSON. Credentials never enter the application — the
  CLI reads its own token file. If the binary is missing or unauthenticated,
  `isAvailable()` is false and the chain moves on rather than failing the lesson.
- **Generated media is mirrored into our own storage immediately.** Vendor URLs
  expire; a lesson that renders today must still render next year.

### 8.2 Caching by intent, not by URL

Standard lesson media is generated once and reused by every learner. The cache
key is derived from the request itself, and a hit increments `reuse_count` on the
generation. Personalised output opts out with `cacheable = false`.

This is the difference between an AI feature that costs a fixed amount and one
whose bill scales with users. The reuse counter also tells you, later, which
generations were worth making.

### 8.3 Limits before spend

Before any uncached call, `limitCheck()` resolves the applicable rows in
`ai_usage_limits` — per user, per plan, or the global default — and compares them
against `ai_usage` for the current day or month. Requests, cost and credits are
all capped independently. The default fallbacks live in `config/ai.php`
(`AI_DEFAULT_DAILY_REQUESTS`, `AI_DEFAULT_MONTHLY_COST`).

A denial returns a failure result with a human-readable reason. It does not throw:
the caller's job is to degrade gracefully.

### 8.4 The ledger

Every attempt — including failures — writes an `ai_requests` row with provider,
model, feature, status, duration, tokens, credits, estimated cost and the
provider's own request id. Successes additionally write `ai_generations` with the
prompt, the output and the provider metadata. Costs are computed from configured
per-million-token rates.

Two consequences worth stating plainly: you can answer "what did this lesson cost
to build?" and "which provider is failing?" from SQL alone, and a spend anomaly is
attributable to a feature rather than to "the AI".

### 8.5 Honest failure

The alignment path is the clearest statement of the project's attitude to AI
output. `WhisperSpeechProvider::supportsAlignment()` is false unless a real
forced-alignment binary is configured, and `AiOrchestrator::align()` then returns
an explicit "no forced-alignment provider is configured" failure. It would be easy
to approximate phoneme scores from a transcript. That would be a guess dressed up
as a measurement, and a learner told their /θ/ is weak deserves better than that.

Likewise `PlaceholderImageProvider` marks its output `needs_replacement` in
metadata so the admin review queue can find and replace it, and an Anthropic
response with `stop_reason: refusal` is surfaced as a failure rather than passed
through as content.

---

## 9. Queue topology

Six named queues, run as six independent supervisor process pools
(`docker/worker/supervisord.conf`):

| Queue | Work | Timeout | Why it is separate |
|---|---|---|---|
| `default` | ordinary application work | 120s | no special profile |
| `content` | ingestion, curriculum derivation | 1800s | long, bursty, idempotent, restartable |
| `media` | image/video/audio generation and mirroring | 900s | minutes per job; must exceed `HIGGSFIELD_TIMEOUT` |
| `speech` | transcription, forced alignment | 420s | a learner is waiting — short backoff, own workers |
| `ai-high` | tutor replies, live grading, conversation turns | 180s | someone is watching a spinner |
| `ai-low` | batch generation, exam scoring | 600s | cheap in time, expensive in money — few retries, long backoff |

They are separate **process pools** rather than one worker with a
comma-separated `--queue` list, because a comma list is priority-ordered *within
a single worker*: a worker busy on a ten-minute video render cannot pick up an
interactive tutor reply however high its priority. Only separate pools give real
isolation.

Retry policy follows cost, not convenience. `ai-low` retries twice with a long
backoff because every attempt is a real billed call recorded in `ai_requests`;
`speech` retries three times with a five-second backoff because the failure is
usually transient and someone is waiting.

**Current state:** `app/Jobs/Speech/ProcessSpeechAttempt` and
`PurgeExpiredSpeechAudio` both dispatch to the `speech` queue. The other five
queues have workers running and no producers yet — jobs for content, media and
AI work are still being written.

---

## 10. Storage and media

Book audio, page images and generated media are `media_assets` rows plus bytes on
a Laravel filesystem disk. The disk is `local` in development and S3-compatible
elsewhere; `AI_STORAGE_DISK` controls where the AI layer mirrors its output.

The intended delivery path is: API endpoint → entitlement check → signed URL or
`X-Accel-Redirect`. It is **planned, not built** — the media endpoints are still
being written. Accordingly, `docker/nginx/default.conf` deliberately contains no
public alias into `storage/`, and `docker/minio/init.sh` creates the bucket
private and never makes it anonymously readable. It is much easier to keep a
door shut than to close one that everything has started depending on.

---

## 11. Data model, in one page

126 tables. The groupings, in the order the migrations create them:

| Group | Tables | Holds |
|---|---|---|
| Reference | languages, cefr_levels, skills, subskills, topics, parts_of_speech | the vocabulary the rest of the schema is written in |
| Course | courses, course_versions, modules, units, lessons, lesson_blocks | the navigable spine; `lesson_blocks` are what a learner actually sees |
| Lexicon | vocabulary_items, word_families, word_forms, vocabulary_senses, definitions, translations, examples, collocations, sense_relations | word-level knowledge, sense by sense |
| Grammar & pronunciation | grammar_concepts, grammar_rules, phonemes, pronunciation_items, minimal_pairs, stress/intonation patterns | the non-lexical teachable material |
| Discourse | characters, dialogues, dialogue_turns, passages, conversation_scenarios, production_prompts | material for conversation and extended reading |
| Content sources | source_documents, source_files, source_pages, source_segments, ingestion_jobs/stages/issues, audio_assets, audio_mappings, media_assets | provenance and the ingestion audit trail |
| Concept graph | concepts, concept_prerequisites, learning_objectives, lesson_concept, lesson_objective | §4 |
| Exercises | exercise_templates, exercises, exercise_options/answers/hints/explanations, exercise_concepts | the item bank, with IRT parameters |
| Learner | learner_profiles, learner_skill_states, learner_concepts, learner_errors, learner_reviews, review_history, learning_sessions, session_activities, lesson_attempts, exercise_attempts | everything the engines read and write |
| Speech | speech_attempts, speech_words, speech_phonemes, pronunciation_errors, conversation_sessions, conversation_turns | recordings scored down to the phoneme |
| Placement & exam | placement_sessions/skill_states/responses, exam_types, exam_sections, exam_tasks, exam_attempts, exam_scores, exam_score_bands | §7 and exam preparation |
| Billing | plans, plan_prices, plan_entitlements, coupons, subscriptions, invoices, payment_attempts, payment_webhooks, entitlement_usage | subscriptions and entitlements |
| AI | ai_providers, ai_models, ai_prompts, ai_requests, ai_generations, ai_usage, ai_usage_limits, content_reviews | §8 |
| Analytics | user_events, daily_progress, skill_snapshots, achievements, xp_transactions, audit_logs, privacy_requests | progress, gamification, compliance |

---

## 12. Decisions worth restating

- **The backend owns learning state.** Mastery, ability, intervals and difficulty
  are computed server-side and never accepted from the client. Anything else makes
  the model a suggestion.
- **Provenance is permanent.** Every derived row can name the page it came from,
  and generated material is always distinguishable from extracted material.
- **Verification is a build artifact.** `content:audit` and `content:readiness`
  are commands, not a checklist someone remembers to run.
- **Failure degrades, it does not break.** Ordered provider chains, fallback
  providers, and a placeholder that is explicitly marked as one.
- **The system says when it does not know.** Missing aligner means "unavailable",
  not an approximation. Unresolvable unit titles go to review, not to a guess.
- **Cost is a first-class metric.** Reuse caching, per-plan limits and a full
  ledger, because a per-learner AI bill that nobody can attribute is how these
  products die.

---

## 13. What is not built yet

Honest list, as of this document:

- No jobs dispatch to `content`, `media`, `ai-high` or `ai-low` yet; those pools
  idle.
- `routes/console.php` registers no scheduled tasks, so the scheduler container
  runs an empty schedule.
- Media delivery (signed URLs / `X-Accel-Redirect`) is designed but not written.
- No payment gateway is integrated; the billing tables and services exist,
  `payment_webhooks` is ready, but no gateway credentials are read anywhere in
  `config/`.
- `laravel/reverb` is not in `composer.json`, so websockets are configuration
  only.
- The S3 filesystem driver needs `league/flysystem-aws-s3-v3`, which is a
  suggested package and not yet installed.

See [API.md](API.md) for the endpoint-by-endpoint state and
[DEPLOYMENT.md](DEPLOYMENT.md) for what running this in production requires.
