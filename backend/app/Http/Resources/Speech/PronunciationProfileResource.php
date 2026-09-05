<?php

namespace App\Http\Resources\Speech;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The learner's pronunciation profile as built by PronunciationProfileService.
 *
 * The service already produces the exact shape the client needs; this resource
 * exists so the endpoint returns the same envelope as every other one and so the
 * payload's contract is documented in one place.
 *
 * @property array $resource
 */
class PronunciationProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;

        return [
            // Every phoneme the learner has been measured on, worst first.
            'phonemes' => $data['phonemes'] ?? [],
            // The subset the learning engine should build drills from.
            'drill_targets' => $data['drill_targets'] ?? [],
            'thresholds' => $data['thresholds'] ?? [],
        ];
    }
}
