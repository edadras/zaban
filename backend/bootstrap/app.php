<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Defining a rate limiter does not apply it - the group has to name it.
        // Without the throttle entry here, every authenticated endpoint is
        // unthrottled however carefully the limiters are configured.
        $middleware->api(
            prepend: [App\Http\Middleware\ForceJsonResponse::class],
            append: ['throttle:api'],
        );

        $middleware->alias([
            'admin' => App\Http\Middleware\EnsureAdmin::class,
            'panel' => App\Http\Middleware\EnsurePanelUser::class,
        ]);

        // The only session-authenticated part of the installation is the web
        // panel, so an unauthenticated browser belongs at its login page. API
        // requests never reach here: they answer 401 in JSON above.
        $middleware->redirectGuestsTo(fn () => route('panel.login'));

        /*
         * The scene player's own calls.
         *
         * It is a page rather than an API client, so its requests go through
         * the web group - but it authenticates by the signature on every URL,
         * not by a session, and it therefore has no session cookie to derive a
         * CSRF token from. The signature is what a forged cross-site request
         * would have to produce, and cannot.
         */
        $middleware->validateCsrfTokens(except: [
            'scene/*/answer',
            'scene/*/finish',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Framework-level failures use the same envelope as everything else, so
        // the client has exactly one response shape to parse rather than two.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof Illuminate\Validation\ValidationException => App\Support\ApiResponse::error(
                    'validation_failed', 'The given data was invalid.', 422, $e->errors(),
                ),
                $e instanceof Illuminate\Auth\AuthenticationException => App\Support\ApiResponse::error(
                    'unauthenticated', 'Authentication is required.', 401,
                ),
                $e instanceof Illuminate\Auth\Access\AuthorizationException => App\Support\ApiResponse::error(
                    'forbidden', 'You are not allowed to do that.', 403,
                ),
                // Must be tested before the generic HTTP exception below, since
                // it is a subclass of it.
                $e instanceof Illuminate\Http\Exceptions\ThrottleRequestsException => App\Support\ApiResponse::error(
                    'rate_limited', 'Too many requests. Please slow down.', 429,
                ),
                $e instanceof Illuminate\Database\Eloquent\ModelNotFoundException,
                $e instanceof Symfony\Component\HttpKernel\Exception\NotFoundHttpException => App\Support\ApiResponse::error(
                    'not_found', 'Resource not found.', 404,
                ),
                $e instanceof Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => App\Support\ApiResponse::error(
                    'method_not_allowed', 'That method is not allowed on this endpoint.', 405,
                ),
                default => null,
            };
        });
    })->create();
