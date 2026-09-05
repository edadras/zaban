<?php

namespace App\Http\Controllers\Api\V1\Speech;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Speech\PronunciationProfileResource;
use App\Services\Speech\PronunciationProfileService;
use Illuminate\Http\Request;

/**
 * The learner's rolling pronunciation profile and the drills it implies.
 *
 * This survives deletion of every recording it was built from, which is the
 * point: the teaching value is in the statistics, not in the audio.
 */
class PronunciationProfileController extends ApiController
{
    public function __construct(private PronunciationProfileService $profile) {}

    public function show(Request $request)
    {
        return $this->ok(new PronunciationProfileResource(
            $this->profile->profileFor((int) $request->user()->id),
        ));
    }

    /** Just the drill targets, for the learning engine's session builder. */
    public function drills(Request $request)
    {
        $limit = min(10, max(1, (int) $request->integer('limit', 5)));

        return $this->ok($this->profile->drillTargets((int) $request->user()->id, $limit));
    }
}
