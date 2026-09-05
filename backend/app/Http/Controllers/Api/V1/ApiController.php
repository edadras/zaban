<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LearnerProfile;
use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    protected function ok(mixed $data = null, array $meta = [])
    {
        return ApiResponse::ok($data, $meta);
    }

    protected function created(mixed $data = null, array $meta = [])
    {
        return ApiResponse::created($data, $meta);
    }

    protected function fail(string $code, string $message, int $status = 400, array $details = [])
    {
        return ApiResponse::error($code, $message, $status, $details);
    }

    /** The learner profile is the anchor for every learning endpoint. */
    protected function learner(Request $request): LearnerProfile
    {
        return LearnerProfile::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['language_id' => \App\Models\Language::where('code', 'en')->value('id')],
        );
    }
}
