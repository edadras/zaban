<?php

namespace Tests\Feature\Exam;

use App\Http\Controllers\Api\V1\Exam\ExamAttemptController;
use App\Http\Controllers\Api\V1\Exam\ExamProgressController;
use App\Http\Controllers\Api\V1\Exam\ExamSpeakingController;
use App\Http\Controllers\Api\V1\Exam\ExamTypeController;
use App\Models\ExamSection;
use App\Models\ExamTask;
use App\Models\ExamTaskType;
use App\Models\ExamType;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\User;
use Database\Seeders\ExamSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Shared setup for the exam suite: the real reference data and the real exam
 * profiles, so the tests exercise the seeded IELTS/TOEFL/Cambridge/PTE rows
 * rather than a fixture that could drift away from them.
 */
abstract class ExamTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ExamSeeder::class);
    }

    /**
     * The routes this phase needs. routes/api.php belongs to another owner, so
     * the suite declares them itself; INTEGRATION.md carries the same block for
     * whoever wires it in.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/exams')->name('exams.')->group(function () {
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
    }

    protected function learner(): User
    {
        return User::factory()->create();
    }

    protected function examType(string $code): ExamType
    {
        return ExamType::where('code', $code)->firstOrFail();
    }

    protected function section(string $examCode, string $sectionCode): ExamSection
    {
        return ExamSection::where('exam_type_id', $this->examType($examCode)->id)
            ->where('code', $sectionCode)
            ->firstOrFail();
    }

    /**
     * A published task carrying multiple-choice items with a single correct
     * option each.
     *
     * @param  array<int, array{stem: string, options: string[], correct: int}>  $items
     */
    protected function objectiveTask(ExamSection $section, string $taskTypeCode, array $items, int $position = 0): ExamTask
    {
        $task = $this->task($section, $taskTypeCode, $position);
        $languageId = Language::where('code', 'en')->value('id');
        $templateId = ExerciseTemplate::where('code', 'multiple_choice')->value('id');

        foreach ($items as $i => $item) {
            $exercise = Exercise::create([
                'exercise_template_id' => $templateId,
                'language_id' => $languageId,
                'skill_id' => $section->skill_id,
                'stem' => $item['stem'],
                'difficulty' => $item['difficulty'] ?? 0.0,
                'status' => 'published',
                'is_exam_eligible' => true,
            ]);

            foreach ($item['options'] as $p => $text) {
                ExerciseOption::create([
                    'exercise_id' => $exercise->id,
                    'position' => $p,
                    'text' => $text,
                    'is_correct' => $p === $item['correct'],
                ]);
            }

            DB::table('exam_task_exercise')->insert([
                'exam_task_id' => $task->id,
                'exercise_id' => $exercise->id,
                'position' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $task->fresh('taskType');
    }

    /**
     * A published task carrying short-answer items marked from exercise_answers.
     *
     * @param  array<int, array{stem: string, answer: string, match_mode?: string}>  $items
     */
    protected function shortAnswerTask(ExamSection $section, string $taskTypeCode, array $items, int $position = 0): ExamTask
    {
        $task = $this->task($section, $taskTypeCode, $position);
        $languageId = Language::where('code', 'en')->value('id');
        $templateId = ExerciseTemplate::where('code', 'fill_blank')->value('id');

        foreach ($items as $i => $item) {
            $exercise = Exercise::create([
                'exercise_template_id' => $templateId,
                'language_id' => $languageId,
                'skill_id' => $section->skill_id,
                'stem' => $item['stem'],
                'status' => 'published',
                'is_exam_eligible' => true,
            ]);

            ExerciseAnswer::create([
                'exercise_id' => $exercise->id,
                'blank_index' => 0,
                'value' => $item['answer'],
                'match_mode' => $item['match_mode'] ?? 'normalised',
                'is_primary' => true,
                'credit' => $item['credit'] ?? 1.0,
            ]);

            DB::table('exam_task_exercise')->insert([
                'exam_task_id' => $task->id,
                'exercise_id' => $exercise->id,
                'position' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $task->fresh('taskType');
    }

    protected function task(ExamSection $section, string $taskTypeCode, int $position = 0, ?string $instructions = null): ExamTask
    {
        $taskType = ExamTaskType::where('exam_section_id', $section->id)
            ->where('code', $taskTypeCode)
            ->firstOrFail();

        return ExamTask::create([
            'exam_task_type_id' => $taskType->id,
            'title' => $taskType->name.' practice task',
            'instructions' => $instructions,
            'position' => $position,
            'status' => 'published',
            'generation_method' => 'authored',
        ]);
    }
}
