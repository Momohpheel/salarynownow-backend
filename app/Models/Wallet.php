<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'currency',
        'account_number',
        'account_name',
        'account_reference',
        'bank_name',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logs()
    {
        return $this->hasMany(WalletLog::class);
    }

    /**
     * Funds topped up into this wallet since the start of today (credited amount).
     * Used by the T+1 settlement rule — money funded today is not spendable until tomorrow.
     */
    public function sameDayFundingTotal(): float
    {
        return (float) $this->logs()
            ->where('type', 'credit')
            ->where('created_at', '>=', now()->startOfDay())
            ->whereRaw('LOWER(description) LIKE ?', ['%topup%'])
            ->selectRaw('COALESCE(SUM(COALESCE(balance_after - balance_before, amount)), 0) as total')
            ->value('total');
    }

    /**
     * Balance that can actually be spent right now.
     * When $applySettlementDelay is true, funds topped up today are excluded until tomorrow.
     */
    public function spendableBalance(bool $applySettlementDelay = false): float
    {
        $balance = (float) ($this->balance ?? 0);

        if (!$applySettlementDelay) {
            return $balance;
        }

        return max(0, $balance - $this->sameDayFundingTotal());
    }
}
