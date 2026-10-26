<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates external partner requests via an API key supplied as
 * `Authorization: Bearer sn_<prefix>_<secret>`.
 *
 * On success the resolved business user becomes the request user and the
 * request is pinned to that business via `business_id`, so the existing
 * employer controllers can be reused without trusting client input.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Missing API key.');
        }

        $apiKey = ApiKey::findByPlainText($token);

        if (! $apiKey) {
            return $this->unauthorized('Invalid API key.');
        }

        if (! $apiKey->isActive()) {
            return $this->unauthorized('This API key is no longer active.');
        }

        if ($requiredScope !== null && ! $apiKey->hasScope($requiredScope)) {
            return response()->json([
                'status' => false,
                'message' => 'This API key is not permitted to perform this action.',
                'data' => null,
            ], 403);
        }

        $user = $apiKey->user;

        if (! $user || $user->type !== User::TYPE_EMPLOYEE) {
            return $this->unauthorized('The business linked to this API key is unavailable.');
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        // Pin the request to the key's business unless the caller explicitly
        // targets another business within the same owner group (validated later
        // by ResolvesBusinessContext).
        if (! $request->filled('business_id')) {
            $request->merge(['business_id' => $user->id]);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    protected function unauthorized(string $message): Response
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => null,
        ], 401);
    }
}
