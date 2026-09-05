<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Requests\Api\Exam\StartExamAttemptRequest;
use App\Http\Requests\Api\Exam\SubmitExamResponseRequest;
use App\Http\Resources\Exam\ExamAttemptResource;
use App\Http\Resources\Exam\ExamResultResource;
use App\Http\Resources\Exam\ExamTaskResource;
use App\Models\CefrLevel;
use App\Models\ExamAttempt;
use App\Models\ExamScore;
use App\Models\ExamTask;
use App\Models\ExamType;
use App\Services\Exam\ExamAnalyticsService;
use App\Services\Exam\ExamEstimate;
use App\Services\Exam\ExamService;
use App\Services\Exam\ScoringService;
use Illuminate\Http\Request;

/** Starting, running and closing one exam sitting. */
class ExamAttemptController extends ExamController
{
    public function __construct(
        private ExamService $exams,
        private ScoringService $scoring,
        private ExamAnalyticsService $analytics,
    ) {}

    public function store(StartExamAttemptRequest $request)
    {
        $examType = ExamType::findOrFail($request->integer('exam_type_id'));

        return $this->guard(function () use ($request, $examType) {
            $attempt = $this->exams->start(
                $request->user()->id,
                $examType,
                $request->input('mode', ExamService::MODE_PRACTICE),
                $request->filled('exam_section_id') ? $request->integer('exam_section_id') : null,
            );

            return $this->created(new ExamAttemptResource($attempt->load(['examType', 'sectionAttempts.section.skill'])));
        });
    }

    public function show(Request $request, ExamAttempt $attempt)
    {
        return $this->ok(new ExamAttemptResource($this->owned($request, $attempt)));
    }

    /** The next task under the section clock, or a completion marker. */
    public function nextTask(Request $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(function () use ($attempt) {
            $next = $this->exams->nextTask($attempt);

            if (! $next) {
                return $this->ok([
                    'complete' => true,
                    'message' => 'Every section of this attempt has been served. Finish the attempt to see your estimate.',
                ]);
            }

            return $this->ok(new ExamTaskResource($next));
        });
    }

    public function submit(SubmitExamResponseRequest $request, ExamAttempt $attempt, ExamTask $task)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(function () use ($request, $attempt, $task) {
            $record = $this->exams->submitResponse(
                $attempt,
                $task->load('taskType'),
                $request->payload(),
                $request->secondsUsed(),
            );

            return $this->ok([
                'exam_task_id' => $task->id,
                'kind' => $record['kind'],
                // Objective marks come back immediately; productive work is scored
                // when the attempt is finished, so nothing is promised here.
                'raw_score' => $record['raw_score'] ?? null,
                'items' => $record['items'] ?? null,
                'scored' => array_key_exists('raw_score', $record),
                'estimate_notice' => ExamEstimate::AI_DISCLAIMER,
            ]);
        });
    }

    public function finish(Request $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(function () use ($request, $attempt) {
            $finished = $this->exams->finish($attempt, $this->scoring);

            return $this->results($request, $finished);
        });
    }

    public function results(Request $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);
        $level = $attempt->estimated_cefr_level_id ? CefrLevel::find($attempt->estimated_cefr_level_id) : null;

        return $this->ok(new ExamResultResource([
            'attempt' => $attempt,
            'cefr' => $level?->code,
            'cefr_name' => $level?->name,
            'scale' => [
                'min' => (float) $attempt->examType->score_min,
                'max' => (float) $attempt->examType->score_max,
                'step' => (float) $attempt->examType->score_step,
                'type' => $attempt->examType->score_type,
            ],
            'skills' => $this->scoring->skillBreakdown($attempt),
            'criteria' => ExamScore::where('exam_attempt_id', $attempt->id)
                ->orderBy('exam_section_attempt_id')
                ->orderBy('criterion')
                ->get()
                // Submission rows share the table with scores; only real criteria
                // belong on the result screen.
                ->reject(fn (ExamScore $s) => ExamService::isResponseCriterion($s->criterion))
                ->values(),
            'question_types' => $this->analytics->questionTypePerformance($attempt),
            'mistakes' => $this->analytics->commonMistakes($attempt),
        ]));
    }
}
