<?php

namespace App\Http\Resources\Exam;

use App\Models\Exercise;
use App\Models\ExerciseOption;
use App\Services\Exam\ExamEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One served task. Wraps the array ExamService::nextTask returns.
 *
 * Answer keys are stripped here: options go out without is_correct and without
 * their distractor rationale, and accepted answers are not sent at all.
 */
class ExamTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $task = $data['task'];
        $section = $data['section'];

        return [
            'exam_attempt_id' => $data['attempt']->id,
            'section' => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'position' => (int) $section->position,
            ],
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'instructions' => $task->instructions,
                'position' => (int) $task->position,
                'passage_id' => $task->passage_id,
                'production_prompt_id' => $task->production_prompt_id,
                'type' => [
                    'code' => $data['task_type']?->code,
                    'name' => $data['task_type']?->name,
                    'description' => $data['task_type']?->description,
                ],
            ],
            'kind' => $data['kind'],
            'exercises' => collect($data['exercises'])->map(fn (Exercise $e) => [
                'id' => $e->id,
                'stem' => $e->stem,
                'instructions' => $e->instructions,
                'payload' => $e->payload,
                'passage_id' => $e->passage_id,
                'audio_media_asset_id' => $e->audio_media_asset_id,
                'options' => $e->options->sortBy('position')->map(fn (ExerciseOption $o) => [
                    'id' => $o->id,
                    'position' => (int) $o->position,
                    'text' => $o->text,
                    'media_asset_id' => $o->media_asset_id,
                ])->values(),
            ])->values(),
            'timing' => $data['timing'],
            'progress' => $data['position'],
            'estimate_notice' => ExamEstimate::AI_DISCLAIMER,
        ];
    }
}
