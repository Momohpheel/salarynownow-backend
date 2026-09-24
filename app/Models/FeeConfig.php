<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class FeeConfig extends Model
{
    use HasFactory;

    const SCOPE_PLATFORM = 'platform';
    const SCOPE_MERCHANT = 'merchant';
    const SCOPE_PARTNER = 'partner';
    const SCOPE_EMPLOYER = 'employer';

    const EVENT_INFLOW_TOPUP = 'inflow_topup';
    const EVENT_OUTFLOW_DISBURSEMENT = 'outflow_disbursement';

    const CALC_FLAT = 'flat';
    const CALC_PERCENTAGE = 'percentage';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'set_by_user_id',
        'event',
        'calculation_type',
        'value',
        'cap_amount',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function setByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_id');
    }

    public function scopeForScope(Builder $query, string $scopeType, ?int $scopeId = null): Builder
    {
        return $query->where('scope_type', $scopeType)
            ->when($scopeId !== null, function ($q) use ($scopeId) {
                $q->where('scope_id', $scopeId);
            }, function ($q) {
                $q->whereNull('scope_id');
            });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function getScopeLabelAttribute(): string
    {
        if ($this->scope_type === self::SCOPE_PLATFORM) {
            return self::SCOPE_PLATFORM;
        }

        return sprintf('%s:%d', $this->scope_type, $this->scope_id);
    }

    public function getCalculationLabelAttribute(): string
    {
        $valueStr = $this->normalizeDecimalForLabel((string) $this->value);
        $base = sprintf('%s:%s', $this->calculation_type, $valueStr);

        if ($this->calculation_type === self::CALC_PERCENTAGE && $this->cap_amount !== null) {
            $capStr = $this->normalizeDecimalForLabel((string) $this->cap_amount);
            $base .= sprintf(':%s', $capStr);
        }

        return $base;
    }

    private function normalizeDecimalForLabel(string $num): string
    {
        if (str_contains($num, '.')) {
            $num = rtrim($num, '0');
            $num = rtrim($num, '.');
        }
        return $num === '' ? '0' : $num;
    }

    public function labelForScope(): string
    {
        return $this->getScopeLabelAttribute();
    }

    public function labelForCalculation(): string
    {
        return $this->getCalculationLabelAttribute();
    }
}
