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

        $payrolls = Payroll::where('user_id', $employerId)
            ->whereBetween('processed_at', [$startDate, $endDate])
            ->orderBy('processed_at', 'desc')
            ->get();

        $totalRuns = $payrolls->count();
        $totalAmountPaid = $payrolls->sum('amount');
        $totalStaff = $payrolls->sum('staff_count');
        $averagePerStaff = $totalStaff > 0 ? $totalAmountPaid / $totalStaff : 0;

        // Monthly Payroll Spend (last 12 months)
        $monthlySpend = Payroll::where('user_id', $employerId)
            ->where('processed_at', '>=', now()->subMonths(11)->startOfMonth())
            ->select(
                DB::raw('DATE_FORMAT(processed_at, "%b %Y") as month'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('month')
            ->orderBy('processed_at', 'asc')
            ->get();

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
                    'deductions' => '₦' . number_format($p->payslips->sum('other_deductions') + $p->payslips->sum('pension'), 2),
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

        $query = Payslip::whereHas('payroll', function($q) use ($employerId, $startDate, $endDate) {
            $q->where('user_id', $employerId)
              ->whereBetween('processed_at', [$startDate, $endDate]);
        })->with('user');

        if ($department && $department !== 'All Departments') {
            $query->whereHas('user', function($q) use ($department) {
                $q->where('department', $department);
            });
        }

        $payslips = $query->get();

        $data = [
            'staff_payments' => $payslips->map(function($p) {
                $paye = $p->gross_salary * 0.07; // Placeholder calculation (7%)
                return [
                    'staff_name' => $p->user->name,
                    'staff_id' => $p->user->id,
                    'dept' => $p->user->department ?? 'N/A',
                    'gross_pay' => '₦' . number_format($p->gross_salary, 2),
                    'paye' => '₦' . number_format($paye, 2),
                    'pension' => '₦' . number_format($p->pension, 2),
                    'advance_ded' => 'NO', // Placeholder
                    'net_pay' => '₦' . number_format($p->net_salary, 2),
                ];
            }),
            'totals' => [
                'gross_pay' => '₦' . number_format($payslips->sum('gross_salary'), 2),
                'paye' => '₦' . number_format($payslips->sum(fn($p) => $p->gross_salary * 0.07), 2),
                'pension' => '₦' . number_format($payslips->sum('pension'), 2),
                'net_pay' => '₦' . number_format($payslips->sum('net_salary'), 2),
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

        $query = SalaryAdvance::where('user_id', $employerId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('staff');

        if ($status && $status !== 'All Statuses') {
            $query->where('status', strtolower($status));
        }

        $advances = $query->get();

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
                return [
                    'staff_name' => $a->staff->name,
                    'amount' => '₦' . number_format($a->amount, 2),
                    'lender' => 'SalaryNowNow', // Default lender name
                    'issue_date' => $a->created_at->format('d M Y'),
                    'due_date' => $a->created_at->addMonth()->day(25)->format('d M Y'), // Simulated
                    'repaid' => $a->status === 'repaid' ? '₦' . number_format($a->amount, 2) : '₦0.00',
                    'outstanding' => $a->status !== 'repaid' ? '₦' . number_format($a->amount, 2) : '₦0.00',
                    'status' => ucfirst($a->status),
                ];
            })
        ];

        return $this->sendResponse($data, 'Salary advance report retrieved successfully');
    }
}
