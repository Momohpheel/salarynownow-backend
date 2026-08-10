<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeductionType extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'default_amount',
        'is_percentage',
        'percentage_value',
        'is_active',
        'is_system',
        'system_key',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'percentage_value' => 'decimal:2',
        'is_percentage' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function employer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payslipDeductions()
    {
        return $this->hasMany(PayslipDeduction::class);
    }

    public function scopeForEmployer($query, int $employerId)
    {
        return $query->where(function ($q) use ($employerId) {
            $q->where('user_id', $employerId)
                ->orWhere(function ($sys) {
                    $sys->where('is_system', true)->where('user_id', 0);
                });
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
