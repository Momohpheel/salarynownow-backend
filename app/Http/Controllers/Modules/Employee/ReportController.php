<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Payroll Summary Report
     */
    public function payrollSummary(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $startDate = $request->query('start_date', now()->subYear()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));
        $startCarbon = Carbon::parse($startDate)->startOfDay();
        $endCarbon = Carbon::parse($endDate)->endOfDay();

        $payrolls = Payroll::where('user_id', $employerId)
            ->whereBetween('processed_at', [$startCarbon, $endCarbon])
            ->with('payslips')
            ->orderBy('processed_at', 'desc')
            ->get();

        $totalRuns = $payrolls->count();
        $totalAmountPaid = $payrolls->sum('amount');
        $totalStaff = $payrolls->sum('staff_count');
        $averagePerStaff = $totalStaff > 0 ? $totalAmountPaid / $totalStaff : 0;

        $startMonth = $startCarbon->copy()->startOfMonth();
        $endMonth = $endCarbon->copy()->startOfMonth();
        $monthKeys = collect();
        $cursor = $startMonth->copy();
        while ($cursor->lte($endMonth)) {
            $monthKeys->push($cursor->format('M Y'));
            $cursor->addMonth();
        }

        $monthlySpendAgg = Payroll::where('user_id', $employerId)
            ->whereBetween('processed_at', [$startCarbon, $endCarbon])
            ->select(
                DB::raw('DATE_FORMAT(processed_at, "%b %Y") as month'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('month')
            ->orderByRaw('MIN(processed_at) asc')
            ->get()
            ->keyBy('month');

        $monthlySpend = $monthKeys->map(function ($m) use ($monthlySpendAgg) {
            return (object)[
                'month' => $m,
                'total' => (string)($monthlySpendAgg->get($m)?->total ?? '0'),
            ];
        })->values();

        $data = [
            'overview' => [
                'total_payroll_runs' => $totalRuns,
                'total_amount_paid' => '₦' . number_format($totalAmountPaid, 2),
                'average_per_staff' => '₦' . number_format($averagePerStaff, 2),
                'raw_totals' => [
                    'runs' => $totalRuns,
                    'amount' => $totalAmountPaid,
                    'average' => $averagePerStaff,
                ]
            ],
            'monthly_spend_chart' => $monthlySpend,
            'payroll_runs' => $payrolls->map(function($p) {
                return [
                    'id' => $p->id,
                    'month' => $p->processed_at->format('M Y'),
                    'pay_date' => $p->processed_at->format('d M Y'),
                    'staff_count' => $p->staff_count,
                    'gross_amount' => '₦' . number_format($p->payslips->sum('gross_salary'), 2),
                    'deductions' => '₦' . number_format($p->payslips->sum('other_deductions') + $p->payslips->sum('pension') + $p->payslips->sum('tax_deduction') + $p->payslips->sum('nhf'), 2),
                    'net_amount' => '₦' . number_format($p->amount, 2),
                    'status' => $p->status,
                ];
            })
        ];

        return $this->sendResponse($data, 'Payroll summary report retrieved successfully');
    }

    /**
     * Staff Payments Report
     */
    public function staffPayments(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $startDate = $request->query('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));
        $department = $request->query('department');
        $startCarbon = Carbon::parse($startDate)->startOfDay();
        $endCarbon = Carbon::parse($endDate)->endOfDay();

        $query = Payslip::whereHas('payroll', function($q) use ($employerId, $startCarbon, $endCarbon) {
            $q->where('user_id', $employerId)
              ->whereBetween('processed_at', [$startCarbon, $endCarbon]);
        })->with(['user', 'payroll']);

        if ($department && $department !== 'All Departments') {
            $query->whereHas('user', function($q) use ($department) {
                $q->where('department', $department);
            });
        }

        $payslips = $query->orderBy('created_at', 'desc')->get();

        $advanceDeductionLookup = collect([]);
        try {
            $payrollIds = $payslips->pluck('payroll_id')->unique()->filter()->values();
            if ($payrollIds->isNotEmpty()) {
                $staffIds = $payslips->pluck('user_id')->unique()->filter()->values();
                if ($staffIds->isNotEmpty()) {
                    $advanceDeductionLookup = \App\Models\SalaryAdvance::whereIn('user_id', $staffIds)
                        ->whereIn('deducted_from_payroll_id', $payrollIds)
                        ->orWhere(function ($q) use ($staffIds, $startCarbon, $endCarbon) {
                            $q->whereIn('user_id', $staffIds)
                                ->where('status', 'repaid')
                                ->whereBetween('updated_at', [$startCarbon, $endCarbon]);
                        })
                        ->get()
                        ->groupBy(fn ($a) => ($a->deducted_from_payroll_id ?? 'x') . '_' . $a->user_id)
                        ->map(fn ($g) => $g->sum('amount'));
                }
            }
        } catch (\Throwable) {
        }

        $rows = $payslips->map(function($p) use ($advanceDeductionLookup) {
            $paye = (float)($p->tax_deduction ?? 0);
            $advanceKey = ($p->payroll_id ?? 'x') . '_' . $p->user_id;
            $advanceAmount = (float)($advanceDeductionLookup->get($advanceKey, 0));
            return [
                'staff_name' => $p->user->name,
                'staff_id' => $p->user->id,
                'dept' => $p->user->department ?? 'N/A',
                'gross_pay' => '₦' . number_format($p->gross_salary, 2),
                'paye' => '₦' . number_format($paye, 2),
                'pension' => '₦' . number_format((float)($p->pension ?? 0) + (float)($p->pension_employer ?? 0), 2),
                'advance_ded' => $advanceAmount > 0 ? ('₦' . number_format($advanceAmount, 2)) : 'NO',
                'net_pay' => '₦' . number_format($p->net_salary, 2),
                '_raw' => [
                    'gross_pay' => (float)$p->gross_salary,
                    'paye' => $paye,
                    'pension' => (float)($p->pension ?? 0) + (float)($p->pension_employer ?? 0),
                    'advance_ded' => $advanceAmount,
                    'net_pay' => (float)$p->net_salary,
                ],
            ];
        });

        $totalsGross = $rows->sum(fn ($r) => $r['_raw']['gross_pay']);
        $totalsPaye = $rows->sum(fn ($r) => $r['_raw']['paye']);
        $totalsPension = $rows->sum(fn ($r) => $r['_raw']['pension']);
        $totalsAdvance = $rows->sum(fn ($r) => $r['_raw']['advance_ded']);
        $totalsNet = $rows->sum(fn ($r) => $r['_raw']['net_pay']);

        $data = [
            'staff_payments' => $rows->map(function ($r) {
                unset($r['_raw']);
                return $r;
            }),
            'totals' => [
                'gross_pay' => '₦' . number_format($totalsGross, 2),
                'paye' => '₦' . number_format($totalsPaye, 2),
                'pension' => '₦' . number_format($totalsPension, 2),
                'advance_ded' => $totalsAdvance > 0 ? ('₦' . number_format($totalsAdvance, 2)) : 'NO',
                'net_pay' => '₦' . number_format($totalsNet, 2),
                'raw' => [
                    'gross_pay' => $totalsGross,
                    'paye' => $totalsPaye,
                    'pension' => $totalsPension,
                    'advance_ded' => $totalsAdvance,
                    'net_pay' => $totalsNet,
                ],
            ]
        ];

        return $this->sendResponse($data, 'Staff payments report retrieved successfully');
    }

    /**
     * Advance Report
     */
    public function advanceReport(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $startDate = $request->query('start_date', now()->subYear()->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));
        $status = $request->query('status');
        $startCarbon = Carbon::parse($startDate)->startOfDay();
        $endCarbon = Carbon::parse($endDate)->endOfDay();

        $query = SalaryAdvance::where('user_id', $employerId)
            ->whereBetween('created_at', [$startCarbon, $endCarbon])
            ->with(['staff', 'user']);

        if ($status && $status !== 'All Statuses') {
            $query->where('status', strtolower($status));
        }

        $advances = $query->orderBy('created_at', 'desc')->get();

        $totalIssued = $advances->sum('amount');
        $totalRepaid = $advances->where('status', 'repaid')->sum('amount');
        $totalOutstanding = $totalIssued - $totalRepaid;

        $data = [
            'overview' => [
                'total_issued' => '₦' . number_format($totalIssued, 2),
                'total_repaid' => '₦' . number_format($totalRepaid, 2),
                'total_outstanding' => '₦' . number_format($totalOutstanding, 2),
            ],
            'advances' => $advances->map(function($a) {
                $issueDate = $a->created_at;
                $dueDate = $a->due_date ? Carbon::parse($a->due_date) : $issueDate->copy()->addMonth()->day(min(25, $issueDate->daysInMonth));
                $repaidAmount = $a->status === 'repaid' ? (float)($a->amount_repaid ?? $a->amount) : (float)($a->amount_repaid ?? 0);
                $outstandingAmount = max(0, (float)$a->amount - $repaidAmount);
                return [
                    'staff_name' => $a->staff->name ?? 'Unknown',
                    'amount' => '₦' . number_format($a->amount, 2),
                    'lender' => $a->lender_name ?? 'Sugar Payroll',
                    'issue_date' => $issueDate->format('d M Y'),
                    'due_date' => $dueDate->format('d M Y'),
                    'repaid' => '₦' . number_format($repaidAmount, 2),
                    'outstanding' => '₦' . number_format($outstandingAmount, 2),
                    'status' => ucfirst($a->status),
                ];
            })
        ];

        return $this->sendResponse($data, 'Salary advance report retrieved successfully');
    }
}
