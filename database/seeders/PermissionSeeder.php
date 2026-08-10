<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['name' => 'view_dashboard', 'group' => 'Dashboard'],

            // Wallet
            ['name' => 'view_wallet', 'group' => 'Wallet'],
            ['name' => 'manage_wallet', 'group' => 'Wallet'],

            // Staff Management
            ['name' => 'view_staff', 'group' => 'Staff Management'],
            ['name' => 'create_staff', 'group' => 'Staff Management'],
            ['name' => 'bulk_upload_staff', 'group' => 'Staff Management'],
            ['name' => 'edit_staff', 'group' => 'Staff Management'],
            ['name' => 'toggle_staff_status', 'group' => 'Staff Management'],
            ['name' => 'invite_staff', 'group' => 'Staff Management'],
            ['name' => 'resend_staff_invite', 'group' => 'Staff Management'],

            // Salary Advances
            ['name' => 'view_salary_advances', 'group' => 'Salary Advances'],
            ['name' => 'view_salary_advance_details', 'group' => 'Salary Advances'],

            // Payroll
            ['name' => 'view_payrolls', 'group' => 'Payroll'],
            ['name' => 'configure_payroll', 'group' => 'Payroll'],
            ['name' => 'review_payroll', 'group' => 'Payroll'],
            ['name' => 'check_payroll_balance', 'group' => 'Payroll'],
            ['name' => 'create_payroll', 'group' => 'Payroll'],
            ['name' => 'view_payroll_details', 'group' => 'Payroll'],
            ['name' => 'download_payroll_sample', 'group' => 'Payroll'],
            ['name' => 'upload_payroll', 'group' => 'Payroll'],
            ['name' => 'view_flagged_payroll', 'group' => 'Payroll'],
            ['name' => 'approve_flagged_payroll', 'group' => 'Payroll'],
            ['name' => 'reject_flagged_payroll', 'group' => 'Payroll'],
            ['name' => 'download_payslip', 'group' => 'Payroll'],

            // Deduction Types
            ['name' => 'view_deduction_types', 'group' => 'Deduction Types'],
            ['name' => 'create_deduction_type', 'group' => 'Deduction Types'],
            ['name' => 'edit_deduction_type', 'group' => 'Deduction Types'],
            ['name' => 'delete_deduction_type', 'group' => 'Deduction Types'],
            ['name' => 'toggle_deduction_type', 'group' => 'Deduction Types'],

            // Team Management
            ['name' => 'view_team', 'group' => 'Team Management'],
            ['name' => 'add_team_member', 'group' => 'Team Management'],
            ['name' => 'update_team_member_role', 'group' => 'Team Management'],
            ['name' => 'toggle_team_member_status', 'group' => 'Team Management'],

            // Reports
            ['name' => 'view_payroll_summary_report', 'group' => 'Reports'],
            ['name' => 'view_staff_payments_report', 'group' => 'Reports'],
            ['name' => 'view_advances_report', 'group' => 'Reports'],

            // Role Management
            ['name' => 'view_roles', 'group' => 'Role Management'],
            ['name' => 'create_role', 'group' => 'Role Management'],
            ['name' => 'edit_role', 'group' => 'Role Management'],
            ['name' => 'delete_role', 'group' => 'Role Management'],
            ['name' => 'assign_permissions', 'group' => 'Role Management'],

            // User Role Assignment
            ['name' => 'assign_user_role', 'group' => 'User Role Assignment'],
            ['name' => 'update_user_role', 'group' => 'User Role Assignment'],
            ['name' => 'view_user_role', 'group' => 'User Role Assignment'],

            // Settings
            ['name' => 'view_settings', 'group' => 'Settings'],
            ['name' => 'manage_settings', 'group' => 'Settings'],

            // Profile
            ['name' => 'complete_profile', 'group' => 'Profile'],
            ['name' => 'view_profile', 'group' => 'Profile'],
            ['name' => 'edit_profile', 'group' => 'Profile'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                ['group' => $permission['group']]
            );
        }
    }
}
