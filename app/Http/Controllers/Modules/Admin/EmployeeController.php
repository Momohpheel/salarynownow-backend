<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EmployerApproved;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EmployeeController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function index(Request $request)
    {
        $admin = $request->user();
        
        $query = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id);

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('company_name', 'like', "%{$request->search}%")
                  ->orWhere('rc_number', 'like', "%{$request->search}%");
            });
        }

        $employees = $query->latest()->get()->map(function($user) {
            $staffCount = User::where('parent_id', $user->id)->where('type', User::TYPE_STAFF)->count();
            $lastPayroll = $user->payrolls()->latest()->first();

            return [
                'id' => $user->id,
                'company_name' => $user->company_name ?? '—',
                'rc_number' => $user->rc_number ?? '—',
                'staff' => $staffCount,
                'last_payroll' => $lastPayroll ? '₦' . number_format($lastPayroll->amount, 0) : '—',
                'kyb_status' => $user->is_approved ? 'Approved' : 'Pending',
                'joined' => $user->created_at->format('d M Y'),
            ];
        });

        return $this->sendResponse($employees, 'Companies retrieved successfully');
    }

    /**
     * KYB Reviews endpoint
     */
    public function kybReviews(Request $request)
    {
        $admin = $request->user();
        $status = $request->query('status', 'pending'); // pending, approved, rejected

        $query = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id);

        if ($status === 'pending') {
            $query->where('is_approved', false);
        } elseif ($status === 'approved') {
            $query->where('is_approved', true);
        }

        $reviews = $query->latest()->get()->map(function($user) {
            $staffCount = User::where('parent_id', $user->id)->where('type', User::TYPE_STAFF)->count();

            $user->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

            return [
                'id' => $user->id,
                'company' => [
                    'name' => $user->company_name ?? '—',
                    'industry' => $user->industry ?? '—',
                ],
                'cac_no' => $user->rc_number ?? '—',
                'documents' => [
                    'cac_certificate' => $user->cac_certificate_url,
                    'director_id' => $user->director_id_url,
                    'utility_bill' => $user->utility_bill_url,
                ],
                'type' => 'Company',
                'industry' => $user->industry ?? '—',
                'state' => $user->state_of_origin ?? '—',
                'submitted' => $user->created_at->format('d M Y'),
                'staff' => $staffCount,
                'status' => $user->is_approved ? 'Approved' : 'Pending',
            ];
        });

        return $this->sendResponse($reviews, 'KYB reviews retrieved successfully');
    }

    public function show(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->employer_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $employee->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

        return $this->sendResponse($employee, 'Employee details retrieved');
    }

    public function approve(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
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

        Mail::to($employee->email)->send(new EmployerApproved($employee));

        return $this->sendResponse($employee->fresh('wallet'), 'Employee approved and virtual account created successfully.');
    }
}
