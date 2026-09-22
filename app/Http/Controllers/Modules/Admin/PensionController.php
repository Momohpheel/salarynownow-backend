<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PensionController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $employerIds = $this->resolveEmployerIds($admin);

        $summary = [
            'total_contributions' => 0,
            'employee_contributions' => 0,
            'employer_contributions' => 0,
            'remitted_this_month' => 0,
            'pending_remittances' => 0,
            'failed_remittances' => 0,
        ];

        $rows = collect([]);
        $companyMap = User::whereIn('id', $employerIds)
            ->where('type', User::TYPE_EMPLOYEE)
            ->pluck('company_name', 'id')
            ->map(fn ($n) => $n ?? 'Company');

        $staffQuery = User::where('type', User::TYPE_STAFF)
            ->when($employerIds->isNotEmpty(), fn ($q) => $q->whereIn('parent_id', $employerIds))
            ->with(['parent:id,company_name']);

        if ($request->filled('search')) {
            $s = $request->search;
            $staffQuery->where(function ($q) use ($s) {
                $q->where('pfa_name', 'like', "%{$s}%")
                    ->orWhereHas('parent', fn ($p) => $p->where('company_name', 'like', "%{$s}%"))
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$s}%"])
                    ->orWhere('name', 'like', "%{$s}%");
            });
        }

        $staff = $staffQuery->latest()->limit(500)->get();

        $monthStart = Carbon::now()->startOfMonth();
        $statusFilter = $request->filled('status') && $request->status !== 'all' ? $request->status : null;

        foreach ($staff as $member) {
            $empRate = (float) ($member->pension_employee_rate ?? 8);
            $empRate = max(0, min(100, $empRate));
            $erRate = (float) ($member->pension_employer_rate ?? 10);
            $erRate = max(0, min(100, $erRate));
            $salary = (float) ($member->salary ?? 0);
            $empContrib = round($salary * ($empRate / 100), 2);
            $erContrib = round($salary * ($erRate / 100), 2);
            $total = $empContrib + $erContrib;

            $summary['employee_contributions'] += $empContrib;
            $summary['employer_contributions'] += $erContrib;
            $summary['total_contributions'] += $total;

            $period = $monthStart->clone()->subMonths(0)->format('M Y');
            $status = 'remitted';
            if (!empty($member->pension_employee) && !empty($member->pension_employer)) {
                $status = 'remitted';
                $summary['remitted_this_month'] += $total;
            } else {
                $status = 'pending';
                $summary['pending_remittances'] += $total;
            }

            $rows->push([
                'id' => $member->id,
                'staff_name' => $member->name ?? trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')),
                'company_id' => $member->parent_id,
                'company_name' => $member->parent->company_name ?? $companyMap->get($member->parent_id, '—'),
                'pfa_name' => $member->pfa_name ?? 'Not set',
                'rsa_pin' => $member->rsa_pin ?? '—',
                'employee_rate' => $empRate,
                'employer_rate' => $erRate,
                'gross_salary' => $salary,
                'employee_contribution' => $empContrib,
                'employer_contribution' => $erContrib,
                'total_contribution' => $total,
                'pay_period' => $period,
                'status' => $status,
                'created_at' => $member->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'remitted_at' => $status === 'remitted' ? now()->toIso8601String() : null,
            ]);
        }

        if ($statusFilter) {
            $rows = $rows->filter(fn ($r) => $r['status'] === $statusFilter)->values();
        }

        $summary['total_contributions'] = (float) $summary['total_contributions'];
        $summary['employee_contributions'] = (float) $summary['employee_contributions'];
        $summary['employer_contributions'] = (float) $summary['employer_contributions'];
        $summary['remitted_this_month'] = (float) $summary['remitted_this_month'];
        $summary['pending_remittances'] = (float) $summary['pending_remittances'];
        $summary['failed_remittances'] = (float) $summary['failed_remittances'];
        $summary['total_staff' ] = $staff->count();

        $companies = User::whereIn('id', $employerIds)
            ->where('type', User::TYPE_EMPLOYEE)
            ->select('id', DB::raw("COALESCE(company_name, name) as name"))
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name ?? 'Company'])
            ->values();

        return $this->sendResponse([
            'summary' => $summary,
            'contributions' => $rows->values(),
            'companies' => $companies,
        ], 'Pension contributions retrieved');
    }

    public function remit(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        $ids = $request->ids;
        User::whereIn('id', $ids)->update([
            'pension_employee' => DB::raw('salary * (pension_employee_rate / 100)'),
            'pension_employer' => DB::raw('salary * (pension_employer_rate / 100)'),
            'updated_at' => now(),
        ]);

        return $this->sendResponse(['count' => count($ids)], 'Remittance processed');
    }

    private function resolveEmployerIds(User $admin)
    {
        if ($admin->type === User::TYPE_SUPERADMIN) {
            return User::where('type', User::TYPE_EMPLOYEE)->pluck('id');
        }
        $ids = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return User::where('type', User::TYPE_EMPLOYEE)->pluck('id');
        }
        return $ids;
    }
}
