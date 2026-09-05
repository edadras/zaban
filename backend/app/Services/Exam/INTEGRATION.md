# Phase 13 — exam preparation engine and AI examiner: integration notes

Everything under `app/Services/Exam`, `app/Http/Controllers/Api/V1/Exam`,
`app/Http/Requests/Api/Exam`, `app/Http/Resources/Exam`,
`database/seeders/ExamSeeder.php` and `tests/Feature/Exam` is self-contained and
tested. Three things live outside that ownership and have to be wired in by
whoever owns them.

---

## 1. Routes (`routes/api.php`) — required

Exactly what `tests/Feature/Exam/ExamTestCase::registerRoutes()` declares, so the
suite and production agree:

```php
use App\Http\Controllers\Api\V1\Exam\ExamAttemptController;
use App\Http\Controllers\Api\V1\Exam\ExamProgressController;
use App\Http\Controllers\Api\V1\Exam\ExamSpeakingController;
use App\Http\Controllers\Api\V1\Exam\ExamTypeController;

Route::middleware(['auth:sanctum'])->prefix('v1/exams')->name('exams.')->group(function () {
    Route::get('types', [ExamTypeController::class, 'index'])->name('types.index');
    Route::get('types/{examType}', [ExamTypeController::class, 'show'])->name('types.show');

    Route::post('attempts', [ExamAttemptController::class, 'store'])->name('attempts.store');
    Route::get('attempts/{attempt}', [ExamAttemptController::class, 'show'])->name('attempts.show');
    Route::get('attempts/{attempt}/next-task', [ExamAttemptController::class, 'nextTask'])->name('attempts.next-task');
    Route::post('attempts/{attempt}/tasks/{task}/response', [ExamAttemptController::class, 'submit'])->name('attempts.submit');
    Route::post('attempts/{attempt}/finish', [ExamAttemptController::class, 'finish'])->name('attempts.finish');
    Route::get('attempts/{attempt}/results', [ExamAttemptController::class, 'results'])->name('attempts.results');

    Route::get('attempts/{attempt}/speaking', [ExamSpeakingController::class, 'next'])->name('speaking.next');
    Route::post('attempts/{attempt}/speaking/response', [ExamSpeakingController::class, 'respond'])->name('speaking.respond');
    Route::get('attempts/{attempt}/speaking/score', [ExamSpeakingController::class, 'score'])->name('speaking.score');

    Route::get('progress', [ExamProgressController::class, 'index'])->name('progress.index');
});
```

Implicit bindings: `{examType}` → `App\Models\ExamType`, `{attempt}` →
`App\Models\ExamAttempt`, `{task}` → `App\Models\ExamTask`. Every action checks
ownership itself and returns `403` for another learner's attempt.

Rate limiting: `finish`, `speaking/response` and `speaking/score` each cost model
calls. Something like `->middleware('throttle:120,1')` on the group with
`throttle:20,1` on those three is enough.

### Endpoint summary

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/v1/exams/types` | The four profiles with sections, timings and the CEFR mapping. |
| `GET` | `/v1/exams/types/{examType}` | One profile, additionally with its question types. |
| `POST` | `/v1/exams/attempts` | Start a sitting. `exam_type_id`, `mode` (`practice`\|`mock`\|`section`), `exam_section_id` (required for `section`). |
| `GET` | `/v1/exams/attempts/{attempt}/next-task` | The next task under the section clock, or `{"complete": true}`. |
| `POST` | `/v1/exams/attempts/{attempt}/tasks/{task}/response` | Submit one task. `answers` (objective), `text` (writing), `speech_attempt_id` (speaking), `seconds_used`. |
| `POST` | `/v1/exams/attempts/{attempt}/finish` | Close and score; returns the full result payload. |
| `GET` | `/v1/exams/attempts/{attempt}/results` | The same payload again, without re-scoring. |
| `GET` | `/v1/exams/attempts/{attempt}/speaking` | The next examiner question with its prep and speaking clocks. |
| `POST` | `/v1/exams/attempts/{attempt}/speaking/response` | Hand the examiner one `speech_attempt_id`; returns the next question. |
| `GET` | `/v1/exams/attempts/{attempt}/speaking/score` | The four speaking criterion estimates. |
| `GET` | `/v1/exams/progress?exam_type_id=` | Estimated band over time, overall and per skill. |

Refusals come back as `{"error":{"code":…}}` with a stable code:
`exam_section_expired` (409), `exam_attempt_expired` (409),
`exam_attempt_not_in_progress` (409), `exam_task_not_available` (422),
`exam_no_content` (422), `exam_invalid_mode` (422), `exam_section_mismatch` (422),
`exam_not_speaking_section` (422), `exam_no_open_question` (409),
`exam_speech_attempt_not_found` (404).

## 2. Seeder registration (`database/seeders/DatabaseSeeder.php`) — required

`ExamSeeder` must run after `ReferenceDataSeeder` (it resolves languages, skills,
CEFR levels and exercise templates by code and throws if they are missing):

```php
$this->call([
    ReferenceDataSeeder::class,
    // …
    ExamSeeder::class,
]);
```

It is idempotent — `updateOrCreate` on `(code)` for exam types, on
`(exam_type_id, code)` for sections and `(exam_section_id, code)` for task types
— so re-running it is safe and is how a profile change is deployed.

## 3. Nothing needed in `config/`

No config keys. Everything an exam knows about itself is a database row, which is
the point: a fifth exam is a `profiles()` entry in `ExamSeeder`, not a code change.

---

## Suggested follow-ups (not blocking)

**`exam_responses` table.** The schema has nowhere to keep a learner's written or
spoken submission, so `ExamService` stores each one as an `exam_scores` row under
the reserved criterion `_response:<exam task id>`, with the submission in
`evidence`. It is indexed by `(exam_attempt_id, criterion)` and works, but a
dedicated table would be cleaner:

```php
Schema::create('exam_responses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
    $table->foreignId('exam_section_attempt_id')->constrained()->cascadeOnDelete();
    $table->foreignId('exam_task_id')->constrained()->cascadeOnDelete();
    $table->string('kind', 24);                 // objective|writing|speaking
    $table->longText('text')->nullable();
    $table->json('payload')->nullable();        // items, speech attempt ids, measured signals
    $table->decimal('raw_score', 6, 2)->nullable();
    $table->unsignedSmallInteger('items_marked')->default(0);
    $table->unsignedInteger('seconds_used')->nullable();
    $table->timestamps();
    $table->unique(['exam_attempt_id', 'exam_task_id']);
});
```

Migrating is mechanical: read the `_response:` rows, write them across, and change
the four accessors in `ExamService` (`writeResponseRow`, `responsesFor`,
`responseFor`, `submittedTaskIds`).

**Exam link on `conversation_sessions`.** `AiExaminerService` keeps the speaking
interview in `conversation_sessions` / `conversation_turns` — the right tables for
a spoken exchange with per-turn recordings — but there is no exam foreign key, so
the link lives in `objectives_met->exam`. A nullable
`exam_section_attempt_id` on `conversation_sessions` would make it explicit and
indexable.

**`exam_section_attempts.status` vocabulary.** The column is a free string; this
phase writes `pending`, `in_progress`, `completed`, `scored`, `projected`,
`not_attempted` and `scoring_unavailable`. Worth recording in the migration
comment if the column is ever tightened.

**Task weighting.** IELTS Task 2 counting double is expressed as
`scoring_criteria.task_weights` on the section rather than a column on
`exam_task_types`. If more exams need per-task weights, a `weight` column there
would be the better home.

---

## What is not built here, and why

* **Exam content.** The seeder creates profiles, not questions: no `exam_tasks`,
  no passages, no cue cards. `ExamService::sectionTasks` serves only tasks with
  status `approved` or `published`, so until the content pipeline produces them
  `nextTask` returns "complete" immediately and a sitting scores nothing. The
  authoring/generation of exam items belongs to the content phase.
* **Speaking audio.** The examiner takes a `speech_attempt_id` that the speech
  phase has already created and scored; it does not upload or transcribe audio.
