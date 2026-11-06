<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();

        $query = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id);

        if ($request->filled('owner_group_user_id')) {
            $query->where('owner_group_user_id', (int) $request->input('owner_group_user_id'));
        }

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
                'name' => $user->company_name ?? $user->name,
                'company_name' => $user->company_name ?? $user->name,
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
                'payroll_settlement_delay_enabled' => $user->hasPayrollSettlementDelay(),
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

        $companies = $query->latest()->get()->map($transform);

        return $this->sendResponse($companies, 'Companies retrieved successfully');
    }

    public function show(Request $request, User $company)
    {
        $admin = $request->user();

        if ($company->type !== User::TYPE_EMPLOYEE || $company->parent_id !== $admin->id) {
            return $this->sendError('Company not found or unauthorized', null, 404);
        }

        $company->load(['staff', 'payrolls.transactions.payslip.user', 'wallet']);
        $company->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

        // Ensure staff relation only contains actual staff (TYPE_STAFF) since the
        // generic relation also picks up other children (e.g. team/admin members).
        $staff = $company->getRelation('staff')->filter(function ($u) {
            return $u->type === User::TYPE_STAFF;
        })->values();
        $company->setRelation('staff', $staff);

        $data = [
            'company_info' => $company,
            'settings' => [
                'payroll_settlement_delay_enabled' => $company->hasPayrollSettlementDelay(),
            ],
        ];

        return $this->sendResponse($data, 'Company details retrieved successfully');
    }

    public function deactivate(Request $request, User $company)
    {
        $admin = $request->user();

        if ($company->type !== User::TYPE_EMPLOYEE || $company->parent_id !== $admin->id) {
            return $this->sendError('Company not found or unauthorized', null, 404);
        }

        $company->update(['is_active' => false]);

        return $this->sendResponse($company, 'Company deactivated successfully');
    }

    public function linkToOwnerGroup(Request $request, User $company)
    {
        $admin = $request->user();

        if ($company->type !== User::TYPE_EMPLOYEE || $company->parent_id !== $admin->id) {
            return $this->sendError('Company not found or unauthorized', null, 404);
        }

        $validated = $request->validate([
            'owner_group_user_id' => 'required|integer|exists:users,id',
        ]);

        $ownerGroupUserId = (int) $validated['owner_group_user_id'];

        $ownerUser = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('id', $ownerGroupUserId)
            ->first();

        if (!$ownerUser) {
            return $this->sendError('owner_group_user_id must be a TYPE_EMPLOYEE under this admin', null, 403);
        }

        $company->update([
            'owner_group_user_id' => $ownerGroupUserId,
        ]);

        return $this->sendResponse($company->fresh(), 'Company linked to owner group successfully');
    }

    /**
     * Update per-employer settings for a company.
     * Currently supports the payroll settlement delay (T+1) toggle.
     */
    public function updateSettings(Request $request, User $company)
    {
        $admin = $request->user();

        if ($company->type !== User::TYPE_EMPLOYEE || $company->parent_id !== $admin->id) {
            return $this->sendError('Company not found or unauthorized', null, 404);
        }

        if (!Schema::hasColumn('users', 'payroll_settlement_delay_enabled')) {
            return $this->sendError('This setting is unavailable. Please run pending migrations.', null, 422);
        }

        $validated = $request->validate([
            'payroll_settlement_delay_enabled' => ['required', 'boolean'],
        ]);

        $company->update([
            'payroll_settlement_delay_enabled' => (bool) $validated['payroll_settlement_delay_enabled'],
        ]);

        return $this->sendResponse([
            'id' => $company->id,
            'payroll_settlement_delay_enabled' => $company->hasPayrollSettlementDelay(),
        ], 'Company settings updated successfully');
    }
}
