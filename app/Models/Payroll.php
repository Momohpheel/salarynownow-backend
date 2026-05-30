<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    protected $fillable = [
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }
}
