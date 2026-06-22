<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\PayslipMail;
use App\Mail\PayrollCompleted;
use App\Models\User;
use App\Models\Payslip;
use App\Models\WalletLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PayrollController extends Controller
{
    /**
     * Step 1: Get estimates for the payroll run based on active staff.
     */
    public function configure(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $staff = User::where('employer_id', $employerId)->staff()->where('is_active', true)->get();

        $totalGross = $staff->sum('salary');
        // Simple net calculation: Gross - Pension (EE 8%)
        $totalNet = $staff->sum(fn($s) => $s->salary * 0.92);

        $data = [
            'staff_count' => $staff->count(),
            'est_gross' => '₦' . number_format($totalGross, 2),
            'est_net' => '₦' . number_format($totalNet, 2),
            'raw_totals' => [
                'gross' => $totalGross,
                'net' => $totalNet,
            ]
        ];

        return $this->sendResponse($data, 'Payroll estimates retrieved successfully');
    }

    /**
     * Step 2: Review and Edit staff details for the payroll.
     */
    public function review(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $staff = User::where('employer_id', $employerId)->staff()->where('is_active', true)->get();

        $staffDetails = $staff->map(function($s) {
            $pensionEE = $s->salary * ($s->pension_employee_rate / 100);
            $pensionER = $s->salary * ($s->pension_employer_rate / 100);
            $netPay = $s->salary - $pensionEE;

            return [
                'id' => $s->id,
                'name' => $s->name,
                'gross' => '₦' . number_format($s->salary, 2),
                'pension_ee' => '₦' . number_format($pensionEE, 2),
                'pension_er' => '₦' . number_format($pensionER, 2),
                'advance_ded' => 'NO',
                'net_pay' => '₦' . number_format($netPay, 2),
                'raw_net' => $netPay,
                'raw_gross' => $s->salary,
            ];
        });

        $data = [
            'staff_payments' => $staffDetails,
            'summary' => [
                'count' => $staff->count(),
                'grand_total_net' => '₦' . number_format($staffDetails->sum('raw_net'), 2),
                'raw_total_net' => $staffDetails->sum('raw_net'),
            ]
        ];

        return $this->sendResponse($data, 'Payroll review details retrieved successfully');
    }

    /**
     * Step 3: Check if wallet balance is sufficient.
     */
    public function checkBalance(Request $request)
    {
        $request->validate([
            'total_amount' => 'required|numeric|min:0',
        ]);

        $employerId = $request->user()->getEmployerId();
        
        $employer = User::with('wallet')->find($employerId);

        $wallet = $employer->wallet;


        $isSufficient = $wallet && $wallet->balance >= $request->total_amount;

        $data = [
        
            'is_sufficient' => $isSufficient,
            'current_balance' => '₦' . number_format($wallet?->balance ?? 0, 2),
            'required_amount' => '₦' . number_format($request->total_amount, 2),
        ];

        return $this->sendResponse($data, 'Balance check completed');
    }

    /**
     * Step 4: Finalize and Save the payroll.
     */
    public function store(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'pay_date' => 'required|date',
            'staff_data' => 'required|array', // Array of objects with staff_id and deductions
            'staff_data.*.id' => 'required|exists:users,id',
            'staff_data.*.deductions' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        // $employer = $user->employer()->first();
        $employerId = $request->user()->getEmployerId();
        
        $employer = User::with('wallet')->find($employerId);

        $wallet = $employer->wallet;


        return DB::transaction(function () use ($request, $employer, $wallet) {
            $totalNetToPay = 0;
            $staffCount = 0;
            $totalGross = 0;
            $payslips = [];

            $payroll = \App\Models\Payroll::create([
                'user_id' => $employer->id,
                'description' => now()->format('F Y') . ' Salary',
                'amount' => 0, // Will update after calculation
                'staff_count' => 0,
                'status' => \App\Models\Payroll::STATUS_PENDING,
                'processed_at' => $request->pay_date,
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
            ]);

            foreach ($request->staff_data as $item) {
                $staff = User::find($item['id']);
                if ($staff->employer_id !== $employer->id) continue;

                $pensionEE = $staff->salary * ($staff->pension_employee_rate / 100);
                $deductions = $item['deductions'] ?? 0;
                $netPay = $staff->salary - $pensionEE - $deductions;

                $payslip = Payslip::create([
                    'user_id' => $staff->id,
                    'payroll_id' => $payroll->id,
                    'period' => $payroll->period_start->format('M Y'),
                    'gross_salary' => $staff->salary,
                    'pension' => $pensionEE,
                    'other_deductions' => $deductions,
                    'net_salary' => $netPay,
                    'status' => Payslip::STATUS_DISBURSED,
                ]);

                $payslips[] = $payslip;
                $totalNetToPay += $netPay;
                $totalGross += $staff->salary;
                $staffCount++;
            }

            // Check final balance before deduction
            if ($wallet->balance < $totalNetToPay) {
                throw new \Exception("Insufficient wallet balance to complete payroll.");
            }

            // Update Payroll totals
            $payroll->update([
                'amount' => $totalNetToPay,
                'staff_count' => $staffCount,
                //'status' => \App\Models\Payroll::STATUS_COMPLETED,
            ]);

            // Deduct from wallet
            $balanceBefore = $wallet->balance;
            $wallet->decrement('balance', $totalNetToPay);

            // Log wallet transaction
            $wallet->logs()->create([
                'amount' => $totalNetToPay,
                'type' => 'debit',
                'description' => "Payroll Run: {$payroll->description}",
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'metadata' => ['payroll_id' => $payroll->id]
            ]);

            // Send payslip emails
            // foreach ($payslips as $payslip) {
            //     $payslip->load('user');
            //     Mail::to($payslip->user->email)->send(new PayslipMail($payslip));
            // }

            // // Send payroll completed email to employer
            // $payroll->load('user');
            // Mail::to($payroll->user->email)->send(new PayrollCompleted($payroll));

            return $this->sendResponse($payroll, 'Payroll processed successfully', true, 201);
        });
    }

    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $payrolls = \App\Models\Payroll::where('user_id', $employerId)
            ->orderBy('processed_at', 'desc')
            ->get();

        $data = $payrolls->map(function($p) {
            return [
                'id' => $p->id,
                'run_date' => $p->processed_at->format('d M Y'),
                'pay_period' => $p->period_start->format('d M') . ' — ' . $p->period_end->format('d M Y'),
                'staff_count' => $p->staff_count,
                'total_amount' => '₦' . number_format($p->amount, 2),
                'status' => $p->status,
            ];
        });

        return $this->sendResponse($data, 'Payroll history retrieved successfully');
    }

    public function show(Request $request, $id)
    {
        $employerId = $request->user()->getEmployerId();
        $payroll = \App\Models\Payroll::where('user_id', $employerId)
            ->with(['payslips.user'])
            ->findOrFail($id);
        
        $totalDeductions = $payroll->payslips->sum('other_deductions');
        $totalGross = $payroll->payslips->sum('gross_salary');

        $data = [

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
                        'status' => $p->status,
                    ];
                }),
         
        ];

        return $this->sendResponse($data, 'Payroll details retrieved successfully');
    }
}
