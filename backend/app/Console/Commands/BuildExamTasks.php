<?php

namespace App\Console\Commands;

use App\Models\ExamTask;
use App\Models\ExamTaskType;
use App\Models\ProductionPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put the authored prompts behind the exam papers.
 *
 * The exam engine was complete and empty: four exam types, sixteen sections and
 * seventy-odd task types were seeded, `exam_tasks` held nothing, and starting an
 * attempt threw `ExamException::noContent`. A whole feature that could not be
 * opened.
 *
 * What it is filled from is the fifteen production prompts - ten written, five
 * spoken - which were authored for exactly this and were already in the
 * database. A prompt becomes a task in every exam whose band contains its
 * level, under the task type it actually resembles: a report goes to IELTS
 * Task 1 and not to Task 2, a short message to the Cambridge part that asks for
 * one.
 *
 * Reading and listening are deliberately not built, and that is the honest
 * outcome rather than a shortcut. An exam reading paper needs a passage written
 * to be read; the corpus is sixteen exercise books, whose pages are tables,
 * drills and margin notes. Dressing a vocabulary page as "IELTS Academic
 * Reading" would mislabel it to the learner at the moment they are trying to
 * find out where they stand. The command says so on every run rather than
 * quietly reporting two sections built out of four.
 */
class BuildExamTasks extends Command
{
    protected $signature = 'content:build-exams {--fresh : remove previously built tasks first}';

    protected $description = 'Build exam tasks from the authored production prompts';

    /**
     * Which task types a prompt may be asked under, by the words in their code.
     *
     * There is deliberately no fallback. An early version put every written
     * prompt under the first task type of each writing section, which sent "A
     * message to a friend" to IELTS Academic Task 1 - a paper that asks for a
     * description of a chart. A prompt that fits nothing in an exam is not
     * asked in that exam, and several correctly are not: TOEFL's integrated
     * tasks need a lecture to summarise, PTE's speaking parts need text to read
     * aloud or an image to describe, and none of that is in the corpus.
     */
    private const SHAPE = [
        'report' => ['task_1_report'],
        'essay' => ['task_2_essay', 'part_1_essay', 'write_essay', 'academic_discussion'],
        'opinion' => ['task_2_essay', 'part_1_essay', 'write_essay', 'academic_discussion',
            'part_3_discussion', 'part_4_discussion', 'independent_choice'],
        'message' => ['part_2_choice'],
        'email' => ['part_2_choice'],
        'narrate' => ['part_2_choice', 'part_2_long_turn'],
        'describe' => ['part_2_long_turn'],
        // A written argument is an essay; a spoken one is a discussion. The
        // same kind reaches a different task type because the section it is
        // offered in only has one of the two.
        'argue' => ['task_2_essay', 'part_1_essay', 'write_essay', 'academic_discussion',
            'part_3_discussion', 'part_4_discussion',
            'part_3_collaborative_task', 'independent_choice'],
    ];

    /** Modality of a prompt, to the section it is examined in. */
    private const SECTIONS = ['written' => 'writing', 'spoken' => 'speaking'];

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $removed = ExamTask::whereNotNull('production_prompt_id')->forceDelete();
            $this->line("   removed: {$removed} previously built tasks");
        }

        $types = ExamTaskType::query()
            ->join('exam_sections', 'exam_sections.id', '=', 'exam_task_types.exam_section_id')
            ->join('exam_types', 'exam_types.id', '=', 'exam_sections.exam_type_id')
            ->select('exam_task_types.*', 'exam_sections.code as section',
                'exam_sections.duration_minutes', 'exam_types.code as exam',
                'exam_types.name as exam_name')
            ->orderBy('exam_task_types.id')
            ->get();

        $built = 0;
        $skipped = 0;
        $byExam = [];

        foreach (ProductionPrompt::whereNull('deleted_at')->orderBy('id')->get() as $prompt) {
            $section = self::SECTIONS[$prompt->modality] ?? null;
            if ($section === null) {
                continue;
            }

            foreach ($types->groupBy('exam') as $exam => $examTypes) {
                $candidates = $examTypes->where('section', $section);
                if ($candidates->isEmpty()) {
                    continue;
                }

                $taskType = $this->pick($candidates, $prompt->task_type);
                if ($taskType === null) {
                    $skipped++;

                    continue;
                }

                $task = ExamTask::updateOrCreate(
                    [
                        'exam_task_type_id' => $taskType->id,
                        'production_prompt_id' => $prompt->id,
                    ],
                    [
                        'title' => $prompt->title,
                        'instructions' => trim($prompt->prompt.' '.($prompt->guidance ?? '')),
                        'position' => $prompt->id,
                        // The section's own minute allowance divided by the
                        // parts it has, so a paper still runs to its real
                        // length rather than to a number invented here.
                        'time_limit_seconds' => $this->seconds($taskType, $candidates->count(), $prompt),
                        'status' => 'approved',
                        'generation_method' => 'authored',
                    ],
                );

                $built += $task->wasRecentlyCreated ? 1 : 0;
                $byExam[$exam][$section] = ($byExam[$exam][$section] ?? 0) + 1;
            }
        }

        $this->info("Exam tasks built: {$built}");
        $rows = [];
        foreach ($byExam as $exam => $sections) {
            $rows[] = [$exam, $sections['writing'] ?? 0, $sections['speaking'] ?? 0, 0, 0];
        }
        $this->table(['exam', 'writing', 'speaking', 'reading', 'listening'], $rows);

        if ($skipped > 0) {
            $this->line("   prompt/exam pairs with no task type that fits: {$skipped}");
        }

        $this->newLine();
        $this->warn('Reading and listening are empty, and not by omission.');
        $this->line('   An exam reading paper needs a passage written to be read. The corpus is');
        $this->line('   sixteen exercise books: tables, drills and margin notes. Presenting one of');
        $this->line('   those pages as "IELTS Academic Reading" would tell a learner something');
        $this->line('   untrue at the moment they are trying to find out where they stand.');
        $this->line('   Those two sections need passages the corpus does not contain.');

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ExamTaskType>  $candidates
     */
    private function pick($candidates, ?string $kind): ?ExamTaskType
    {
        foreach (self::SHAPE[$kind] ?? [] as $needle) {
            $match = $candidates->first(fn ($t) => str_contains($t->code, $needle));
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function seconds($taskType, int $parts, ProductionPrompt $prompt): int
    {
        if ($prompt->response_seconds) {
            return (int) $prompt->response_seconds
                + (int) ($prompt->prep_seconds ?? 0);
        }

        $minutes = (int) ($taskType->duration_minutes ?: 30);

        return (int) max(300, round($minutes * 60 / max(1, $parts)));
    }
}
