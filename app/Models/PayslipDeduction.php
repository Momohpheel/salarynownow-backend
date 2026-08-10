<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayslipDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payslip_id',
        'deduction_type_id',
        'deduction_name',
        'deduction_key',
        'amount',
        'is_percentage',
        'percentage_applied',
        'base_amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'percentage_applied' => 'decimal:2',
        'is_percentage' => 'boolean',
    ];

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function deductionType()
    {
        return $this->belongsTo(DeductionType::class);
    }
}
