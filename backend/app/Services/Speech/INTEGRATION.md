# Phase 9 - Speech & pronunciation engine: integration notes

Everything below lives outside this phase's file ownership (`routes/*`, `config/*`,
`bootstrap/*`, `app/Models/*`, `app/AI/*`) and has to be wired in by whoever owns
those files. Nothing in `app/Services/Speech`, `app/Jobs/Speech`, the speech
controllers or the seeder depends on being wired in first - the tests register
the routes themselves - but the feature is not reachable over HTTP until it is.

---

## 1. Routes (`routes/api.php`)

Exactly what `tests/Feature/Speech/SpeechTestCase::registerRoutes()` declares:

```php
use App\Http\Controllers\Api\V1\Speech\PronunciationProfileController;
use App\Http\Controllers\Api\V1\Speech\SpeechAttemptController;
use App\Http\Controllers\Api\V1\Speech\SpeechRecordingController;

Route::middleware(['auth:sanctum'])->prefix('v1/speech')->name('speech.')->group(function () {
    Route::get('attempts', [SpeechAttemptController::class, 'index'])->name('attempts.index');
    Route::post('attempts', [SpeechAttemptController::class, 'store'])->name('attempts.store');
    Route::get('attempts/{attempt}', [SpeechAttemptController::class, 'show'])->name('attempts.show');

    // Privacy (spec 45): audio only, scores and phoneme statistics survive.
    Route::delete('attempts/{attempt}/recording', [SpeechRecordingController::class, 'destroy'])->name('recordings.destroy');
    Route::delete('recordings', [SpeechRecordingController::class, 'destroyAll'])->name('recordings.destroy-all');

    Route::get('profile', [PronunciationProfileController::class, 'show'])->name('profile.show');
    Route::get('profile/drills', [PronunciationProfileController::class, 'drills'])->name('profile.drills');
});
```

`{attempt}` binds to `App\Models\SpeechAttempt`; both controllers check ownership
themselves and return `403 forbidden` for another learner's attempt.

Rate limiting: `store` writes a file and queues a transcription, so it wants its
own throttle - something like `->middleware('throttle:60,1')` on the group and a
tighter `throttle:20,1` on `store`.

### Endpoint summary

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/v1/speech/attempts` | Upload a recording. `201` with a `pending` attempt, or `403 speech_consent_required`. |
| `GET` | `/v1/speech/attempts` | Paginated list of the learner's attempts (no word detail). |
| `GET` | `/v1/speech/attempts/{attempt}` | One attempt with word and phoneme detail and the feedback payload. |
| `DELETE` | `/v1/speech/attempts/{attempt}/recording` | Delete one recording, keep its scores. |
| `DELETE` | `/v1/speech/recordings` | Delete all of this learner's recordings. `?include_analysis=1` also drops `pronunciation_errors`. |
| `GET` | `/v1/speech/profile` | Full per-phoneme profile plus drill targets. |
| `GET` | `/v1/speech/profile/drills?limit=5` | Just the drill targets, for the session builder. |

`POST /v1/speech/attempts` fields: `audio` (required file, ≤25 MB, audio mimetype),
`expected_text`, `duration_ms`, `exercise_id`, `production_prompt_id`,
`pronunciation_item_id`, `learning_session_id`.

---

## 2. Queue

`ProcessSpeechAttempt` and `PurgeExpiredSpeechAudio` both run on the **`speech`**
queue. A worker has to cover it:

```
php artisan queue:work --queue=speech,default --timeout=900
```

`ProcessSpeechAttempt` sets `timeout = 600` and uses `WithoutOverlapping`, so the
worker's `--timeout` must be higher than the job's.

## 3. Scheduler (`routes/console.php`)

```php
use App\Jobs\Speech\PurgeExpiredSpeechAudio;

Schedule::job(new PurgeExpiredSpeechAudio(500))->hourly()->name('speech-audio-retention');
```

Retention is a legal commitment, so this should be monitored - a silently dead
schedule means recordings outliving their window.

## 4. Config

Nothing new is required; the speech pipeline reads what already exists:

- `config/ai.php` → `chains.stt` and `providers.whisper.*`. Setting
  `ALIGNER_BINARY` (and `ALIGNER_DICTIONARY`) is what turns phoneme scoring on.
  Without it, `AiOrchestrator::align()` fails by design and attempts are scored
  without a pronunciation score.
- `config/filesystems.php` → recordings are written to the **`local`** disk under
  `speech/{user_id}/{uuid}.{ext}`. That disk is private
  (`storage/app/private`); it must stay private if it is ever repointed at S3.
  The disk name is `SpeechRetentionService::DISK` if it ever needs to move.
- `config/ai.php` → `chains.text` is used for the narrative half of the feedback.
  With no text provider configured the feedback falls back to a rules-based
  summary built from the same measurements; nothing breaks.

## 5. Seeding

Add to `DatabaseSeeder::run()` **after** `ReferenceDataSeeder` (it needs the
`en` language row and reads CEFR levels if present):

```php
$this->call(PhonemeSeeder::class);
```

It is idempotent (`updateOrCreate` throughout) and seeds 40 phonemes, 59
pronunciation items with their ordered phoneme sequences, and 32 minimal pairs.

## 6. What the learning engine should call

- `PronunciationProfileService::drillTargets(int $userId, int $limit = 5)` -
  the learner's live problem sounds with example words, the sounds they are being
  confused with, articulation hints and ready-made minimal pairs.
- `PronunciationProfileService::problemPhonemes(int $userId)` - the raw rows.
- Word-level and grammar errors are already written to `learner_errors` through
  `RemediationService::recordError`, so the existing remediation flow picks them
  up with no extra wiring. Error types used: `article`, `preposition`, `grammar`,
  `word_order`, `vocabulary_confusion`, `pronunciation`.

---

## 7. Known defect outside this phase's ownership

`App\AI\AiOrchestrator::finish()` writes a **negative** `duration_ms`:

```php
'duration_ms' => $log->started_at ? (int) (now()->diffInMilliseconds($log->started_at)) : null,
```

Carbon 3 returns a *signed* difference, and `$log->started_at` is not cast to a
datetime on `AiRequest`, so it is compared as a second-truncated string. The
result is a small negative number, and `ai_requests.duration_ms` is an unsigned
integer - so **every AI call fails with SQLSTATE[22003] on MySQL/MariaDB**. This
is not specific to speech; it affects text, media and STT alike.

Fix (in `app/AI/AiOrchestrator.php`, not owned here):

```php
'duration_ms' => $log->started_at ? (int) $log->started_at->diffInMilliseconds(now(), true) : null,
```

...with `'started_at' => 'datetime'` added to `AiRequest::casts()`.

Until that lands, `tests/Feature/Speech/SpeechTestCase` freezes the clock on a
whole second so the truncation cannot produce a negative value. That is a
work-around in the test rig only; production still hits the bug on every AI call.

---

## 8. Design decisions worth knowing before changing anything

**A null score means "not measured".** `speech_attempts.pronunciation_score` is
null whenever no forced aligner ran, and `feedback.not_measured.pronunciation`
carries the reason. The same holds for fluency (no word timings), completeness
and grammar (no target text) and vocabulary (read-aloud, or under 20 words). The
client must render null as "not measured", never as zero. `overall_score` is a
weighted mean over whichever components exist, renormalised - so an attempt with
no pronunciation score is still comparable on what it did measure.

**Phoneme statistics are only ever written from real alignment.** Nothing in the
pipeline derives phoneme accuracy from a transcript, so a deployment without an
aligner produces an empty `pronunciation_errors` table rather than a plausible
but fictional one.

**The LLM never produces a number.** `SpeechFeedbackService` sends the model the
measurements and gets back prose under a JSON schema; scores are written from
`SpeechScorer` and `PhonemeScorer` before the feedback call happens.

**Audio and analysis have separate lifetimes.** `deleteAudio()` nulls
`media_asset_id`, sets `audio_deleted`, removes the file and soft-deletes the
media asset, and touches nothing else. `SpeechRetentionService::deleteAnalysisFor()`
is the separate, explicit erasure of the derived profile.
