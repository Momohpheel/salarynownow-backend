<?php

use App\Http\Controllers\Modules\Common\BankController as CommonBankController;
use App\Http\Controllers\Modules\Employee\RegistrationController as EmployeeRegistrationController;
use App\Http\Controllers\Modules\Employee\LoginController as EmployeeLoginController;
use App\Http\Controllers\Modules\Employee\DashboardController as EmployeeDashboardController;
use App\Http\Controllers\Modules\Employee\WalletController as EmployeeWalletController;
use App\Http\Controllers\Modules\Employee\SalaryAdvanceController as EmployeeSalaryAdvanceController;
use App\Http\Controllers\Modules\Employee\PayrollController as EmployeePayrollController;
use App\Http\Controllers\Modules\Employee\TeamController as EmployeeTeamController;
use App\Http\Controllers\Modules\Employee\UserRoleController as EmployeeUserRoleController;
use App\Http\Controllers\Modules\Employee\RoleController as EmployeeRoleController;
use App\Http\Controllers\Modules\Employee\VerifyOtpController as EmployeeVerifyOtpController;
use App\Http\Controllers\Modules\Employee\ResendOtpController as EmployeeResendOtpController;
use App\Http\Controllers\Modules\Employee\ForgotPasswordController as EmployeeForgotPasswordController;
use App\Http\Controllers\Modules\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Modules\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Modules\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Modules\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Modules\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Modules\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Modules\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Modules\Admin\ChargeController as AdminChargeController;
use App\Http\Controllers\Modules\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Modules\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Modules\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Modules\Partner\RegistrationController as PartnerRegistrationController;
use App\Http\Controllers\Modules\Partner\LoginController as PartnerLoginController;
use App\Http\Controllers\Modules\Partner\ForgotPasswordController as PartnerForgotPasswordController;
use App\Http\Controllers\Modules\Staff\LoginController as StaffLoginController;
use App\Http\Controllers\Modules\Staff\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Modules\Staff\VerifyOtpController as StaffVerifyOtpController;
use App\Http\Controllers\Modules\Staff\ResendOtpController as StaffResendOtpController;
use App\Http\Controllers\Modules\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Modules\Staff\ProfileController as StaffProfileController;
use App\Http\Controllers\Modules\Staff\PayslipController as StaffPayslipController;
use App\Http\Controllers\Modules\Staff\SalaryAdvanceController as StaffSalaryAdvanceController;
use App\Http\Controllers\Modules\Employee\ReportController as EmployeeReportController;
use App\Http\Controllers\Modules\Employee\StaffController;
use App\Http\Controllers\Modules\Employer\EmployerProfileController;
use App\Http\Controllers\Modules\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\Modules\SuperAdmin\LoginController as SuperAdminLoginController;
use App\Http\Controllers\Modules\SuperAdmin\MerchantController;
use App\Http\Controllers\Webhooks\SarepayWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Common Utility Routes
Route::get('/banks', [CommonBankController::class, 'index']);

// Webhooks
Route::post('/webhooks/sarepay', [SarepayWebhookController::class, 'handle']);

// SuperAdmin Module
Route::post('/superadmin/login', [SuperAdminLoginController::class, 'login']);
Route::middleware(['auth:sanctum'])->prefix('superadmin')->group(function () {
    Route::get('/dashboard', [SuperAdminDashboardController::class, 'index']);
    Route::get('/merchants', [MerchantController::class, 'index']);
    Route::post('/merchants', [MerchantController::class, 'store']);
    Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
});

// Employee Module
Route::post('/employee/register', [EmployeeRegistrationController::class, 'register']);
Route::post('/employee/login', [EmployeeLoginController::class, 'login']);
Route::post('/employee/verify-otp', [EmployeeVerifyOtpController::class, 'verify']);
Route::post('/employee/resend-otp', [EmployeeResendOtpController::class, 'resend']);
Route::post('/employee/forgot-password', [EmployeeForgotPasswordController::class, 'sendResetLink']);
Route::post('/employee/reset-password', [EmployeeForgotPasswordController::class, 'reset']);

// Partner Module
Route::post('/partner/register', [PartnerRegistrationController::class, 'register']);
Route::post('/partner/login', [PartnerLoginController::class, 'login']);
Route::post('/partner/forgot-password', [PartnerForgotPasswordController::class, 'sendResetLink']);
Route::post('/partner/reset-password', [PartnerForgotPasswordController::class, 'reset']);

// Staff Module
Route::post('/staff/login', [StaffLoginController::class, 'login']);
Route::post('/staff/verify-otp', [StaffVerifyOtpController::class, 'verify']);
Route::post('/staff/resend-otp', [StaffResendOtpController::class, 'resend']);
Route::post('/staff/forgot-password', [StaffForgotPasswordController::class, 'sendResetLink']);
Route::post('/staff/reset-password', [StaffForgotPasswordController::class, 'reset']);

// Admin Module
Route::post('/admin/login', [AdminLoginController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [EmployeeLoginController::class, 'logout']); 

    // Admin Protected Routes
    Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/employees', [AdminEmployeeController::class, 'index']);
        Route::get('/kyb-reviews', [AdminEmployeeController::class, 'kybReviews']);
        Route::get('/employees/{employee}', [AdminEmployeeController::class, 'show']);
        Route::post('/employees/{employee}/approve', [AdminEmployeeController::class, 'approve']);
        Route::post('/employees/{employee}/reject', [AdminEmployeeController::class, 'reject']);
        Route::post('/employees/{employee}/create-default-role', [AdminEmployeeController::class, 'createDefaultRole']);
        Route::post('/employees/{employee}/regenerate-virtual-account', [AdminEmployeeController::class, 'regenerateVirtualAccount']);
        Route::get('/advances', [AdminOperationsController::class, 'advances']);
        Route::get('/audit-log', [AdminOperationsController::class, 'auditLog']);
        Route::get('/audit-log/export', [AdminOperationsController::class, 'exportAuditLog']);
        Route::get('/users', [AdminOperationsController::class, 'users']);
        Route::get('/wallets', [AdminOperationsController::class, 'wallets']);
        Route::get('/payrolls', [AdminOperationsController::class, 'payrolls']);
        Route::get('/staff', [AdminStaffController::class, 'index']);
        Route::get('/companies', [AdminCompanyController::class, 'index']);
        Route::get('/companies/{company}', [AdminCompanyController::class, 'show']);
        Route::post('/companies/{company}/deactivate', [AdminCompanyController::class, 'deactivate']);
        Route::post('/companies/{company}/wallet/credit', [AdminWalletController::class, 'credit']);
        Route::post('/companies/{company}/wallet/debit', [AdminWalletController::class, 'debit']);
        Route::get('/charges', [AdminChargeController::class, 'index']);
        Route::post('/charges', [AdminChargeController::class, 'store']);
        Route::post('/transactions/{transaction}/requery', [AdminTransactionController::class, 'requeryTransaction']);
        Route::apiResource('/team', AdminTeamController::class);
        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::post('/settings', [AdminSettingsController::class, 'store']);
    });

    // Staff Protected Routes
    Route::prefix('staff')->group(function () {
        Route::get('/dashboard', [StaffDashboardController::class, 'index']);
        Route::get('/profile', [StaffProfileController::class, 'show']);
        Route::post('/bank/verify', [StaffProfileController::class, 'verifyBank']);
        Route::post('/bank/update', [StaffProfileController::class, 'updateBank']);
        Route::get('/payslips', [StaffPayslipController::class, 'index']);
        Route::get('/payslips/{id}/download', [StaffPayslipController::class, 'download']);
        Route::get('/salary-advance/eligibility', [StaffSalaryAdvanceController::class, 'eligibility']);
        Route::post('/salary-advance', [StaffSalaryAdvanceController::class, 'store']);
    });

    Route::prefix('employee')->group(function () {
        Route::post('/complete-profile', [EmployeeRegistrationController::class, 'completeProfile']);
        Route::get('/profile', [EmployerProfileController::class, 'show']);
        Route::post('/profile', [EmployerProfileController::class, 'update']);
        Route::get('/dashboard', [EmployeeDashboardController::class, 'index']);
        Route::get('/wallet', [EmployeeWalletController::class, 'index']);
        
        // Staff Management
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::post('/staff/bulk-upload', [StaffController::class, 'bulkUpload']);
        Route::post('/staff/{staff}', [StaffController::class, 'update']);
        Route::post('/staff/{staff}/toggle-status', [StaffController::class, 'toggleStatus']);
        Route::post('/staff/{staff}/invite', [StaffController::class, 'invite']);

        // Salary Advances
        Route::get('/salary-advances', [EmployeeSalaryAdvanceController::class, 'index']);
        Route::get('/salary-advances/{salary_advance}', [EmployeeSalaryAdvanceController::class, 'show']);

        // Payroll History & Creation
        Route::get('/payrolls', [EmployeePayrollController::class, 'index']);
        Route::get('/payrolls/configure', [EmployeePayrollController::class, 'configure']);
        Route::get('/payrolls/review', [EmployeePayrollController::class, 'review']);
        Route::post('/payrolls/check-balance', [EmployeePayrollController::class, 'checkBalance']);
        Route::post('/payrolls', [EmployeePayrollController::class, 'store']);
        Route::get('/payrolls/{payroll}', [EmployeePayrollController::class, 'show']);
        Route::get('/payslips/{id}/download', [EmployeePayrollController::class, 'downloadPayslip']);

        // Team Management
        Route::get('/team', [EmployeeTeamController::class, 'index']);
        Route::post('/team', [EmployeeTeamController::class, 'store']);
        Route::post('/team/{member}/role', [EmployeeUserRoleController::class, 'assignRole']);
        Route::post('/team/{member}/toggle-status', [EmployeeTeamController::class, 'toggleStatus']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/payroll-summary', [EmployeeReportController::class, 'payrollSummary']);
            Route::get('/staff-payments', [EmployeeReportController::class, 'staffPayments']);
            Route::get('/advances', [EmployeeReportController::class, 'advanceReport']);
        });

        // Role Management
        Route::apiResource('roles', EmployeeRoleController::class);
        Route::get('/permissions', [EmployeeRoleController::class, 'permissions']);
        Route::post('/roles/{role}/permissions', [EmployeeRoleController::class, 'assignPermissions']);
      //  Route::post('/roles/{role}/permissions', [EmployeeRoleController::class, 'updatePermissions']);

        // User Role Assignment
        Route::post('/users/{user}/role', [EmployeeUserRoleController::class, 'assignRole']);
        Route::post('/users/{user}/role', [EmployeeUserRoleController::class, 'updateRole']);
        Route::get('/users/{user}/role', [EmployeeUserRoleController::class, 'getUserRole']);
    });
});
