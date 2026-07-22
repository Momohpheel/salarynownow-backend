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
    'link_name', 
    'contact_person',
    'email', 
    'phone_number',
    'state',
    'revenue_share',
    'plan_tier',
    'internal_notes',
    'status',
    'password', 
    'type', 
    'parent_id',
    'phone_number',
    'company_name',
    'rc_number',
    'industry',
    'company_address',
    'number_of_staff',
    'bvn',
    'cac_certificate_path',
    'director_id_path',
    'utility_bill_path',
    'is_approved',
    'first_name',
    'last_name',
    'job_title',
    'department',
    'start_date',
    'dob',
    'state_of_origin',
    'bank_name',
    'account_number',
    'employer_id',
    'account_name',
    'salary',
    'pfa_name',
    'rsa_pin',
    'pension_employee_rate',
    'pension_employer_rate',
    'invitation_status',
    'is_active',
    'tax_deduction',
    'nhf',
    'net_salary',
    'role_id',
    'otp',
    'otp_expires_at',
    'otp_attempts'

])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const TYPE_SUPERADMIN = 'superadmin';
    const TYPE_ADMIN = 'admin'; // Also referred to as Merchants
    const TYPE_EMPLOYEE = 'employee';
    const TYPE_STAFF = 'staff';
    const TYPE_PARTNER = 'partner';



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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the full URL for the CAC certificate.
     */
    public function getCacCertificateUrlAttribute(): ?string
    {
        return $this->cac_certificate_path ? asset('storage/' . $this->cac_certificate_path) : null;
    }

    /**
     * Get the full URL for the director ID.
     */
    public function getDirectorIdUrlAttribute(): ?string
    {
        return $this->director_id_path ? asset('storage/' . $this->director_id_path) : null;
    }

    /**
     * Get the full URL for the utility bill.
     */
    public function getUtilityBillUrlAttribute(): ?string
    {
        return $this->utility_bill_path ? asset('storage/' . $this->utility_bill_path) : null;
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
        return $this->belongsTo(User::class, 'employer_id');
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
        return $this->hasMany(SalaryAdvance::class, 'user_id');
    }

    public function staffAdvances()
    {
        return $this->hasMany(SalaryAdvance::class, 'staff_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Get the ID of the employer (the account owner).
     * If the user is an owner, it returns their own ID.
     * If the user is a team member, it returns their parent's ID.
     */
    public function getEmployerId(): int
    {
        return $this->type === self::TYPE_EMPLOYEE && $this->employer_id 
            ? $this->employer_id 
            : $this->id;
    }

    /**
     * Get the actual employer instance.
     */
    public function employer()
    {
        return $this->type === self::TYPE_EMPLOYEE && $this->employer_id 
            ? $this->parent() 
            : $this;
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasPermissionTo($permission)
    {
        return $this->role->permissions->pluck('name')->contains($permission);
    }
}
