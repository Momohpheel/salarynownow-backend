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
    'plan_tier',
    'password',
    'company_name',
    'rc_number',
    'industry',
    'company_address',
    'number_of_staff',
    'alternative_emails',
    'cac_certificate_path',
    'director_id_path',
    'utility_bill_path',
    'first_name',
    'last_name',
    'job_title',
    'department',
    'start_date',
    'dob',
    'state_of_origin',
    'bank_name',
    'account_name',
    'pfa_name',
    'rsa_pin',
    'is_active',
    'pension_employee_rate',
    'pension_employer_rate',
    'pension_employee',
    'pension_employer',
    'invitation_status',
    'status',
    'tax_deduction',
    'nhf',
    'net_salary',
    'salary',
    'employer_id',
    'role_id',
    'account_number',
    'bvn',
    'otp',
    'otp_expires_at',
    'otp_attempts',
    'parent_id',
    'owner_group_user_id',
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

    const MAX_LOGIN_ATTEMPTS = 3;
    const LOCKOUT_DURATION_MINUTES = 15;



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
            'lockout_until' => 'datetime',
            'alternative_emails' => 'array',
        ];
    }

    public function isLockedOut(): bool
    {
        return $this->lockout_until !== null && $this->lockout_until->isFuture();
    }

    public function getLockoutRemainingMinutes(): int
    {
        if (!$this->isLockedOut()) {
            return 0;
        }
        return max(1, (int) $this->lockout_until->diffInMinutes(now()));
    }

    public function incrementLoginAttempts(): void
    {
        $this->login_attempts = ($this->login_attempts ?? 0) + 1;
        if ($this->login_attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->lockout_until = now()->addMinutes(self::LOCKOUT_DURATION_MINUTES);
        }
        $this->save();
    }

    public function resetLoginAttempts(): void
    {
        $this->login_attempts = 0;
        $this->lockout_until = null;
        $this->save();
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

    public function staff()
    {
        return $this->hasMany(User::class, 'parent_id');
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

    public function hasRole($roleName): bool
    {
        if (!$this->relationLoaded('role')) {
            $this->loadMissing('role');
        }
        if (!$this->role) {
            return false;
        }
        return mb_strtolower(trim((string) $this->role->name)) === mb_strtolower(trim((string) $roleName));
    }

    public function hasPermissionTo($permission): bool
    {
        $employerId = $this->getEmployerId();
        if ((int) $this->id === (int) $employerId) {
            return true;
        }
        if (!$this->relationLoaded('role')) {
            $this->loadMissing('role.permissions');
        }
        if (!$this->role || !$this->role->relationLoaded('permissions')) {
            try {
                $this->loadMissing('role.permissions');
            } catch (\Throwable $e) {
                return false;
            }
        }
        if (!$this->role) {
            return false;
        }
        $permName = is_string($permission) ? $permission : ($permission->name ?? null);
        if ($permName === null) {
            return false;
        }
        return $this->role->permissions->pluck('name')->contains($permName);
    }

    public function ownerGroupOwner()
    {
        if (is_null($this->owner_group_user_id) || (int) $this->owner_group_user_id === (int) $this->id) {
            return $this;
        }
        return $this->belongsTo(User::class, 'owner_group_user_id');
    }

    public function ownedBusinesses()
    {
        $ownerId = $this->resolveSharedWalletOwner()->id;

        $subs = static::where('owner_group_user_id', $ownerId)
            ->where('type', self::TYPE_EMPLOYEE)
            ->where('id', '!=', $ownerId)
            ->get();

        $owner = static::find($ownerId);

        if ($owner && $owner->type === self::TYPE_EMPLOYEE) {
            return collect([$owner])->merge($subs)->unique('id')->values();
        }

        return $subs;
    }

    public function resolveSharedWalletOwner(): User
    {
        if (is_null($this->owner_group_user_id) || (int) $this->owner_group_user_id === (int) $this->id) {
            return $this;
        }

        if ($this->relationLoaded('ownerGroupOwner')) {
            $related = $this->getRelation('ownerGroupOwner');
            if ($related instanceof User) {
                return $related;
            }
        }

        return $this->ownerGroupOwner ?? $this;
    }

    public function scopeInOwnerGroup($query, int $ownerUserId)
    {
        return $query->where('owner_group_user_id', $ownerUserId)
            ->where('type', self::TYPE_EMPLOYEE);
    }
}
