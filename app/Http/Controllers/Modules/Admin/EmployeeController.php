<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function index()
    {
        $employees = User::employee()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'employees' => $employees,
        ]);
    }

    public function show(User $employee)
    {
        if ($employee->type !== User::TYPE_EMPLOYEE) {
            return response()->json(['message' => 'User is not an employee.'], 404);
        }

        return response()->json([
            'employee' => $employee->load('wallet'),
        ]);
    }

    public function approve(User $employee)
    {
        if ($employee->type !== User::TYPE_EMPLOYEE) {
            return response()->json(['message' => 'User is not an employee.'], 404);
        }

        if ($employee->is_approved) {
            return response()->json(['message' => 'Employee is already approved.'], 400);
        }

        // Call Sarepay to create virtual account
        $sarepayResponse = $this->sarepayService->createAccount($employee);

        // if ($sarepayResponse['status'] !== 'success') {
        //     return response()->json([
        //         'message' => 'Failed to create virtual account with Sarepay.',
        //         'error' => $sarepayResponse['message'] ?? 'Unknown error'
        //     ], 500);
        // }

        $accountData = $sarepayResponse;

        DB::transaction(function () use ($employee, $accountData) {
            $employee->update(['is_approved' => true]);

            // Create wallet for the employee with virtual account details
            Wallet::firstOrCreate([
                'user_id' => $employee->id,
            ], [
                'balance' => 0.00,
                'currency' => 'NGN',
                'account_number' => $accountData['account_number'],
                'account_name' => $accountData['account_name'],
                'account_reference' => $accountData['account_reference'],
                'bank_name' => $accountData['bank_name'],
            ]);
        });

        return response()->json([
            'message' => 'Employee approved and virtual account created successfully.',
            'employee' => $employee->fresh('wallet'),
        ]);
    }
}
