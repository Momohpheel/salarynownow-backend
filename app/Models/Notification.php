<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'type',
        'title',
        'body',
        'icon',
        'deep_link',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, User|int $user)
    {
        $id = $user instanceof User ? $user->id : $user;
        return $query->where('user_id', $id);
    }

    public static function notify(User|int $user, array $payload): self
    {
        $id = $user instanceof User ? $user->id : $user;
        return self::create([
            'user_id' => $id,
            'category' => $payload['category'] ?? 'system',
            'type' => $payload['type'] ?? null,
            'title' => $payload['title'],
            'body' => $payload['body'] ?? null,
            'icon' => $payload['icon'] ?? null,
            'deep_link' => $payload['deep_link'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]);
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }
}
