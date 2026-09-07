<?php

namespace App\Http\Middleware;

use App\Support\PanelAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's front door.
 *
 * Session authentication rather than a bearer token, because this half of the
 * product is a website: a coach opens it in a browser, and a browser that has
 * to keep a token in JavaScript is a browser one XSS away from handing the
 * whole school away.
 */
class EnsurePanelUser
{
    public function __construct(private readonly PanelAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('panel.login'));
        }

        if (! $this->access->isPanelUser($user)) {
            abort(403, 'این بخش برای مدیران آموزشگاه و مربیان است.');
        }

        return $next($request);
    }
}
