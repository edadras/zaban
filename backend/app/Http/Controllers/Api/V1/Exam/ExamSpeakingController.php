<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Requests\Api\Exam\SubmitSpeakingResponseRequest;
use App\Models\ExamAttempt;
use App\Services\Exam\AiExaminerService;
use App\Services\Exam\ExamEstimate;
use App\Support\SpeakerLink;
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
            'examiner' => $this->examinerFor($state),
        ];
    }

    /**
     * The examiner, sitting opposite, for as long as the interview is running.
     *
     * What a candidate has to rehearse is not the questions - those are on the
     * screen already - it is being looked at while they answer. A face that
     * waits through a pause is the part of a speaking test that a text box
     * cannot practise, and it is the same examiner they met in the two exam
     * scenes in conversation practice, so the room is at least familiar.
     *
     * Silent unless the question has a recording behind it. A figure mouthing
     * along to nothing would be a claim about speech that is not being made;
     * the question stays on screen and under their chin either way.
     *
     * @param  array<string, mixed>  $state
     * @return array{url: string, expires_in: int}|null
     */
    private function examinerFor(array $state): ?array
    {
        if (($state['complete'] ?? false) || ! isset($state['question'])) {
            return null;
        }

        return SpeakerLink::examiner(null, (string) $state['question']);
    }
}
