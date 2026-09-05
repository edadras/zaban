<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\SpacedRepetitionService;
use Illuminate\Http\Request;

class ReviewController extends ApiController
{
    public function __construct(
        private SpacedRepetitionService $srs,
        private AdaptiveLearningService $engine,
    ) {}

    /** Reviews that are actually due, most-forgotten first. */
    public function due(Request $request)
    {
        $userId = $request->user()->id;
        $limit = min(100, max(1, $request->integer('limit', 30)));

        $due = $this->srs->due($userId, $limit)->map(function ($lc) use ($userId) {
            $exercise = $this->engine->pickExerciseForConcept($userId, $lc->concept_id);

            return [
                'concept_id' => $lc->concept_id,
                'label' => $lc->concept?->label,
                'mastery_score' => (float) $lc->mastery_score,
                'interval_days' => (int) $lc->interval_days,
                'due_since' => $lc->next_review_at?->toIso8601String(),
                'forgetting_probability' => $this->srs->forgettingProbability($lc),
                'exercise_id' => $exercise?->id,
            ];
        })->values();

        return $this->ok($due, ['total_due' => $this->srs->dueCount($userId)]);
    }

    public function counts(Request $request)
    {
        return $this->ok(['due' => $this->srs->dueCount($request->user()->id)]);
    }
}
