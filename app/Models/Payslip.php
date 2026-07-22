<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    const STATUS_PROCESSING = 'processing';
    const STATUS_DISBURSED = 'disbursed';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';



    protected $fillable = [
        'user_id',
        'payroll_id',
        'period',
        'gross_salary',
        'pension_employee',
        'pension_employer',
        'tax_deduction',
        'nhf',
        'other_deductions',
        'deduction_type',
        'net_salary',
        'status',
    ];

    protected $casts = [
        'gross_salary' => 'decimal:2',
        'pension_employee' => 'decimal:2',
        'pension_employer' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'nhf' => 'decimal:2',
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
