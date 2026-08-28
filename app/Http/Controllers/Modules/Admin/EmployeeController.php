<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EmployerApproved;
use App\Mail\EmployerRejected;
use App\Models\Role;
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

        $perPage = (int) $request->input('per_page', $request->input('page') ? 15 : 0);

        $transform = function($user) {
            $staffCount = User::where('parent_id', $user->id)->where('type', User::TYPE_STAFF)->count();
            $lastPayroll = $user->payrolls()->latest()->first();

            return [
                'id' => $user->id,
                'company_name' => $user->company_name ?? $user->name,
                'name' => $user->company_name ?? $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'rc_number' => $user->rc_number ?? 'nil',
                'industry' => $user->industry ?? 'nil',
                'company_address' => $user->company_address,
                'staff' => $staffCount,
                'last_payroll' => $lastPayroll ? ($lastPayroll->processed_at?->toIso8601String() ?? $lastPayroll->created_at?->toIso8601String()) : null,
                'kyb_status' => $user->is_approved ? 'approved' : ($user->status ?? 'pending'),
                'is_approved' => (bool) $user->is_approved,
                'has_kyb_documents' => (bool) ($user->cac_certificate_path || $user->director_id_path || $user->utility_bill_path),
                'joined' => $user->created_at->format('d M Y'),
                'created_at' => $user->created_at?->toIso8601String(),
            ];
        };

        if ($perPage > 0) {
            $paginator = $query->latest()->paginate($perPage);
            $mapped = collect($paginator->items())->map($transform)->values();

            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];

            return $this->sendResponse([
                'items' => $mapped,
                'pagination' => $pagination,
            ], 'Companies retrieved successfully');
        }

        $employees = $query->latest()->get()->map($transform);

        return $this->sendResponse($employees, 'Companies retrieved successfully');
    }

    /**
     * KYB Reviews endpoint
     */
    public function kybReviews(Request $request)
    {
        $admin = $request->user();

        $pendingCount = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('is_approved', false)->where('status', 'pending')
            ->count();

        $approvedCount = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('is_approved', true)
            ->count();

        $rejectedCount = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('status', 'rejected')
            ->count();

        $status = $request->query('status', 'pending'); // pending, approved, rejected

        $query = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id);

        if ($status === 'pending') {
            $query->where('is_approved', false)->where('status', 'pending');
        } elseif ($status === 'approved') {
            $query->where('is_approved', true);
        } elseif ($status === 'rejected') {
            $query->where('status', 'rejected');
        }

        $reviews = $query->latest()->get()->map(function($user) {
            $staffCount = User::where('parent_id', $user->id)->where('type', User::TYPE_STAFF)->count();

            $user->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

            return [
                'id' => $user->id,
                'company' => [
                    'name' => $user->company_name ?? $user->name,
                    'industry' => $user->industry ?? 'nil',
                ],
                'cac_no' => $user->rc_number ?? 'nil',
                'documents' => [
                    'cac_certificate' => $user->cac_certificate_url,
                    'director_id' => $user->director_id_url,
                    'utility_bill' => $user->utility_bill_url,
                ],
                'type' => 'Company',
                'industry' => $user->industry ?? 'nil',
                'state' => $user->state_of_origin ?? 'Nigeria',
                'submitted' => $user->created_at->format('d M Y'),
                'staff' => $staffCount,
                'status' => $user->is_approved ? 'Approved' : ucfirst($user->status),
            ];
        });

        $data = [
            'counts' => [
                'pending' => $pendingCount,
                'approved' => $approvedCount,
                'rejected' => $rejectedCount,
            ],
            'reviews' => $reviews,
        ];

        return $this->sendResponse($data, 'KYB reviews retrieved successfully');
    }

    public function show(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $employee->load(['staff', 'payrolls.transactions.payslip.user', 'wallet']);
        $employee->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

        $staff = $employee->getRelation('staff')->filter(function ($u) {
            return $u->type === User::TYPE_STAFF;
        })->values();
        $employee->setRelation('staff', $staff);

        return $this->sendResponse(['company_info' => $employee], 'Employee details retrieved');
    }

    public function approve(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $wasApproved = (bool) $employee->is_approved;

        // Only create a fresh virtual account when the company was NOT approved yet.
        // If already approved, skip Sarepay account creation and reuse the existing wallet.
        $accountData = null;
        if (!$wasApproved) {
            $sarepayResponse = $this->sarepayService->createAccount($employee);
            $accountData = $sarepayResponse;
        }

        DB::transaction(function () use ($employee, $accountData) {
            $employee->update(['is_approved' => true, 'status' => 'approved']);

            // Wallet already exists for previously approved companies;
            // create-or-update ensures we never error on re-approve.
            if ($accountData) {
                Wallet::updateOrCreate([
                    'user_id' => $employee->id,
                ], [
                    'currency' => 'NGN',
                    'account_number' => $accountData->account_number,
                    'account_name' => $accountData->account_name,
                    'account_reference' => $accountData->account_reference,
                    'bank_name' => $accountData->bank_name,
                ]);
            }
        });

        // Always re-send the approval email on approve() so the merchant can
        // manually re-trigger it for already-approved companies too.
        Mail::to($employee->email)->send(new EmployerApproved($employee));

        $message = $wasApproved
            ? 'Company re-approved successfully (approval email re-sent).'
            : 'Employee approved and virtual account created successfully.';

        return $this->sendResponse($employee->fresh('wallet'), $message);
    }

    public function reject(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $employee->update(['status' => 'rejected']);

        Mail::to($employee->email)->send(new EmployerRejected($employee, $request->reason));

        return $this->sendResponse(null, 'Employee KYC rejected successfully.');
    }

    public function hold(Request $request, User $employee)
    {
        $admin = $request->user();

        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $employee->update(['status' => 'under_review']);

        return $this->sendResponse(null, 'Employee KYC placed under review.');
    }

    public function createDefaultRole(Request $request, User $employee)
    {
        $admin = $request->user();

        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        $employee = DB::transaction(function () use ($employee) {
            $standardRoles = Role::ensureStandardRolesForEmployer((int) $employee->id);
            $defaultRole = $standardRoles['admin'] ?? null;
            if (!$defaultRole) {
                throw new \RuntimeException('Unable to create default admin role.');
            }

            $employee->update([
                'role_id' => $defaultRole->id,
            ]);

            return $employee->fresh('role');
        });

        return $this->sendResponse($employee, 'Default roles + permissions created successfully.');
    }

    public function regenerateVirtualAccount(Request $request, User $employee)
    {
        $admin = $request->user();

        // Ensure the employee belongs to this merchant
        if ($employee->type !== User::TYPE_EMPLOYEE || $employee->parent_id !== $admin->id) {
            return $this->sendError('Employee not found or unauthorized', null, 404);
        }

        // Call Sarepay to create virtual account
        $sarepayResponse = $this->sarepayService->createAccount($employee);

        $accountData = $sarepayResponse;

        Wallet::updateOrCreate([
            'user_id' => $employee->id,
        ], [
            'currency' => 'NGN',
            'account_number' => $accountData->account_number,
            'account_name' => $accountData->account_name,
            'account_reference' => $accountData->account_reference,
            'bank_name' => $accountData->bank_name,
        ]);

        return $this->sendResponse($employee->fresh('wallet'), 'Virtual account regenerated successfully.');
    }
}
