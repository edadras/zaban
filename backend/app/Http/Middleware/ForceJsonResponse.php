<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** API clients always get JSON, even for framework-level failures. */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
