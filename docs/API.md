# API reference — `/api/v1`

The Zaban backend exposes one versioned JSON API, consumed by the Flutter client
and by the admin tooling.

**How to read this document.** Every endpoint is marked with its state:

| Mark | Meaning |
|---|---|
| **Live** | route registered in `routes/api.php` (or a file it requires) and the controller method exists |
| **Planned** | designed and reserved, but no route and/or no controller method exists yet |

Nothing here is described as working unless it was verified in the code. The
`Planned` sections are included because the client team needs to know the shape
that is coming, not because it is available.

---

## 1. Conventions

### Base URL and versioning

```
https://<host>/api/v1
```

The version lives in the path so the server can be upgraded without breaking an
installed app. `routes/api.php` wraps the whole surface in
`Route::prefix('v1')`.

### The envelope

Every response uses the same shape, produced by `App\Support\ApiResponse`. One
shape means the client needs one parser and one error path.

Success:

```json
{
  "data": { "...": "..." }
}
```

Success with metadata (pagination, counters):

```json
{
  "data": [ … ],
  "meta": { "page": 1, "per_page": 25, "total": 340, "last_page": 14 }
}
```

Failure:

```json
{
  "data": null,
  "error": {
    "code": "placement_closed",
    "message": "This placement session is already finished.",
    "details": { "…": "…" }
  }
}
```

`meta` is omitted when empty; `error.details` is omitted when there is nothing to
add. `204 No Content` responses have no body.

Paginated collections get `page`, `per_page`, `total` and `last_page` folded into
`meta` automatically — the client never has to handle two pagination formats.

### Content type

`ForceJsonResponse` is prepended to the API middleware group and sets
`Accept: application/json` on every request. Together with
`shouldRenderJsonWhen()` in `bootstrap/app.php`, that means *framework-level*
failures — validation errors, model-not-found, unhandled exceptions — also come
back as JSON, never as an HTML error page.

### Timestamps and numbers

Timestamps are ISO-8601 with offset (`2026-09-05T14:03:11+00:00`). Ability,
difficulty and mastery values are JSON numbers, not strings: mastery is `0..1`,
ability and difficulty are logits (roughly `-6..6`), probabilities are `0..1`.

---

## 2. Authentication

Laravel Sanctum, bearer-token mode. The mobile client is stateless: it holds a
personal access token and sends it on every request.

```
Authorization: Bearer 17|kR3q…
```

### Flow

1. `POST /auth/register` or `POST /auth/login` returns `{ user, token }`.
2. The client stores the token in the platform keystore and sends it as a bearer
   header.
3. `POST /auth/logout` deletes **the current token only**, so signing out on the
   phone does not sign out the tablet.

Tokens are named per device (`AuthController::deviceName()`), which is what makes
per-device revocation meaningful.

`config/sanctum.php` also supports the stateful cookie path
(`SANCTUM_STATEFUL_DOMAINS`) for a same-site browser client. The mobile client
does not use it and should not be configured for it.

`SANCTUM_TOKEN_PREFIX` is worth setting in production: it stamps a recognisable
prefix on issued tokens so secret scanners can spot a leaked one.

### Endpoints — **Live**

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/auth/register` | none | Creates `users`, `user_profiles`, `user_settings` and `learner_profiles` in one transaction, so placement has somewhere to write the moment the learner starts. Returns `201` with `{user, token}`. |
| POST | `/auth/login` | none | Returns `{user, token}`. Inactive accounts get `403 account_inactive`. |
| POST | `/auth/logout` | bearer | Revokes the calling token. |
| GET | `/auth/me` | bearer | Current user with profile, settings and learner profile. |
| POST | `/auth/forgot-password` | none | Always reports success. |
| POST | `/auth/reset-password` | none | Token from the reset email. |
| GET | `/auth/verify/{id}/{hash}` | signed URL | Email verification; requires a valid Laravel signature. |
| POST | `/auth/resend-verification` | bearer | Rate-limited with the auth limiter. |

Two deliberate behaviours:

- **Login does not say which half was wrong.** A wrong email and a wrong password
  produce the same message, because a differing response is an account-enumeration
  oracle.
- **Forgot-password always reports success**, for the same reason.

---

## 3. Errors

### HTTP status

| Status | When |
|---|---|
| 200 | success |
| 201 | resource created |
| 204 | success, no body |
| 401 | missing or invalid token |
| 403 | authenticated but not allowed (inactive account, non-admin) |
| 404 | not found — also returned for another user's resource (see below) |
| 422 | validation failure, or a valid request in the wrong state |
| 429 | rate limit exceeded |
| 500 | unhandled server error |

**404 rather than 403 for someone else's data.** `SessionController` and
`PlacementController` both `abort_unless($x->user_id === $user->id, 404)`. A 403
would confirm that the id exists, which is information the caller has not earned.

### Error codes

`error.code` is a stable machine-readable string; `error.message` is
human-readable and may change. Codes verified in the current code:

| Code | Status | Where |
|---|---|---|
| `account_inactive` | 403 | login with a non-active account |
| `forbidden` | 403 | `EnsureAdmin` — caller lacks admin/editor/reviewer role |
| `no_hint` | 404 | requested hint level does not exist |
| `no_version` | 404 | course has no published version |
| `activity_mismatch` | 422 | activity does not belong to that session |
| `placement_closed` | 422 | submitting to a finished placement session |

Validation failures come from Laravel's `ValidationException` and carry the field
errors; other framework errors (401, 404, 500) are rendered as JSON by the
exception handler.

---

## 4. Rate limiting

Named limiters are defined in `App\Providers\AppServiceProvider::rateLimits()`:

| Limiter | Limit | Keyed by | Applied to |
|---|---|---|---|
| `auth` | 5/min | IP **and** submitted email | register, login, forgot/reset password, resend verification |
| `webhooks` | 300/min | IP | the webhook route group |
| `api` | 120/min | user id, else IP | *defined but not currently attached to a route group* |
| `ai` | 20/min | user id, else IP | *defined; for AI-backed endpoints when they land* |
| `speech` | 12/min | user id, else IP | *defined; for recording upload when it lands* |

The two-key `auth` limiter is the important one: limiting by IP alone lets an
attacker spread a credential-stuffing run across a botnet, and limiting by email
alone lets them enumerate freely from one address.

> **Gap, stated plainly:** the `api`, `ai` and `speech` limiters exist but no
> route group currently applies them — `bootstrap/app.php` does not add a
> `throttle` middleware to the API group. Until that changes, authenticated
> endpoints are unthrottled. This is on the pre-production checklist in
> [DEPLOYMENT.md](DEPLOYMENT.md).

---

## 5. Endpoint groups

### 5.1 Profile — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/profile` | user + profile + settings + learner profile (with CEFR level) |
| PATCH | `/profile` | name, timezone, locale, country, date of birth, native/target language, learning objective, profession, interests, favourite topics |
| PATCH | `/profile/settings` | daily/weekly goals, preferred study time, theme, notification flags, **speech consent and retention** |
| POST | `/profile/avatar` | multipart `avatar`; jpg/png/webp, max 4 MB; returns `avatar_url` |
| POST | `/profile/export` | creates a `privacy_requests` row (`type: export`), processed asynchronously |
| POST | `/profile/delete` | requires `confirm: true`; creates a deletion request **and revokes every token immediately** |

Personalisation inputs are limited to declared, non-sensitive categories —
interests and favourite topics are capped at 12 entries of 40 characters. Nothing
is inferred.

Speech settings are the consent record: `speech_consent_given` timestamps
`speech_consent_at` when granted and clears it when withdrawn, so retention jobs
can act on a withdrawal. `speech_retention_days` is 1–730.

### 5.2 Placement (adaptive test) — **Live**

| Method | Path | Notes |
|---|---|---|
| POST | `/placement/start` | body `{language?: "en"}`. Idempotent: an in-progress session is returned rather than a second one being created. |
| GET | `/placement/{session}/next` | the next adaptively selected item, or `{complete: true, result}` |
| POST | `/placement/{session}/submit` | `{exercise_id, response, response_ms?}` |
| GET | `/placement/{session}/result` | per-skill profile |

`next` returns either

```json
{ "data": { "complete": false,
            "progress": { "items_administered": 7, "max_items": 40 },
            "item": { "id": 812, "skill": "reading", "template": "multiple_choice",
                      "stem": "…", "instructions": "…",
                      "options": [ {"id": 3301, "position": 0, "text": "…"} ] } } }
```

or, once every dimension has converged,

```json
{ "data": { "complete": true,
            "result": { "overall": { "cefr": "B1", "ability": 0.42,
                                     "standard_error": 0.29, "confidence": 0.917 },
                        "skills": [ { "skill": "reading", "cefr": "B2",
                                      "ability": 0.91, "standard_error": 0.31,
                                      "confidence": 0.9, "items": 6, "complete": true } ],
                        "items_administered": 22,
                        "stop_reason": "precision_reached" } } }
```

**`submit` never tells the learner whether they were right.** It returns
`{recorded: true, complete, result}`. Item-by-item feedback would let a test-taker
infer the difficulty ladder and walk it deliberately, which destroys the
measurement. See [ARCHITECTURE.md §7](ARCHITECTURE.md) for how items are chosen.

### 5.3 Courses and lessons — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/courses` | active courses with CEFR span and unit count |
| GET | `/courses/{course}` | published version's modules and units (falls back to the latest version) |
| GET | `/units/{unit}` | unit with its lessons |
| GET | `/lessons/{lesson}` | lesson with its ordered blocks |

`GET /courses/{course}` returns `404 no_version` when the course has no version
at all.

### 5.4 The daily session — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/session/next` | the active session, or a newly composed one. `?minutes=` overrides the learner's daily target. `meta.due_reviews` carries the backlog count. |
| POST | `/session/start` | abandons any active session and composes a fresh one (`201`) |
| GET | `/session/{session}` | one session with its ordered activities |
| POST | `/session/{session}/activities/{activity}/complete` | marks one activity done; returns `{activity_id, remaining, session_status}` and auto-completes the session when nothing is pending |
| POST | `/session/{session}/complete` | ends the session; optional `{seconds}` |

Composition happens on the server — the client asks what to do next and renders
it. Each activity carries a `why` object (the persisted `selection_reason`), so
the UI can explain *why this appeared*:

```json
{
  "id": 4471, "position": 2, "type": "review", "status": "pending",
  "concept_id": 9182, "predicted_success": 0.79,
  "why": { "driver": "spaced_repetition", "due_since": "2026-09-04T08:00:00+00:00",
           "forgetting_probability": 0.61, "mastery": 0.4 },
  "subject": { "kind": "exercise", "id": 55021, "template": "multiple_choice",
               "stem": "…", "instructions": "…", "difficulty": -0.35,
               "options": [ {"id": 1, "position": 0, "text": "…"} ] }
}
```

The `subject` is embedded so the client can render the activity without a second
round trip. **Option correctness is never included** — grading is server-side.

### 5.5 Exercises — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/exercises/{exercise}` | stem, instructions, payload, difficulty, options, `hints_available` |
| GET | `/exercises/{exercise}/hint?level=1` | one hint level at a time; `404 no_hint` past the last |
| POST | `/exercises/{exercise}/submit` | grade a response |

Submit body:

```json
{ "response": 3301, "hints_used": 1, "response_ms": 4200,
  "learning_session_id": 77, "session_activity_id": 4471 }
```

`response` accepts an option id, an option's text, or a free-text answer
depending on the item. Response:

```json
{ "data": {
    "attempt_id": 90210, "correct": false, "score": 0.0,
    "expected": "have been living",
    "explanation": "…", "feedback": { "distractor_rationale": "…" },
    "mastery": [ { "concept_id": 9182, "mastery_score": 0.22,
                   "next_review_at": "2026-09-06T09:12:00+00:00" } ] } }
```

What happens behind one submit, because it explains the response fields:

- graded against the stored key — multiple choice by the option flag, free text by
  `match_mode` (`exact`, `normalised`, `regex`, `fuzzy`) with per-answer partial
  credit;
- an `exercise_attempts` row is written with the ability at attempt time and the
  predicted success, which is what makes the item bank self-calibrating;
- mastery is updated for **every** concept the item tests (`exercise_concepts`);
- a wrong answer records a `learner_errors` entry; a right one clears those errors
  only once mastery reaches "strong";
- the learner's ability estimate is updated.

**Open-ended items are not silently scored.** With no answer key and no options,
the response comes back `correct: false, score: 0` with
`feedback.requires_review: true` and a message saying it needs review — rather
than a fabricated grade. AI grading of productive items is **planned**.

`expected` is `null` when the answer was correct, so the key is not handed out
for free on every request.

### 5.6 Reviews (spaced repetition) — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/reviews/due?limit=30` | due items, **most-forgotten first**, max 100; `meta.total_due` is the full backlog |
| GET | `/reviews/counts` | `{due: n}` — cheap enough for a badge |

Each due entry carries `concept_id`, `label`, `mastery_score`, `interval_days`,
`due_since`, `forgetting_probability` and a suggested `exercise_id` chosen at the
learner's ability.

### 5.7 Progress — **Live**

| Method | Path | Notes |
|---|---|---|
| GET | `/progress/dashboard` | everything the home screen needs, in one call |
| GET | `/progress/skills` | per-skill ability and CEFR |
| GET | `/progress/history` | daily progress rows |
| GET | `/progress/trend` | skill snapshots over time |

`dashboard` returns CEFR level, ability, placement status, streaks, XP, overall
mastery, total study minutes, a `today` block (study seconds, goal progress,
attempts, correct, goal met), due review count, vocabulary learned (concepts at
"competent" or better), concepts tracked, a skill radar and the five weakest
areas. It is one call by design: a dashboard that needs six requests is a
dashboard that renders in pieces.

### 5.8 Admin — **Live**

All under `/admin`, inside the authenticated group and further gated by the
`admin` middleware (`EnsureAdmin`), which admits roles `admin`, `editor` and
`reviewer` and returns `403 forbidden` otherwise.

**Ingestion**

| Method | Path |
|---|---|
| GET | `/admin/ingestion/summary` |
| GET | `/admin/ingestion/documents` |
| GET | `/admin/ingestion/jobs` |
| GET | `/admin/ingestion/jobs/{job}` |
| GET | `/admin/ingestion/issues` |
| GET | `/admin/ingestion/audio/unmapped` |
| POST | `/admin/ingestion/audio/{mapping}/review` |

**Generated-content review**

| Method | Path |
|---|---|
| GET | `/admin/content/queue` |
| GET | `/admin/content/reviews/{review}` |
| POST | `/admin/content/reviews/{review}/revalidate` |
| POST | `/admin/content/reviews/{review}/decide` |
| POST | `/admin/content/validate-batch` |
| POST | `/admin/content/auto-publish` |

**AI cost and reliability**

| Method | Path |
|---|---|
| GET | `/admin/ai/overview` |
| GET | `/admin/ai/daily` |
| GET | `/admin/ai/failures` |
| GET | `/admin/ai/providers` |
| GET | `/admin/ai/limits` |
| POST | `/admin/ai/limits` |

`POST /admin/ai/limits` writes `ai_usage_limits` rows, which is how a per-plan or
per-user spend cap is set. The orchestrator enforces them before every uncached
call.

**Users**

| Method | Path |
|---|---|
| GET | `/admin/users` |
| GET | `/admin/users/{user}` |
| PATCH | `/admin/users/{user}` |
| GET | `/admin/audit-log` |

---

## 6. Groups still being built

`routes/api.php` already contains the loader for these modules — each is
`require`d from `routes/api/<module>.php` when that file exists — so they will
appear without further wiring. As of this document only `routes/api/admin.php`
exists.

### 6.1 Subscription and billing — **Planned**

Route file: `routes/api/billing.php` *(not present)*.
Services present: `SubscriptionService`, `EntitlementService`, `InvoiceService`,
`CouponService`, `WebhookService`, `GatewayManager`.
Schema present: `plans`, `plan_prices`, `plan_entitlements`, `coupons`,
`subscriptions`, `subscription_transactions`, `payment_attempts`, `invoices`,
`coupon_redemptions`, `payment_webhooks`, `entitlement_usage`.

Intended surface: list plans, current subscription, subscribe, change plan,
cancel/resume, invoices, redeem a coupon, entitlement status.

**No payment gateway is integrated.** No gateway credentials are read anywhere in
`config/`, so nothing charges a card today.

### 6.2 Speech — **Planned (partly built)**

Route file: `routes/api/speech.php` *(not present)*. Controller directory
`Api/V1/Speech/` exists but is empty.

Already built: `App\Jobs\Speech\ProcessSpeechAttempt` and `PurgeExpiredSpeechAudio`
(both dispatched to the `speech` queue), and a full analysis stack under
`app/Services/Speech` — `SpeechAnalysisService`, `SpeechScorer`, `PhonemeScorer`,
`WordAligner`, `SequenceAligner`, `FluencyAnalyser`, `TranscriptErrorDetector`,
`SpeechFeedbackService`, `PronunciationProfileService`, `SpeechRetentionService`.
Schema: `speech_attempts`, `speech_words`, `speech_phonemes`,
`pronunciation_errors`.

Intended surface: upload a recording, poll or receive its score, fetch the
pronunciation profile, list weak phonemes.

Two constraints that will show in the API: consent (`speech_consent_given` in
settings) gates recording, and **phoneme-level scoring requires a configured
forced aligner**. Without one, `AiOrchestrator::align()` returns an explicit
"no forced-alignment provider is configured" failure rather than approximating
phoneme scores from a transcript.

### 6.3 Conversation — **Planned**

Schema present: `conversation_sessions`, `conversation_turns`,
`conversation_scenarios`, `characters`, `dialogues`, `dialogue_turns`.
No controller, no routes, no service.

Intended surface: start a scenario, exchange turns, close a session with
feedback. Turns will run on the `ai-high` queue because a learner is waiting.

### 6.4 Exam preparation — **Planned (partly built)**

Route file: `routes/api/exam.php` *(not present)*. Controller directory
`Api/V1/Exam/` exists but is empty.
Services present: `ObjectiveGrader`, `SectionScoring`, `ExamEstimate`.
Schema: `exam_types`, `exam_score_bands`, `exam_sections`, `exam_task_types`,
`exam_tasks`, `exam_attempts`, `exam_section_attempts`, `exam_scores`.

Intended surface: list exam types, start an attempt, submit sections, retrieve
scores and band estimates.

### 6.5 Media — **Planned**

No routes, no controller.

Intended surface: request a playable URL for a `media_asset`, with an entitlement
check and either a signed object-store URL or an `X-Accel-Redirect`. Deliberately
not built yet, and deliberately not worked around: `docker/nginx/default.conf`
exposes no path into `storage/`, and `docker/minio/init.sh` creates the bucket
private. See [ARCHITECTURE.md §10](ARCHITECTURE.md).

### 6.6 Webhooks — **Planned**

`routes/api.php` reserves `/api/v1/webhooks/*` **outside** the authenticated
group, throttled at 300/min per IP, loading `routes/api/webhooks.php` *(not
present)*. Each gateway's controller is responsible for verifying its own
signature — a webhook authenticates by signature, never by session. Received
payloads are recorded in `payment_webhooks`.

---

## 7. Client notes

- **Never compute learning state on the client.** Mastery, ability, intervals and
  correctness are server-owned. The API deliberately does not send answer keys,
  option correctness flags, or anything that would let the client grade itself.
- **Treat `why` as displayable.** The `selection_reason` on each session activity
  is designed to be shown ("you last saw this 6 days ago and are likely to have
  forgotten it"), not just logged.
- **Expect 429s and back off.** The auth limiter is 5/min per IP *and* per email.
- **Store the token in the platform keystore**, not in shared preferences, and
  call `/auth/logout` on sign-out so the device's token is actually revoked.
