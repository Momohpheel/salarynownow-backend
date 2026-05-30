<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $fillable = [
        'user_id',
        'payroll_id',
        'period',
        'gross_salary',
        'pension',
        'other_deductions',
        'net_salary',
    ];

    protected $casts = [
        'gross_salary' => 'decimal:2',
        'pension' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }
}
