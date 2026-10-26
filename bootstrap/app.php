<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'ensure.employer.role' => \App\Http\Middleware\EnsureEmployerHasRole::class,
            'ensure.user.type' => \App\Http\Middleware\EnsureUserType::class,
            'external.api-key' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);

        // Enforce idle + absolute token lifetime before authentication resolves
        // the bearer token (see EnsureSessionLifetime for why ordering matters).
        $middleware->api(prepend: [
            \App\Http\Middleware\EnsureSessionLifetime::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Write detailed diagnostic traces to the protected server-side log,
        // never to the client response.
        $exceptions->report(function (\Throwable $e) {
            if (! app()->bound('request') || ! request()->is('api/*')) {
                return;
            }

            Log::error('api_exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'method' => request()->method(),
                'path' => request()->path(),
                'user_id' => optional(request()->user())->getAuthIdentifier(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

        // Return generic messages to API clients for unexpected failures.
        // "Safe" exceptions that are safe to surface verbatim are left alone.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (
                $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || $e instanceof ThrottleRequestsException
                || $e instanceof HttpExceptionInterface
            ) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong. Please try again later.',
                'data' => null,
            ], 500);
        });
    })->create();
