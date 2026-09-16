<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MarketplaceEnquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'offer_id',
        'offer_name',
        'submitter_type',
        'submitter_id',
        'name',
        'phone_number',
        'email',
        'company_name',
        'message',
        'status',
        'metadata',
        'replied_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'replied_at' => 'datetime',
    ];

    public function submitter(): MorphTo
    {
        return $this->morphTo();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }
}
