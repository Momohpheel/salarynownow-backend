<?php

namespace App\Http\Controllers\Modules\External;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Modules\Employee\PayrollController as EmployeePayrollController;
use App\Http\Controllers\Modules\Employee\StaffController as EmployeeStaffController;
use App\Http\Controllers\Modules\Employee\WalletController as EmployeeWalletController;
use Illuminate\Http\Request;

/**
 * Public partner API.
 *
 * These endpoints are thin wrappers over the employer controllers so external
 * integrations behave exactly like the in-app experience (same validation,
 * wallet debits, idempotent payroll creation). The API-key middleware has
 * already authenticated the business and pinned `business_id` on the request.
 */
class PayrollController extends Controller
{
    /**
     * Verify credentials and inspect the business the key is bound to.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return $this->sendResponse([
            'business_id' => $user->id,
            'business_name' => $user->company_name ?? $user->name,
            'email' => $user->email,
        ], 'API key is valid');
    }

    /**
     * List payroll runs for the business (most recent first).
     */
    public function listPayrolls(Request $request)
    {
        return app(EmployeePayrollController::class)->index($request);
    }

    /**
     * Run a new payroll for the business.
     */
    public function storePayroll(Request $request)
    {
        return app(EmployeePayrollController::class)->store($request, $request->user());
    }

    /**
     * Show a single payroll run with per-staff payment results.
     */
    public function showPayroll(Request $request, $id)
    {
        return app(EmployeePayrollController::class)->show($request, $id);
    }

    /**
     * List the business staff members.
     */
    public function listStaff(Request $request)
    {
        return app(EmployeeStaffController::class)->index($request);
    }

    /**
     * Wallet balance for the business.
     */
    public function wallet(Request $request)
    {
        return app(EmployeeWalletController::class)->index($request);
    }
}
