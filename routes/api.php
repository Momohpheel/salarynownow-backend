<?php

use App\Http\Controllers\Modules\Common\BankController as CommonBankController;
use App\Http\Controllers\Modules\Employee\RegistrationController as EmployeeRegistrationController;
use App\Http\Controllers\Modules\Employee\LoginController as EmployeeLoginController;
use App\Http\Controllers\Modules\Employee\DashboardController as EmployeeDashboardController;
use App\Http\Controllers\Modules\Employee\WalletController as EmployeeWalletController;
use App\Http\Controllers\Modules\Employee\SalaryAdvanceController as EmployeeSalaryAdvanceController;
use App\Http\Controllers\Modules\Employee\PayrollController as EmployeePayrollController;
use App\Http\Controllers\Modules\Employee\TeamController as EmployeeTeamController;
use App\Http\Controllers\Modules\Employee\ForgotPasswordController as EmployeeForgotPasswordController;
use App\Http\Controllers\Modules\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Modules\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Modules\Partner\RegistrationController as PartnerRegistrationController;
use App\Http\Controllers\Modules\Partner\LoginController as PartnerLoginController;
use App\Http\Controllers\Modules\Partner\ForgotPasswordController as PartnerForgotPasswordController;
use App\Http\Controllers\Modules\Staff\LoginController as StaffLoginController;
use App\Http\Controllers\Modules\Staff\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Modules\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Modules\Staff\ProfileController as StaffProfileController;
use App\Http\Controllers\Modules\Staff\PayslipController as StaffPayslipController;
use App\Http\Controllers\Modules\Staff\SalaryAdvanceController as StaffSalaryAdvanceController;
use App\Http\Controllers\Modules\Employee\ReportController as EmployeeReportController;
use App\Http\Controllers\Modules\Employee\StaffController;
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

// Employee Module
Route::post('/employee/register', [EmployeeRegistrationController::class, 'register']);
Route::post('/employee/login', [EmployeeLoginController::class, 'login']);
Route::post('/employee/forgot-password', [EmployeeForgotPasswordController::class, 'sendResetLink']);
Route::post('/employee/reset-password', [EmployeeForgotPasswordController::class, 'reset']);

// Partner Module
Route::post('/partner/register', [PartnerRegistrationController::class, 'register']);
Route::post('/partner/login', [PartnerLoginController::class, 'login']);
Route::post('/partner/forgot-password', [PartnerForgotPasswordController::class, 'sendResetLink']);
Route::post('/partner/reset-password', [PartnerForgotPasswordController::class, 'reset']);

// Staff Module
Route::post('/staff/login', [StaffLoginController::class, 'login']);
Route::post('/staff/forgot-password', [StaffForgotPasswordController::class, 'sendResetLink']);
Route::post('/staff/reset-password', [StaffForgotPasswordController::class, 'reset']);

// Admin Module
Route::post('/admin/login', [AdminLoginController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [EmployeeLoginController::class, 'logout']); 

    // Admin Protected Routes
    Route::prefix('admin')->group(function () {
        Route::get('/employees', [AdminEmployeeController::class, 'index']);
        Route::get('/employees/{employee}', [AdminEmployeeController::class, 'show']);
        Route::post('/employees/{employee}/approve', [AdminEmployeeController::class, 'approve']);
    });

    // Staff Protected Routes
    Route::prefix('staff')->group(function () {
        Route::get('/dashboard', [StaffDashboardController::class, 'index']);
        Route::get('/profile', [StaffProfileController::class, 'show']);
        Route::post('/bank/verify', [StaffProfileController::class, 'verifyBank']);
        Route::post('/bank/update', [StaffProfileController::class, 'updateBank']);
        Route::get('/payslips', [StaffPayslipController::class, 'index']);
        Route::get('/salary-advance/eligibility', [StaffSalaryAdvanceController::class, 'eligibility']);
        Route::post('/salary-advance', [StaffSalaryAdvanceController::class, 'store']);
    });

    Route::prefix('employee')->group(function () {
        Route::post('/complete-profile', [EmployeeRegistrationController::class, 'completeProfile']);
        Route::get('/dashboard', [EmployeeDashboardController::class, 'index']);
        Route::get('/wallet', [EmployeeWalletController::class, 'index']);
        
        // Staff Management
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::post('/staff/bulk-upload', [StaffController::class, 'bulkUpload']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
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

        // Team Management
        Route::get('/team', [EmployeeTeamController::class, 'index']);
        Route::post('/team', [EmployeeTeamController::class, 'store']);
        Route::put('/team/{member}/role', [EmployeeTeamController::class, 'updateRole']);
        Route::post('/team/{member}/toggle-status', [EmployeeTeamController::class, 'toggleStatus']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/payroll-summary', [EmployeeReportController::class, 'payrollSummary']);
            Route::get('/staff-payments', [EmployeeReportController::class, 'staffPayments']);
            Route::get('/advances', [EmployeeReportController::class, 'advanceReport']);
        });
    });
});
