<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\Notification;
use App\Models\SalaryAdvance;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class PensionController extends Controller
{
    public function index(Request $request)
    {
        $employers = User::where('type', User::TYPE_EMPLOYEE)->get();
        $employerIds = $employers->pluck('id');
        $companyMap = $employers->pluck('company_name', 'id')->map(fn ($n) => $n ?? 'Company');

        $staffQuery = User::where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $employerIds)
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

        $staff = $staffQuery->latest()->limit(1000)->get();

        $summary = [
            'total_staff' => $staff->count(),
            'total_contributions' => 0.0,
            'employee_contributions' => 0.0,
            'employer_contributions' => 0.0,
            'remitted_this_month' => 0.0,
            'pending_remittances' => 0.0,
            'failed_remittances' => 0.0,
        ];

        $rows = collect([]);
        $monthStart = now()->startOfMonth();
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

            $period = $monthStart->clone()->format('M Y');
            $remitted = !empty($member->pension_employee) && !empty($member->pension_employer);
            $status = $remitted ? 'remitted' : 'pending';
            if ($remitted) $summary['remitted_this_month'] += $total;
            else $summary['pending_remittances'] += $total;

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
                'remitted_at' => $remitted ? now()->toIso8601String() : null,
            ]);
        }

        if ($statusFilter) {
            $rows = $rows->filter(fn ($r) => $r['status'] === $statusFilter)->values();
        }

        // Aggregate per-company rows for AdminPension table
        $companyMapAgg = [];
        foreach ($rows as $r) {
            $key = ($r['company_id'] ?? 'none') . '__' . $r['pay_period'];
            if (!isset($companyMapAgg[$key])) {
                $companyMapAgg[$key] = [
                    'id' => $r['id'],
                    'company_id' => $r['company_id'],
                    'company_name' => $r['company_name'],
                    'pay_period' => $r['pay_period'],
                    'pfa_name' => $r['pfa_name'],
                    'staff_count' => 0,
                    'total_employee_contribution' => 0.0,
                    'total_employer_contribution' => 0.0,
                    'status' => $r['status'],
                    'remitted_at' => $r['remitted_at'],
                    'created_at' => $r['created_at'],
                ];
            }
            $agg = &$companyMapAgg[$key];
            $agg['staff_count'] += 1;
            $agg['total_employee_contribution'] += $r['employee_contribution'];
            $agg['total_employer_contribution'] += $r['employer_contribution'];
            if ($r['status'] === 'pending') $agg['status'] = 'pending';
        }

        $companies = $employers->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->company_name ?? $c->name ?? 'Company',
        ])->values();

        $summary = [
            'total_contributions' => '₦' . number_format((float) ($summary['total_contributions'] ?? 0), 2),
            'employee_contributions' => '₦' . number_format((float) ($summary['employee_contributions'] ?? 0), 2),
            'employer_contributions' => '₦' . number_format((float) ($summary['employer_contributions'] ?? 0), 2),
            'remitted_this_month' => '₦' . number_format((float) ($summary['remitted_this_month'] ?? 0), 2),
            'pending_remittances' => '₦' . number_format((float) ($summary['pending_remittances'] ?? 0), 2),
            'failed_remittances' => '₦' . number_format((float) ($summary['failed_remittances'] ?? 0), 2),
            'total_staff' => $summary['total_staff'] ?? 0,
        ];

        return $this->sendResponse([
            'summary' => $summary,
            'contributions' => $rows->values(),
            'rows' => array_values($companyMapAgg),
            'companies' => $companies,
        ], 'Pension contributions retrieved');
    }

    public function remit(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::whereIn('id', $request->ids)->update([
            'pension_employee' => \DB::raw('salary * (pension_employee_rate / 100)'),
            'pension_employer' => \DB::raw('salary * (pension_employer_rate / 100)'),
            'updated_at' => now(),
        ]);

        return $this->sendResponse(['count' => count($request->ids)], 'Remittance processed');
    }
}
