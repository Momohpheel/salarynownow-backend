<?php

namespace App\Http\Middleware;

use App\Support\SessionAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces server-side session limits for bearer-token (Sanctum) sessions.
 *
 * This runs BEFORE `auth:sanctum` so it can inspect the token row while its
 * stored `last_used_at` still reflects the *previous* request. Sanctum updates
 * `last_used_at` during authentication, so checking afterwards would always
 * look "just used" and never expire.
 *
 *  - Idle timeout:     token unused for SESSION_LIFETIME minutes (default 15)
 *  - Absolute timeout: token older than SESSION_ABSOLUTE_LIFETIME (default 480 = 8h)
 */
class EnsureSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_contains($token, '|')) {
            return $next($request);
        }

        $model = \Laravel\Sanctum\Sanctum::$personalAccessTokenModel;
        $accessToken = $model::findToken($token);

        if (! $accessToken) {
            return $next($request);
        }

        $idleMinutes = (int) config('session.idle_timeout', (int) config('session.lifetime', 15));
        $absoluteMinutes = (int) config('session.absolute_lifetime', 480);
        $now = now();

        $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

        // Idle timeout — no activity within the idle window.
        if ($lastActivity && $lastActivity->diffInMinutes($now) >= $idleMinutes) {
            $accessToken->delete();
            SessionAudit::invalidated($accessToken->tokenable_id, 'idle_timeout', [
                'token_id' => $accessToken->id,
                'last_used_at' => optional($accessToken->last_used_at)->toIso8601String(),
            ]);

            return $this->expiredResponse(
                'session_idle_timeout',
                'Your session expired due to inactivity. Please sign in again.'
            );
        }

        // Absolute timeout — session has been alive too long regardless of activity.
        if ($accessToken->created_at && $accessToken->created_at->diffInMinutes($now) >= $absoluteMinutes) {
            $accessToken->delete();
            SessionAudit::invalidated($accessToken->tokenable_id, 'absolute_timeout', [
                'token_id' => $accessToken->id,
                'created_at' => optional($accessToken->created_at)->toIso8601String(),
            ]);

            return $this->expiredResponse(
                'session_absolute_timeout',
                'Your session has reached its maximum lifetime. Please sign in again.'
            );
        }

        return $next($request);
    }

    /**
     * Minimal, non-revealing 401 payload for expired sessions.
     */
    protected function expiredResponse(string $code, string $message): Response
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'code' => $code,
            'data' => null,
        ], 401);
    }
}
