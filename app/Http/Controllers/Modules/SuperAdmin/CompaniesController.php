<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CompaniesController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('type', User::TYPE_EMPLOYEE);

        if ($request->filled('owner_group_user_id')) {
            $query->where('owner_group_user_id', (int) $request->input('owner_group_user_id'));
        }

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('company_name', 'like', "%{$request->search}%")
                  ->orWhere('rc_number', 'like', "%{$request->search}%")
                  ->orWhere('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
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
                'parent_id' => $user->parent_id,
                'owner_group_user_id' => $user->owner_group_user_id,
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
        if ($company->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('Company not found', null, 404);
        }

        $company->load(['staff', 'payrolls.transactions.payslip.user', 'wallet']);
        $company->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

        $staff = $company->getRelation('staff')->filter(function ($u) {
            return $u->type === User::TYPE_STAFF;
        })->values();
        $company->setRelation('staff', $staff);

        $data = [
            'company_info' => $company,
        ];

        return $this->sendResponse($data, 'Company details retrieved successfully');
    }

    public function deactivate(Request $request, User $company)
    {
        if ($company->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('Company not found', null, 404);
        }

        $company->update(['is_active' => false]);

        return $this->sendResponse($company, 'Company deactivated successfully');
    }

    public function linkToOwnerGroup(Request $request, User $company)
    {
        if ($company->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('Company not found', null, 404);
        }

        $validated = $request->validate([
            'owner_group_user_id' => 'required|integer|exists:users,id',
        ]);

        $ownerGroupUserId = (int) $validated['owner_group_user_id'];

        $ownerUser = User::where('type', User::TYPE_EMPLOYEE)
            ->where('id', $ownerGroupUserId)
            ->first();

        if (!$ownerUser) {
            return $this->sendError('owner_group_user_id must be a TYPE_EMPLOYEE user', null, 403);
        }

        $company->update([
            'owner_group_user_id' => $ownerGroupUserId,
        ]);

        return $this->sendResponse($company->fresh(), 'Company linked to owner group successfully');
    }
}
