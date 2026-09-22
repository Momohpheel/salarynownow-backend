<?php

use App\Http\Controllers\Modules\Common\BankController as CommonBankController;
use App\Http\Controllers\Modules\Employee\RegistrationController as EmployeeRegistrationController;
use App\Http\Controllers\Modules\Employee\LoginController as EmployeeLoginController;
use App\Http\Controllers\Modules\Employee\DashboardController as EmployeeDashboardController;
use App\Http\Controllers\Modules\Employee\DeductionTypeController as EmployeeDeductionTypeController;
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
use App\Http\Controllers\Modules\Partner\DashboardController as PartnerDashboardController;
use App\Http\Controllers\Modules\Partner\NotificationController as PartnerNotificationController;
use App\Http\Controllers\Modules\Partner\MarketplaceEnquiryController as PartnerMarketplaceEnquiryController;
use App\Http\Controllers\Modules\Staff\LoginController as StaffLoginController;
use App\Http\Controllers\Modules\Staff\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Modules\Staff\VerifyOtpController as StaffVerifyOtpController;
use App\Http\Controllers\Modules\Staff\ResendOtpController as StaffResendOtpController;
use App\Http\Controllers\Modules\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Modules\Staff\ProfileController as StaffProfileController;
use App\Http\Controllers\Modules\Staff\PayslipController as StaffPayslipController;
use App\Http\Controllers\Modules\Staff\SalaryAdvanceController as StaffSalaryAdvanceController;
use App\Http\Controllers\Modules\Staff\MarketplaceEnquiryController as StaffMarketplaceEnquiryController;
use App\Http\Controllers\Modules\Employee\MarketplaceEnquiryController as EmployeeMarketplaceEnquiryController;
use App\Http\Controllers\Modules\Employee\ReportController as EmployeeReportController;
use App\Http\Controllers\Modules\Employee\NotificationController as EmployeeNotificationController;
use App\Http\Controllers\Modules\Admin\NotificationController as AdminNotificationController;
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
Route::post('/superadmin/login', [SuperAdminLoginController::class, 'login'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', 'ensure.user.type:super_admin'])->prefix('superadmin')->group(function () {
    // Auth / profile
    Route::post('/logout', [SuperAdminLoginController::class, 'logout']);
    Route::get('/me', fn (\Illuminate\Http\Request $r) => (new \App\Http\Controllers\Controller())->sendResponse($r->user()->makeHidden(['password', 'remember_token']), 'OK'));

    // Dashboard + overview
    Route::get('/dashboard', [SuperAdminDashboardController::class, 'index']);

    // Merchants (TYPE_ADMIN = merchant / platform operator)
    Route::get('/merchants', [MerchantController::class, 'index']);
    Route::post('/merchants', [MerchantController::class, 'store']);
    Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
    Route::post('/merchants/{merchant}/approve', [MerchantController::class, 'approve']);
    Route::post('/merchants/{merchant}/reject', [MerchantController::class, 'reject']);
    Route::post('/merchants/{merchant}/suspend', [MerchantController::class, 'suspend']);
    Route::post('/merchants/{merchant}/activate', [MerchantController::class, 'activate']);
    Route::post('/merchants/{merchant}/update', [MerchantController::class, 'update']);
});

// Employee Module
Route::post('/employee/register', [EmployeeRegistrationController::class, 'register'])->middleware('throttle:5,1');
Route::post('/employee/login', [EmployeeLoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/employee/verify-otp', [EmployeeVerifyOtpController::class, 'verify'])->middleware('throttle:8,1');
Route::post('/employee/resend-otp', [EmployeeResendOtpController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/employee/forgot-password', [EmployeeForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,10');
Route::post('/employee/reset-password', [EmployeeForgotPasswordController::class, 'reset'])->middleware('throttle:5,10');

// Partner Module
Route::post('/partner/register', [PartnerRegistrationController::class, 'register'])->middleware('throttle:5,1');
Route::post('/partner/login', [PartnerLoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/partner/forgot-password', [PartnerForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,10');
Route::post('/partner/reset-password', [PartnerForgotPasswordController::class, 'reset'])->middleware('throttle:5,10');

// Staff Module
Route::post('/staff/login', [StaffLoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/staff/verify-otp', [StaffVerifyOtpController::class, 'verify'])->middleware('throttle:8,1');
Route::post('/staff/resend-otp', [StaffResendOtpController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/staff/forgot-password', [StaffForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,10');
Route::post('/staff/reset-password', [StaffForgotPasswordController::class, 'reset'])->middleware('throttle:5,10');

// Admin Module
Route::post('/admin/login', [AdminLoginController::class, 'login'])->middleware('throttle:10,1');

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [EmployeeLoginController::class, 'logout']); 

    // Admin Protected Routes
    Route::middleware(['ensure.user.type:admin,super_admin'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/employees', [AdminEmployeeController::class, 'index']);
        Route::get('/kyb-reviews', [AdminEmployeeController::class, 'kybReviews']);
        Route::get('/employees/{employee}', [AdminEmployeeController::class, 'show']);
        Route::post('/employees/{employee}/approve', [AdminEmployeeController::class, 'approve']);
        Route::post('/employees/{employee}/reject', [AdminEmployeeController::class, 'reject']);
        Route::post('/employees/{employee}/hold', [AdminEmployeeController::class, 'hold']);
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
        Route::get('/team/roles', [AdminTeamController::class, 'roles']);
        Route::post('/team/invite', [AdminTeamController::class, 'invite']);
        Route::post('/team/deactivate', [AdminTeamController::class, 'deactivate']);
        Route::post('/team/update-role', [AdminTeamController::class, 'updateRole']);
        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::post('/settings', [AdminSettingsController::class, 'store']);
        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [AdminNotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [AdminNotificationController::class, 'markAllAsRead']);
    });

    // Partner Protected Routes
    Route::prefix('partner')->middleware(['ensure.user.type:partner'])->group(function () {
        Route::post('/logout', [PartnerLoginController::class, 'logout']);
        Route::get('/me', [PartnerDashboardController::class, 'me']);
        Route::get('/dashboard', [PartnerDashboardController::class, 'dashboard']);

        // Notifications
        Route::get('/notifications', [PartnerNotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [PartnerNotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [PartnerNotificationController::class, 'markAllAsRead']);

        // Marketplace enquiries
        Route::get('/enquiries', [PartnerMarketplaceEnquiryController::class, 'index']);
        Route::get('/enquiries/{id}', [PartnerMarketplaceEnquiryController::class, 'show']);
        Route::post('/enquiries/{id}/reply', [PartnerMarketplaceEnquiryController::class, 'reply']);
    });

    // Staff Protected Routes
    Route::prefix('staff')->middleware(['ensure.user.type:staff'])->group(function () {
        Route::get('/dashboard', [StaffDashboardController::class, 'index']);
        Route::get('/profile', [StaffProfileController::class, 'show']);
        Route::post('/bank/verify', [StaffProfileController::class, 'verifyBank']);
        Route::post('/bank/update', [StaffProfileController::class, 'updateBank']);
        Route::get('/payslips', [StaffPayslipController::class, 'index']);
        Route::get('/payslips/{id}/download', [StaffPayslipController::class, 'download']);
        Route::get('/salary-advance/eligibility', [StaffSalaryAdvanceController::class, 'eligibility']);
        Route::post('/salary-advance', [StaffSalaryAdvanceController::class, 'store']);
        Route::post('/marketplace-enquiry', [StaffMarketplaceEnquiryController::class, 'store']);
        Route::get('/marketplace-enquiries', [StaffMarketplaceEnquiryController::class, 'index']);
    });

    Route::prefix('employee')->group(function () {
        Route::middleware('ensure.employer.role')->group(function () {
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
        Route::post('/salary-advances/{salary_advance}/approve', [EmployeeSalaryAdvanceController::class, 'approve']);
        Route::post('/salary-advances/{salary_advance}/reject', [EmployeeSalaryAdvanceController::class, 'reject']);

        // Payroll History & Creation
        Route::get('/payrolls', [EmployeePayrollController::class, 'index']);
        Route::get('/payrolls/configure', [EmployeePayrollController::class, 'configure']);
        Route::get('/payrolls/review', [EmployeePayrollController::class, 'review']);
        Route::post('/payrolls/check-balance', [EmployeePayrollController::class, 'checkBalance']);
        Route::post('/payrolls', [EmployeePayrollController::class, 'store']);
        Route::get('/payrolls/{payroll}', [EmployeePayrollController::class, 'show']);
        Route::get('/payslips/{id}/download', [EmployeePayrollController::class, 'downloadPayslip']);
        Route::get('/payrolls-sample/download', [EmployeePayrollController::class, 'downloadSample']);
        Route::post('/payrolls/upload-preview', [EmployeePayrollController::class, 'uploadPreview']);
        Route::post('/payrolls/upload-commit', [EmployeePayrollController::class, 'uploadCommit']);
        Route::get('/payrolls-flagged', [EmployeePayrollController::class, 'flaggedRows']);
        Route::post('/payrolls-flagged/{flag}/approve', [EmployeePayrollController::class, 'approveFlagged']);
        Route::post('/payrolls-flagged/{flag}/reject', [EmployeePayrollController::class, 'rejectFlagged']);

        // Deduction Types
        Route::get('/deduction-types', [EmployeeDeductionTypeController::class, 'index']);
        Route::post('/deduction-types', [EmployeeDeductionTypeController::class, 'store']);
        Route::match(['put', 'patch'], '/deduction-types/{deductionType}', [EmployeeDeductionTypeController::class, 'update']);
        Route::delete('/deduction-types/{deductionType}', [EmployeeDeductionTypeController::class, 'destroy']);
        Route::post('/deduction-types/{deductionType}/toggle', [EmployeeDeductionTypeController::class, 'toggle']);

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

        // Notifications
        Route::get('/notifications', [EmployeeNotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [EmployeeNotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [EmployeeNotificationController::class, 'markAllAsRead']);

        // Marketplace Enquiries
        Route::post('/marketplace-enquiry', [EmployeeMarketplaceEnquiryController::class, 'store']);
        Route::get('/marketplace-enquiries', [EmployeeMarketplaceEnquiryController::class, 'index']);
        }); // close ensure.employer.role middleware group
    });
});
