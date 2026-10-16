<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * Central audit trail for authentication session lifecycle events.
 *
 * Emits structured log entries for session creation, revocation and
 * invalidation so abnormal persistence / replay can be detected from the
 * protected server-side logs. Never surfaces details to the client.
 */
class SessionAudit
{
    /**
     * Record a newly issued session token.
     */
    public static function created(Authenticatable $user, string $tokenName): void
    {
        Log::info('session_created', self::context($user) + [
            'token_name' => $tokenName,
        ]);
    }

    /**
     * Revoke every active token for the user (logout-all / password change /
     * account deactivation) and record the event.
     */
    public static function revokeAll(Authenticatable $user, string $reason): void
    {
        $revoked = 0;
        if (method_exists($user, 'tokens')) {
            $revoked = $user->tokens()->count();
            $user->tokens()->delete();
        }

        Log::warning('session_revoked', self::context($user) + [
            'reason' => $reason,
            'revoked_count' => $revoked,
        ]);
    }

    /**
     * Record a session that expired or was otherwise invalidated.
     */
    public static function invalidated(?int $userId, string $reason, array $context = []): void
    {
        Log::warning('session_invalidated', array_merge([
            'user_id' => $userId,
            'reason' => $reason,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ], $context));
    }

    /**
     * Shared request context for session audit entries.
     */
    protected static function context(Authenticatable $user): array
    {
        return [
            'user_id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
            'email' => $user instanceof User ? $user->email : null,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ];
    }
}
