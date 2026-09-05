<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'editor', 'reviewer'], true)) {
            return ApiResponse::error('forbidden', 'Administrator access required.', 403);
        }

        return $next($request);
    }
}
