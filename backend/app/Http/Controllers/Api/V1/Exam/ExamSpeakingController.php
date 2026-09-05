<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Requests\Api\Exam\SubmitSpeakingResponseRequest;
use App\Models\ExamAttempt;
use App\Services\Exam\AiExaminerService;
use App\Services\Exam\ExamEstimate;
use Illuminate\Http\Request;

/**
 * The AI examiner: it asks the next question, takes the recording, and reports
 * the four speaking criteria as an estimate.
 */
class ExamSpeakingController extends ExamController
{
    public function __construct(private AiExaminerService $examiner) {}

    /** The next examiner question, with its preparation and speaking clocks. */
    public function next(Request $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(fn () => $this->ok($this->present($this->examiner->interview($attempt))));
    }

    public function respond(SubmitSpeakingResponseRequest $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(fn () => $this->ok($this->present(
            $this->examiner->respond($attempt, $request->integer('speech_attempt_id')),
        )));
    }

    /** Criterion-level estimates for the speaking section. */
    public function score(Request $request, ExamAttempt $attempt)
    {
        $attempt = $this->owned($request, $attempt);

        return $this->guard(fn () => $this->ok($this->examiner->score($attempt)));
    }

    /** @param  array<string, mixed>  $state */
    private function present(array $state): array
    {
        $sectionAttempt = $state['section_attempt'] ?? null;
        unset($state['section_attempt']);

        return $state + [
            'section_attempt_id' => $sectionAttempt?->id,
            'section' => $sectionAttempt?->section?->code,
            'estimate' => ExamEstimate::label(aiEstimated: true),
        ];
    }
}
