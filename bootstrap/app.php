<?php

use App\Http\Middleware\EnsureAdminUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: [
        __DIR__.'/../app/Listeners',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => EnsureAdminUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): Response {
            $headers = $exception->getHeaders();
            $retryAfter = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Too many attempts. Please slow down.',
                    'error' => 'too_many_attempts',
                    'retry_after_seconds' => $retryAfter,
                ], 429, $headers);
            }

            return response('Too many attempts. Please slow down.', 429, $headers);
        });
    })->create();
