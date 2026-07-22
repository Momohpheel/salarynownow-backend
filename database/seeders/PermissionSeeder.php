<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['name' => 'view_dashboard', 'group' => 'Dashboard'],

            // Wallet
            ['name' => 'view_wallet', 'group' => 'Wallet'],

            // Staff Management
            ['name' => 'view_staff', 'group' => 'Staff Management'],
            ['name' => 'create_staff', 'group' => 'Staff Management'],
            ['name' => 'bulk_upload_staff', 'group' => 'Staff Management'],
            ['name' => 'edit_staff', 'group' => 'Staff Management'],
            ['name' => 'toggle_staff_status', 'group' => 'Staff Management'],
            ['name' => 'invite_staff', 'group' => 'Staff Management'],

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

            // Profile
            ['name' => 'complete_profile', 'group' => 'Profile'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }
}
