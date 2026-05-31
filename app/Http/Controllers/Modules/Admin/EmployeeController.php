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

        return $this->sendResponse($employees, 'Employees retrieved successfully');
    }

    public function show(User $employee)
    {
        if ($employee->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('User is not an employee.', null, 404);
        }

        return $this->sendResponse($employee->load('wallet'), 'Employee details retrieved successfully');
    }

    public function approve(User $employee)
    {
        if ($employee->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('User is not an employee.', null, 404);
        }

        if ($employee->is_approved) {
            return $this->sendError('Employee is already approved.', null, 400);
        }

        // Call Sarepay to create virtual account
        $sarepayResponse = $this->sarepayService->createAccount($employee);

       

        $accountData = $sarepayResponse;

        DB::transaction(function () use ($employee, $accountData) {
            $employee->update(['is_approved' => true]);

            // Create wallet for the employee with virtual account details
            Wallet::firstOrCreate([
                'user_id' => $employee->id,
            ], [
                'balance' => 0.00,
                'currency' => 'NGN',
                'account_number' => $accountData->account_number,
                'account_name' => $accountData->account_name,
                'account_reference' => $accountData->account_reference,
                'bank_name' => $accountData->bank_name,
            ]);
        });

        return $this->sendResponse($employee->fresh('wallet'), 'Employee approved and virtual account created successfully.');
    }
}
