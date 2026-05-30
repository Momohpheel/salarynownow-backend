<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $payrolls = \App\Models\Payroll::where('user_id', $employerId)
            ->orderBy('processed_at', 'desc')
            ->get();

        return response()->json([
            'payroll_history' => $payrolls->map(function($p) {
                return [
                    'id' => $p->id,
                    'run_date' => $p->processed_at->format('d M Y'),
                    'pay_period' => $p->period_start->format('d M') . ' — ' . $p->period_end->format('d M Y'),
                    'staff_count' => $p->staff_count,
                    'total_amount' => '₦' . number_format($p->amount, 2),
                    'status' => $p->status,
                ];
            }),
        ]);
    }

    public function show(Request $request, $id)
    {
        $employerId = $request->user()->getEmployerId();
        $payroll = \App\Models\Payroll::where('user_id', $employerId)
            ->with(['payslips.user'])
            ->findOrFail($id);
        
        $totalDeductions = $payroll->payslips->sum('other_deductions');
        $totalGross = $payroll->payslips->sum('gross_salary');

        return response()->json([
            'payroll_run' => [
                'id' => $payroll->id,
                'period' => $payroll->period_start->format('d M') . ' — ' . $payroll->period_end->format('d M Y'),
                'status' => $payroll->status,
                'summary' => [
                    'staff_count' => $payroll->staff_count,
                    'total_gross' => '₦' . number_format($totalGross, 2),
                    'total_deductions' => '₦' . number_format($totalDeductions, 2),
                    'net_disbursement' => '₦' . number_format($payroll->amount, 2),
                ],
                'staff_payments' => $payroll->payslips->map(function($p) {
                    return [
                        'id' => $p->id,
                        'name' => $p->user->name,
                        'bank' => $p->user->bank_name ?? '-',
                        'account' => $p->user->account_number ?? '-',
                        'gross' => '₦' . number_format($p->gross_salary, 2),
                        'deductions' => $p->other_deductions > 0 ? '₦' . number_format($p->other_deductions, 2) : 'NO',
                        'advance_ded' => 'NO', // Placeholder for advance deduction logic
                        'net_pay' => '₦' . number_format($p->net_salary, 2),
                        'status' => 'disbursed',
                    ];
                }),
            ],
        ]);
    }
}
