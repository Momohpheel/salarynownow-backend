<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Payroll extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'reference',
        'user_id',
        'description',
        'amount',
        'staff_count',
        'status',
        'processed_at',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'amount' => 'decimal:2',
    ];

    protected function periodLabel(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attrs) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                $start = $attrs['period_start'] ?? null;
                $end = $attrs['period_end'] ?? null;
                $description = $attrs['description'] ?? null;

                try {
                    if ($start && $end) {
                        $s = $start instanceof Carbon ? $start : Carbon::parse($start);
                        $e = $end instanceof Carbon ? $end : Carbon::parse($end);
                        if ($s->format('Y M') === $e->format('Y M')) {
                            return $s->format('M Y') . ' pay run';
                        }
                        return $s->format('M Y') . ' – ' . $e->format('M Y') . ' pay run';
                    }
                    if ($start) {
                        $s = $start instanceof Carbon ? $start : Carbon::parse($start);
                        return $s->format('M Y') . ' pay run';
                    }
                } catch (\Throwable) {
                }

                if ($description) {
                    return (string) $description;
                }
                $processed = $attrs['processed_at'] ?? null;
                if ($processed) {
                    try {
                        $p = $processed instanceof Carbon ? $processed : Carbon::parse($processed);
                        return $p->format('M Y') . ' pay run';
                    } catch (\Throwable) {
                    }
                }

                return 'Payroll #' . ($attrs['id'] ?? 'run');
            },
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
