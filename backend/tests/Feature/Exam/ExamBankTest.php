<?php

namespace Tests\Feature\Exam;

use App\Models\ExamTask;
use App\Models\ProductionPrompt;
use Database\Seeders\ExamSeeder;
use Database\Seeders\ProductionPromptSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The exam engine was complete and empty.
 *
 * Four exam types, sixteen sections and seventy task types were seeded,
 * `exam_tasks` held nothing, and starting an attempt threw `noContent`. What
 * these guard is not that the bank is full — it is small on purpose — but that
 * nothing in it is in the wrong place. An informal message offered as IELTS
 * Academic Task 1 is worse than an empty paper, because the learner takes the
 * result seriously.
 */
class ExamBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ExamSeeder::class);
        $this->seed(ProductionPromptSeeder::class);
        $this->artisan('content:build-exams')->assertSuccessful();
    }

    public function test_the_papers_that_can_be_built_are_built(): void
    {
        $this->assertGreaterThan(0, ExamTask::count());

        $sections = DB::table('exam_tasks')
            ->join('exam_task_types', 'exam_task_types.id', '=', 'exam_tasks.exam_task_type_id')
            ->join('exam_sections', 'exam_sections.id', '=', 'exam_task_types.exam_section_id')
            ->distinct()->pluck('exam_sections.code');

        // Writing and speaking, and only those: reading and listening need a
        // passage written to be read, and the corpus is exercise books.
        $this->assertEqualsCanonicalizing(['writing', 'speaking'], $sections->all());
    }

    /**
     * The one that matters. A report is a report and a message is a message,
     * and IELTS Academic Task 1 asks for the first.
     */
    public function test_no_prompt_is_asked_under_a_task_type_it_does_not_fit(): void
    {
        $rows = DB::table('exam_tasks')
            ->join('exam_task_types', 'exam_task_types.id', '=', 'exam_tasks.exam_task_type_id')
            ->join('production_prompts', 'production_prompts.id', '=', 'exam_tasks.production_prompt_id')
            ->select('exam_task_types.code as task_type', 'production_prompts.task_type as kind')
            ->get();

        $allowed = [
            'task_1_report' => ['report'],
            'part_2_choice' => ['message', 'email', 'narrate'],
        ];

        foreach ($rows as $row) {
            if (! isset($allowed[$row->task_type])) {
                continue;
            }
            $this->assertContains(
                $row->kind,
                $allowed[$row->task_type],
                "a {$row->kind} prompt was placed under {$row->task_type}",
            );
        }
    }

    public function test_a_spoken_prompt_is_never_set_as_a_writing_task(): void
    {
        $wrong = DB::table('exam_tasks')
            ->join('exam_task_types', 'exam_task_types.id', '=', 'exam_tasks.exam_task_type_id')
            ->join('exam_sections', 'exam_sections.id', '=', 'exam_task_types.exam_section_id')
            ->join('production_prompts', 'production_prompts.id', '=', 'exam_tasks.production_prompt_id')
            ->whereRaw("(exam_sections.code = 'writing' AND production_prompts.modality <> 'written')
                     OR (exam_sections.code = 'speaking' AND production_prompts.modality <> 'spoken')")
            ->count();

        $this->assertSame(0, $wrong);
    }

    public function test_every_task_carries_a_time_limit_a_person_could_work_in(): void
    {
        $bad = ExamTask::where(function ($q) {
            $q->whereNull('time_limit_seconds')->orWhere('time_limit_seconds', '<', 60);
        })->count();

        $this->assertSame(0, $bad);
    }

    public function test_rebuilding_corrects_rather_than_duplicates(): void
    {
        $before = ExamTask::count();
        $this->artisan('content:build-exams')->assertSuccessful();

        $this->assertSame($before, ExamTask::count());
    }

    /**
     * An A2 prompt asking someone to describe their room has no home in a B2+
     * exam paper, and leaving it out is the right answer rather than a gap.
     */
    public function test_a_prompt_that_fits_no_paper_is_left_out(): void
    {
        $placed = ExamTask::distinct()->pluck('production_prompt_id');
        $unplaced = ProductionPrompt::whereNotIn('id', $placed)->pluck('task_type');

        $this->assertNotEmpty($placed);
        foreach ($unplaced as $kind) {
            $this->assertSame('describe', $kind);
        }
    }
}
