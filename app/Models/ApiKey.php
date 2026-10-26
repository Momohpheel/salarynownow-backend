<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Credential used by external partners to call the public API.
 *
 * Only the SHA-256 hash of the full token is stored. The plaintext token is
 * shown exactly once, at creation time, and can never be recovered.
 */
class ApiKey extends Model
{
    public const SCOPE_PAYROLL_RUN = 'payroll:run';
    public const SCOPE_PAYROLL_READ = 'payroll:read';
    public const SCOPE_STAFF_READ = 'staff:read';
    public const SCOPE_WALLET_READ = 'wallet:read';

    public const DEFAULT_SCOPES = [
        self::SCOPE_PAYROLL_RUN,
        self::SCOPE_PAYROLL_READ,
        self::SCOPE_STAFF_READ,
        self::SCOPE_WALLET_READ,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a new key for a business.
     *
     * @return array{0: self, 1: string} the persisted key model and its plaintext token
     */
    public static function generate(User $user, ?string $name = null, array $scopes = self::DEFAULT_SCOPES, ?Carbon $expiresAt = null): array
    {
        $prefix = Str::lower(Str::random(12));
        $secret = Str::random(40);
        $plain = 'sn_' . $prefix . '_' . $secret;

        $key = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    /**
     * Resolve the key matching a plaintext bearer token, or null when unknown.
     */
    public static function findByPlainText(string $plain): ?self
    {
        $parts = explode('_', trim($plain));
        if (count($parts) !== 3 || $parts[0] !== 'sn') {
            return null;
        }

        $key = static::where('key_prefix', $parts[1])->first();
        if (!$key) {
            return null;
        }

        return hash_equals($key->key_hash, hash('sha256', $plain)) ? $key : null;
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array($scope, $scopes, true);
    }
}
