<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 
    'email', 
    'password', 
    'type', 
    'parent_id',
    'phone_number',
    'company_name',
    'rc_number',
    'industry',
    'company_address',
    'number_of_staff',
    'cac_certificate_path',
    'director_id_path',
    'utility_bill_path',
    'is_approved'
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const TYPE_EMPLOYEE = 'employee';
    const TYPE_STAFF = 'staff';
    const TYPE_PARTNER = 'partner';
    const TYPE_ADMIN = 'admin';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_approved' => 'boolean',
        ];
    }

    public function scopeEmployee($query)
    {
        return $query->where('type', self::TYPE_EMPLOYEE);
    }

    public function scopeStaff($query)
    {
        return $query->where('type', self::TYPE_STAFF);
    }

    public function scopePartner($query)
    {
        return $query->where('type', self::TYPE_PARTNER);
    }

    public function scopeAdmin($query)
    {
        return $query->where('type', self::TYPE_ADMIN);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function salaryAdvances()
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
}
