<?php

use App\Http\Controllers\Modules\Employee\RegistrationController as EmployeeRegistrationController;
use App\Http\Controllers\Modules\Employee\LoginController as EmployeeLoginController;
use App\Http\Controllers\Modules\Employee\DashboardController as EmployeeDashboardController;
use App\Http\Controllers\Modules\Employee\ForgotPasswordController as EmployeeForgotPasswordController;
use App\Http\Controllers\Modules\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Modules\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Modules\Partner\RegistrationController as PartnerRegistrationController;
use App\Http\Controllers\Modules\Partner\LoginController as PartnerLoginController;
use App\Http\Controllers\Modules\Partner\ForgotPasswordController as PartnerForgotPasswordController;
use App\Http\Controllers\Modules\Staff\LoginController as StaffLoginController;
use App\Http\Controllers\Modules\Staff\ForgotPasswordController as StaffForgotPasswordController;
use App\Http\Controllers\Modules\Employee\StaffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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

    Route::prefix('employee')->group(function () {
        Route::get('/dashboard', [EmployeeDashboardController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::get('/staff', [StaffController::class, 'index']);
    });
});
