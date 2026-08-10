<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollUploadFlag extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_APPLIED = 'applied';

    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_reference',
        'payroll_id',
        'row_index',
        'row_data',
        'flags',
        'matched_staff',
        'gross_amount',
        'net_amount',
        'status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'row_data' => 'array',
        'flags' => 'array',
        'matched_staff' => 'array',
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function employer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForEmployer($query, int $employerId)
    {
        return $query->where('user_id', $employerId);
    }
}
