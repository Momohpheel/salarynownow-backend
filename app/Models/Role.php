<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    use HasFactory;
    //LogsActivity;

    protected $fillable = ['employer_id', 'name', 'description', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function employer()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public static function ownerPermissionNames(): array
    {
        return [
            'view_dashboard',
            'view_wallet',
            'manage_wallet',
            'view_staff',
            'create_staff',
            'bulk_upload_staff',
            'edit_staff',
            'toggle_staff_status',
            'invite_staff',
            'resend_staff_invite',
            'view_salary_advances',
            'view_salary_advance_details',
            'view_payrolls',
            'configure_payroll',
            'review_payroll',
            'check_payroll_balance',
            'create_payroll',
            'view_payroll_details',
            'download_payroll_sample',
            'upload_payroll',
            'view_flagged_payroll',
            'approve_flagged_payroll',
            'reject_flagged_payroll',
            'download_payslip',
            'view_deduction_types',
            'create_deduction_type',
            'edit_deduction_type',
            'delete_deduction_type',
            'toggle_deduction_type',
            'view_team',
            'add_team_member',
            'update_team_member_role',
            'toggle_team_member_status',
            'view_payroll_summary_report',
            'view_staff_payments_report',
            'view_advances_report',
            'view_roles',
            'create_role',
            'edit_role',
            'delete_role',
            'assign_permissions',
            'assign_user_role',
            'update_user_role',
            'view_user_role',
            'view_settings',
            'manage_settings',
            'complete_profile',
            'view_profile',
            'edit_profile',
        ];
    }

    public static function financeManagerPermissionNames(): array
    {
        return [
            'view_dashboard',
            'view_wallet',
            'manage_wallet',
            'view_staff',
            'edit_staff',
            'view_salary_advances',
            'view_salary_advance_details',
            'view_payrolls',
            'configure_payroll',
            'review_payroll',
            'check_payroll_balance',
            'create_payroll',
            'view_payroll_details',
            'download_payroll_sample',
            'upload_payroll',
            'view_flagged_payroll',
            'approve_flagged_payroll',
            'reject_flagged_payroll',
            'download_payslip',
            'view_deduction_types',
            'create_deduction_type',
            'edit_deduction_type',
            'toggle_deduction_type',
            'view_payroll_summary_report',
            'view_staff_payments_report',
            'view_advances_report',
            'view_profile',
            'edit_profile',
        ];
    }

    public static function hrPermissionNames(): array
    {
        return [
            'view_dashboard',
            'view_wallet',
            'view_staff',
            'create_staff',
            'bulk_upload_staff',
            'edit_staff',
            'toggle_staff_status',
            'invite_staff',
            'resend_staff_invite',
            'view_salary_advances',
            'view_salary_advance_details',
            'view_payrolls',
            'view_payroll_details',
            'view_flagged_payroll',
            'download_payslip',
            'view_deduction_types',
            'create_deduction_type',
            'edit_deduction_type',
            'toggle_deduction_type',
            'view_profile',
            'edit_profile',
        ];
    }

    public static function viewerPermissionNames(): array
    {
        return [
            'view_dashboard',
            'view_wallet',
            'view_staff',
            'view_salary_advances',
            'view_salary_advance_details',
            'view_payrolls',
            'view_payroll_details',
            'view_flagged_payroll',
            'view_deduction_types',
            'view_payroll_summary_report',
            'view_staff_payments_report',
            'view_advances_report',
            'view_profile',
        ];
    }

    public static function standardDefinitions(): array
    {
        return [
            'admin' => [
                'description' => 'Full access owner role for the employer account.',
                'perms' => static::ownerPermissionNames(),
            ],
            'finance_manager' => [
                'description' => 'Full finance operations: wallet, payroll, flagged approvals, reports, deduction types.',
                'perms' => static::financeManagerPermissionNames(),
            ],
            'hr' => [
                'description' => 'Staff lifecycle & deductions: onboarding, invites, resends, view payroll, deduction catalog.',
                'perms' => static::hrPermissionNames(),
            ],
            'viewer' => [
                'description' => 'Read-only visibility across all modules.',
                'perms' => static::viewerPermissionNames(),
            ],
        ];
    }

    public static function ensureStandardRolesForEmployer(int $employerId): array
    {
        $created = [];
        DB::transaction(function () use ($employerId, &$created) {
            $definitions = static::standardDefinitions();
            foreach ($definitions as $name => $def) {
                $role = static::firstOrCreate(
                    [
                        'employer_id' => $employerId,
                        'name' => $name,
                    ],
                    [
                        'description' => $def['description'],
                        'status' => 'active',
                    ]
                );
                $permIds = Permission::whereIn('name', $def['perms'])->pluck('id')->all();
                if (count($permIds) > 0) {
                    $role->permissions()->sync($permIds);
                }
                $created[$name] = $role->loadMissing('permissions');
            }
        });
        return $created;
    }

    // public function getActivitylogOptions(): LogOptions
    // {
    //     return LogOptions::defaults()
    //         ->logOnly(['employer_id', 'name', 'description', 'status'])
    //         ->useLogName('Role')
    //         ->logOnlyDirty()
    //         ->dontSubmitEmptyLogs();
    // }
}
