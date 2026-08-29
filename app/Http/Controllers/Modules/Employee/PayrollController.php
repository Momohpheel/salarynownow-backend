<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\DeductionType;
use App\Models\Payroll;
use App\Models\PayrollUploadFlag;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log as FacadesLog;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;


class PayrollController extends Controller
{
    private function buildLegacyAwareDeductionBreakdown($payslip)
    {
        $rows = collect();
        if ($payslip->relationLoaded('deductions') && $payslip->deductions && $payslip->deductions->isNotEmpty()) {
            foreach ($payslip->deductions as $d) {
                $rows->push([
                    'id' => $d->id,
                    'name' => $d->deduction_name,
                    'key' => $d->deduction_key,
                    'amount' => '₦' . number_format($d->amount, 2),
                    'amount_raw' => (float) $d->amount,
                ]);
            }
            return $rows->values();
        }
        if ((float) $payslip->tax_deduction > 0) {
            $rows->push([
                'id' => 'legacy_tax_' . $payslip->id,
                'name' => 'Tax (PAYE)',
                'key' => 'tax',
                'amount' => '₦' . number_format($payslip->tax_deduction, 2),
                'amount_raw' => (float) $payslip->tax_deduction,
            ]);
        }
        if ((float) $payslip->pension_employee > 0) {
            $rows->push([
                'id' => 'legacy_pension_ee_' . $payslip->id,
                'name' => 'Pension (Employee)',
                'key' => 'pension_ee',
                'amount' => '₦' . number_format($payslip->pension_employee, 2),
                'amount_raw' => (float) $payslip->pension_employee,
            ]);
        }
        if ((float) $payslip->nhf > 0) {
            $rows->push([
                'id' => 'legacy_nhf_' . $payslip->id,
                'name' => 'NHF (National Housing Fund)',
                'key' => 'nhf',
                'amount' => '₦' . number_format($payslip->nhf, 2),
                'amount_raw' => (float) $payslip->nhf,
            ]);
        }
        if ((float) $payslip->other_deductions > 0) {
            $label = trim((string) $payslip->deduction_type) ?: 'Other Deductions';
            $rows->push([
                'id' => 'legacy_other_' . $payslip->id,
                'name' => $label,
                'key' => 'other',
                'amount' => '₦' . number_format($payslip->other_deductions, 2),
                'amount_raw' => (float) $payslip->other_deductions,
            ]);
        }
        return $rows->values();
    }

    private function buildBonusBreakdown($payslip)
    {
        $rows = collect();
        if ((float) $payslip->bonus_amount > 0) {
            $label = trim((string) $payslip->bonus_type) ?: 'Bonus';
            $rows->push([
                'id' => 'bonus_' . $payslip->id,
                'name' => $label,
                'key' => $payslip->bonus_type ? 'bonus_' . Str::slug($payslip->bonus_type) : 'bonus',
                'amount' => '₦' . number_format($payslip->bonus_amount, 2),
                'amount_raw' => (float) $payslip->bonus_amount,
            ]);
        }
        return $rows->values();
    }
    public function configure(Request $request)
    {
        $request->validate([
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['exists:users,id'],
        ]);

        $employerId = $request->user()->getEmployerId();
        $query = User::where('parent_id', $employerId)->staff()->where('is_active', true);

        if ($request->has('staff_ids')) {
            $query->whereIn('id', $request->staff_ids);
        }

        $staff = $query->get();
        $totalGross = $staff->sum('salary');

        $deductionTypes = DeductionType::forEmployer($employerId)->active()->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'default_amount' => (float) $d->default_amount,
                'is_percentage' => (bool) $d->is_percentage,
                'percentage_value' => $d->percentage_value ? (float) $d->percentage_value : null,
                'is_system' => (bool) $d->is_system,
                'system_key' => $d->system_key,
            ];
        })->values();

        $data = [
            'staff_count' => $staff->count(),
            'est_gross' => '₦' . number_format($totalGross, 2),
            'est_net' => '₦' . number_format($totalGross, 2),
            'deduction_types' => $deductionTypes,
            'raw_totals' => [
                'gross' => $totalGross,
                'net' => $totalGross,
            ]
        ];

        return $this->sendResponse($data, 'Payroll estimates retrieved successfully');
    }

    public function review(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $staffQuery = User::where('parent_id', $employerId)->staff()->where('is_active', true);
        $staff = $staffQuery->get()->keyBy('id');
        $deductionTypes = DeductionType::forEmployer($employerId)->active()->get();
        $typesByKey = $deductionTypes->keyBy('system_key');
        $typesById = $deductionTypes->keyBy('id');

        // If caller submitted explicit staff_data with edited deductions, prefer those.
        $submittedStaff = $request->input('staff_data');
        $useSubmitted = is_array($submittedStaff) && count($submittedStaff) > 0;

        $staffDetails = collect();
        $periodStart = $request->input('period_start');
        $periodEnd = $request->input('period_end');

        if ($useSubmitted) {
            foreach ($submittedStaff as $item) {
                $s = $staff->get($item['id'] ?? null);
                if (!$s) {
                    continue;
                }

                $grossForCalc = isset($item['gross_salary'])
                    ? (float) $item['gross_salary']
                    : (float) $s->salary;

                $rawDeductionRows = $item['deductions'] ?? [];

                $legacyDedAmount = isset($item['deduction_amount']) ? (float) $item['deduction_amount'] : 0;
                $legacyDedType = $item['deduction_type'] ?? null;
                FacadesLog::info('Review payroll staff_item', [
                    'staff_id' => $item['id'],
                    'received_deduction_amount' => $legacyDedAmount,
                    'received_deduction_type' => $legacyDedType,
                    'deductions_count' => count($rawDeductionRows),
                ]);

                if (empty($rawDeductionRows) && $legacyDedAmount > 0) {
                    $rawDeductionRows = [[
                        'name' => $legacyDedType && $legacyDedType !== 'none' ? $legacyDedType : 'Deduction',
                        'amount' => $legacyDedAmount,
                    ]];
                }

                $deductionRows = $rawDeductionRows;
                $normalized = [];
                $pensionEE = 0;
                $tax = 0;
                $nhf = 0;
                $otherDeds = 0;
                $deductionTotals = 0;

                foreach ($deductionRows as $row) {
                    $amt = (float) ($row['amount'] ?? 0);
                    $key = $row['key'] ?? null;
                    $typeId = $row['deduction_type_id'] ?? $row['type_id'] ?? null;
                    $name = $row['name'] ?? null;
                    if ($key === 'tax') {
                        $tax = $amt;
                        $name = $name ?? ($typesByKey->get('tax')?->name ?? 'PAYE Tax');
                    } elseif ($key === 'nhf') {
                        $nhf = $amt;
                        $name = $name ?? ($typesByKey->get('nhf')?->name ?? 'NHF');
                    } elseif ($key === 'pension_ee') {
                        $pensionEE = $amt;
                        $name = $name ?? ($typesByKey->get('pension_ee')?->name ?? 'Pension (Employee)');
                    } else {
                        $otherDeds += $amt;
                    }
                    $deductionTotals += $amt;

                    $dt = null;
                    if ($typeId) {
                        $dt = $typesById->get((int) $typeId);
                    }
                    if (!$dt && $key && ($kdt = $typesByKey->get($key))) {
                        $dt = $kdt;
                    }
                    $isPct = $row['is_percentage'] ?? ($dt ? (bool) $dt->is_percentage : false);
                    $pctVal = $row['percentage_value'] ?? ($dt ? $dt->percentage_value : null);

                    $normalized[] = [
                        'deduction_type_id' => $dt?->id ?? ($typeId ? (int) $typeId : null),
                        'name' => (string) ($name ?? $dt?->name ?? 'Deduction'),
                        'key' => $key,
                        'amount' => round($amt, 2),
                        'is_percentage' => (bool) $isPct,
                        'percentage_value' => $pctVal === null ? null : (float) $pctVal,
                        'editable' => !($dt?->is_system ?? ($key && in_array($key, ['tax','nhf','pension_ee','pension_er','nsitf','itf'], true))),
                    ];
                }

                $bonus = (float) ($item['bonus_amount'] ?? 0);
                $pensionER = 0;
                //round($grossForCalc * ((float) ($s->pension_employer_rate ?? 10) / 100), 2);
                $netPay = $grossForCalc + $bonus - $tax - $nhf - $pensionEE - $otherDeds;

                $staffDetails->push([
                    'id' => $s->id,
                    'name' => $s->name,
                    'gross' => '₦' . number_format($grossForCalc, 2),
                    'pension_ee' => '₦' . number_format($pensionEE, 2),
                    'pension_er' => '₦' . number_format($pensionER, 2),
                    'tax' => '₦' . number_format($tax, 2),
                    'nhf' => '₦' . number_format($nhf, 2),
                    'bonus' => '₦' . number_format($bonus, 2),
                    'advance_ded' => collect($normalized)->pluck('name')->contains(fn ($n) => is_string($n) && stripos($n, 'advance') !== false) ? 'YES' : 'NO',
                    'deductions' => $normalized,
                    'deductions_total' => round($deductionTotals, 2),
                    'net_pay' => '₦' . number_format(max($netPay, 0), 2),
                    'raw_net' => max($netPay, 0),
                    'raw_gross' => $grossForCalc,
                ]);
            }
        } else {
            $staffDetails = $staff->map(function ($s) use ($deductionTypes, $typesByKey) {
                $grossForCalc = (float) $s->salary;
                $pensionEE = round($grossForCalc * ((float) ($s->pension_employee_rate ?? 8) / 100), 2);
                $pensionER = round($grossForCalc * ((float) ($s->pension_employer_rate ?? 10) / 100), 2);
                $tax = (float) ($s->tax_deduction ?? 0);
                $nhf = (float) ($s->nhf ?? 0);

                $defaultDeductions = $deductionTypes->map(function ($d) use ($s, $grossForCalc, $pensionEE, $tax, $nhf) {
                    $amt = 0;
                    if ($d->is_system && $d->system_key === 'tax') {
                        $amt = $tax;
                    } elseif ($d->is_system && $d->system_key === 'nhf') {
                        $amt = $nhf;
                    } elseif ($d->is_system && $d->system_key === 'pension_ee') {
                        $amt = $pensionEE;
                    } elseif ($d->is_percentage) {
                        $pct = (float) ($d->percentage_value ?? 0);
                        $amt = $grossForCalc * ($pct / 100);
                    } else {
                        $amt = (float) ($d->default_amount ?? 0);
                    }
                    return [
                        'deduction_type_id' => $d->id,
                        'name' => $d->name,
                        'key' => $d->system_key,
                        'amount' => round($amt, 2),
                        'is_percentage' => (bool) $d->is_percentage,
                        'percentage_value' => $d->percentage_value ? (float) $d->percentage_value : null,
                        'editable' => !$d->is_system,
                    ];
                })->values();

                $deductionTotals = $defaultDeductions->sum('amount');
                $netPay = $grossForCalc - $deductionTotals;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'gross' => '₦' . number_format($grossForCalc, 2),
                    'pension_ee' => '₦' . number_format($pensionEE, 2),
                    'pension_er' => '₦' . number_format($pensionER, 2),
                    'tax' => '₦' . number_format($tax, 2),
                    'nhf' => '₦' . number_format($nhf, 2),
                    'bonus' => '₦' . number_format(0, 2),
                    'advance_ded' => 'NO',
                    'deductions' => $defaultDeductions,
                    'deductions_total' => round($deductionTotals, 2),
                    'net_pay' => '₦' . number_format(max($netPay, 0), 2),
                    'raw_net' => max($netPay, 0),
                    'raw_gross' => $grossForCalc,
                ];
            })->values();
        }

        $data = [
            'staff_payments' => $staffDetails->values(),
            'summary' => [
                'count' => $staffDetails->count(),
                'grand_total_net' => '₦' . number_format($staffDetails->sum('raw_net'), 2),
                'raw_total_net' => $staffDetails->sum('raw_net'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
        ];

        return $this->sendResponse($data, 'Payroll review details retrieved successfully');
    }

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

    public function store(Request $request, ?User $actingUser = null)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'pay_date' => 'required|date',
            'staff_data' => 'required|array',
            'staff_data.*.id' => 'required|exists:users,id',
            'staff_data.*.deductions' => ['nullable', 'array'],
            'staff_data.*.deductions.*.deduction_type_id' => ['nullable', 'exists:deduction_types,id'],
            'staff_data.*.deductions.*.type_id' => ['nullable', 'exists:deduction_types,id'],
            'staff_data.*.deductions.*.name' => ['required_with:staff_data.*.deductions', 'string', 'max:255'],
            'staff_data.*.deductions.*.amount' => ['required_with:staff_data.*.deductions', 'numeric', 'min:0'],
            'staff_data.*.bonus_type' => ['nullable', 'string'],
            'staff_data.*.bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'staff_data.*.gross_salary' => ['nullable', 'numeric', 'min:0'],
            'staff_data.*.deduction_type' => ['nullable', 'string'],
            'staff_data.*.deduction_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $actingUser ?? $request->user();
        if (!$user) {
            return $this->sendError('Unauthenticated.', [], 401);
        }
        $employerId = $user->getEmployerId();
        $employer = User::with('wallet')->find($employerId);
        $wallet = $employer->wallet;

        return DB::transaction(function () use ($request, $employer, $wallet) {
            $totalNetToPay = 0;
            $staffCount = 0;
            $totalGross = 0;
            $payslips = [];

            $payroll = Payroll::create([
                'reference' => $this->generatePayrollReference(),
                'user_id' => $employer->id,
                'description' => now()->format('F Y') . ' Salary',
                'amount' => 0,
                'staff_count' => 0,
                'status' => Payroll::STATUS_PENDING,
                'processed_at' => $request->pay_date,
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
            ]);

            foreach ($request->staff_data as $item) {
                $staff = User::find($item['id']);
                if ($staff->parent_id !== $employer->id) {
                    continue;
                }

                $grossForCalc = isset($item['gross_salary'])
                    ? (float) $item['gross_salary']
                    : (float) $staff->salary;

                $rawDeductionRows = $item['deductions'] ?? [];

                $legacyDedAmount = isset($item['deduction_amount']) ? (float) $item['deduction_amount'] : 0;
                $legacyDedType = $item['deduction_type'] ?? null;
                FacadesLog::info('Payroll staff_item', [
                    'staff_id' => $item['id'],
                    'received_deduction_amount' => $legacyDedAmount,
                    'received_deduction_type' => $legacyDedType,
                    'deductions_count' => count($rawDeductionRows),
                ]);

                if (empty($rawDeductionRows) && $legacyDedAmount > 0) {
                    $rawDeductionRows = [[
                        'name' => $legacyDedType && $legacyDedType !== 'none' ? $legacyDedType : 'Deduction',
                        'amount' => $legacyDedAmount,
                    ]];
                }

                $deductionRows = collect($rawDeductionRows)->map(function ($row) {
                    $mapped = $row;
                    if (empty($mapped['deduction_type_id']) && !empty($mapped['type_id'])) {
                        $mapped['deduction_type_id'] = $mapped['type_id'];
                    } elseif (empty($mapped['type_id']) && !empty($mapped['deduction_type_id'])) {
                        $mapped['type_id'] = $mapped['deduction_type_id'];
                    }
                    return $mapped;
                })->all();

                $deductionTotals = 0;
                $pensionEE = 0;
                $tax = 0;
                $nhf = 0;
                $otherDeds = 0;

                foreach ($deductionRows as $row) {
                    $amt = (float) ($row['amount'] ?? 0);
                    $key = $row['key'] ?? null;
                    if ($key === 'tax') {
                        $tax = $amt;
                    } elseif ($key === 'nhf') {
                        $nhf = $amt;
                    } elseif ($key === 'pension_ee') {
                        $pensionEE = $amt;
                    } else {
                        $otherDeds += $amt;
                    }
                    $deductionTotals += $amt;
                }

                $bonus = (float) ($item['bonus_amount'] ?? 0);
                $pensionER = round($grossForCalc * ((float) ($staff->pension_employer_rate ?? 10) / 100), 2);
                $netPay = $grossForCalc + $bonus - $pensionEE - $tax - $nhf - $otherDeds;

                $legacyOtherDeductions = 0;
                foreach ($deductionRows as $row) {
                    $key = $row['key'] ?? null;
                    if (!in_array($key, ['tax', 'nhf', 'pension_ee'], true)) {
                        $legacyOtherDeductions += (float) ($row['amount'] ?? 0);
                    }
                }
                $legacyDeductionType = collect($deductionRows)->pluck('name')->filter()->implode(', ');

                $payslip = Payslip::create([
                    'reference' => $this->generatePayslipReference(),
                    'user_id' => $staff->id,
                    'payroll_id' => $payroll->id,
                    'period' => $payroll->period_start->format('M Y'),
                    'gross_salary' => $grossForCalc,
                    'pension_employee' => $pensionEE,
                    'pension_employer' => $pensionER,
                    'tax_deduction' => $tax,
                    'nhf' => $nhf,
                    'other_deductions' => $legacyOtherDeductions,
                    'deduction_type' => $legacyDeductionType ?: null,
                    'bonus_type' => $item['bonus_type'] ?? null,
                    'bonus_amount' => $bonus,
                    'net_salary' => $netPay,
                    'status' => Payslip::STATUS_PENDING,
                ]);

                foreach ($deductionRows as $row) {
                    $payslip->deductions()->create([
                        'deduction_type_id' => $row['deduction_type_id'] ?? null,
                        'deduction_name' => $row['name'],
                        'deduction_key' => $row['key'] ?? null,
                        'amount' => (float) ($row['amount'] ?? 0),
                        'is_percentage' => !empty($row['is_percentage']),
                        'percentage_applied' => $row['percentage_value'] ?? null,
                        'base_amount' => $grossForCalc,
                        'notes' => $row['notes'] ?? null,
                    ]);
                }

                $payslips[] = $payslip;
                $totalNetToPay += $netPay;
                $totalGross += $grossForCalc;
                $staffCount++;
            }

            FacadesLog::info('Total net to pay: ' . $totalNetToPay);
            FacadesLog::info('Total gross salary: ' . $totalGross);
            FacadesLog::info('Total staff count: ' . $staffCount);
            FacadesLog::info('Wallet balance: ' . $wallet->balance);

            if ($wallet->balance < $totalNetToPay) {
                throw new \Exception("Insufficient wallet balance to complete payroll.");
            }

            $payroll->update([
                'amount' => $totalNetToPay,
                'staff_count' => $staffCount,
            ]);

            $payroll->load('payslips.deductions');

            $data = [
                'id' => $payroll->id,
                'reference' => $payroll->reference,
                'description' => $payroll->description,
                'amount' => (float) $payroll->amount,
                'staff_count' => $payroll->staff_count,
                'status' => $payroll->status,
                'processed_at' => $payroll->processed_at?->toISOString(),
                'period_start' => $payroll->period_start?->format('Y-m-d'),
                'period_end' => $payroll->period_end?->format('Y-m-d'),
                'payslips' => $payroll->payslips->map(function ($payslip) {
                    $breakdown = $this->buildLegacyAwareDeductionBreakdown($payslip);
                    return [
                        'id' => $payslip->id,
                        'reference' => $payslip->reference,
                        'user_id' => $payslip->user_id,
                        'net_salary' => (float) $payslip->net_salary,
                        'status' => $payslip->status,
                        'deductions' => $breakdown->map(function ($r) {
                            return [
                                'id' => $r['id'],
                                'name' => $r['name'],
                                'key' => $r['key'],
                                'amount' => (float) ($r['amount_raw'] ?? 0),
                            ];
                        })->values(),
                        'deduction_breakdown' => $breakdown->all(),
                        'bonus_breakdown' => $this->buildBonusBreakdown($payslip)->all(),
                    ];
                })->values(),
            ];

            return $this->sendResponse($data, 'Payroll processed successfully', true, 201);
        });
    }

    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $payrolls = Payroll::where('user_id', $employerId)
            ->orderBy('processed_at', 'desc')
            ->get();

        $data = $payrolls->map(function ($p) {
            return [
                'id' => $p->id,
                'reference' => $p->reference,
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
        $payroll = Payroll::where('user_id', $employerId)
            ->with(['payslips.user', 'payslips.deductions'])
            ->findOrFail($id);

        $buildBreakdown = function ($p) {
            return $this->buildLegacyAwareDeductionBreakdown($p);
        };

        $buildBonusBreakdown = function ($p) {
            return $this->buildBonusBreakdown($p);
        };

        $allBreakdowns = $payroll->payslips->flatMap(function ($p) use ($buildBreakdown) {
            return $buildBreakdown($p);
        });
        $totalDeductions = $allBreakdowns->sum('amount_raw');
        $totalGross = $payroll->payslips->sum('gross_salary');

        $deductionSummary = $allBreakdowns->groupBy('name')->map(function ($rows, $name) {
            return [
                'name' => $name,
                'key' => $rows->first()['key'] ?? null,
                'total' => (float) $rows->sum('amount_raw'),
                'count' => $rows->count(),
            ];
        })->values()->sortByDesc('total')->values()->all();

        $data = [
            'id' => $payroll->id,
            'reference' => $payroll->reference,
            'period' => $payroll->period_start->format('d M') . ' — ' . $payroll->period_end->format('d M Y'),
            'status' => $payroll->status,
            'summary' => [
                'staff_count' => $payroll->staff_count,
                'total_gross' => '₦' . number_format($totalGross, 2),
                'total_deductions' => '₦' . number_format($totalDeductions, 2),
                'net_disbursement' => '₦' . number_format($payroll->amount, 2),
            ],
            'deduction_summary' => $deductionSummary,
            'staff_payments' => $payroll->payslips->map(function ($p) use ($buildBreakdown, $buildBonusBreakdown) {
                $deductionRows = $buildBreakdown($p);
                $bonusRows = $buildBonusBreakdown($p);
                $otherRaw = $deductionRows->whereNotIn('key', ['tax', 'nhf', 'pension_ee'])->sum('amount_raw');
                $otherDed = $otherRaw > 0 ? '₦' . number_format($otherRaw, 2) : 'NO';
                $otherType = $deductionRows->whereNotIn('key', ['tax', 'nhf', 'pension_ee'])->pluck('name')->filter()->implode(', ');
                return [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'name' => $p->user->name,
                    'bank' => $p->user->bank_name ?? '-',
                    'account' => $p->user->account_number ?? '-',
                    'gross' => '₦' . number_format($p->gross_salary, 2),
                    'pension_ee' => '₦' . number_format($p->pension_employee, 2),
                    'pension_er' => '₦' . number_format($p->pension_employer, 2),
                    'tax' => '₦' . number_format($p->tax_deduction, 2),
                    'nhf' => '₦' . number_format($p->nhf, 2),
                    'deductions' => $otherDed,
                    'deduction_type' => $otherType ?: null,
                    'bonus_amount' => $p->bonus_amount > 0 ? '₦' . number_format($p->bonus_amount, 2) : 'NO',
                    'bonus_type' => $p->bonus_type,
                    'advance_ded' => 'NO',
                    'deduction_breakdown' => $deductionRows->all(),
                    'bonus_breakdown' => $bonusRows->all(),
                    'net_pay' => '₦' . number_format($p->net_salary, 2),
                    'status' => $p->status,
                ];
            }),
        ];

        return $this->sendResponse($data, 'Payroll details retrieved successfully');
    }

    public function downloadPayslip($id)
    {
        $payslip = \App\Models\Payslip::with(['user.parent', 'deductions'])->findOrFail($id);
        $payroll = Payroll::find($payslip->payroll_id);
        $user = User::find($payroll->user_id);
        $companyName = $user->company_name;
        $deductionBreakdown = $this->buildLegacyAwareDeductionBreakdown($payslip);
        $bonusBreakdown = $this->buildBonusBreakdown($payslip);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payslip', compact('payslip', 'companyName', 'deductionBreakdown', 'bonusBreakdown'));
        return $pdf->download('payslip-' . $payslip->period . '.pdf');
    }

    public function downloadSample()
    {
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=payroll-upload-sample.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'employee_name',
                'email',
                'account_name',
                'account_number',
                'bank_name',
                'gross_salary',
                'tax_deduction',
                'pension_employee',
                'nhf',
                'loan_deduction',
                'hmo_deduction',
                'other_deductions',
                'bonus_amount',
                'notes',
            ]);
            fputcsv($handle, [
                'Jane Doe',
                'jane.doe@example.com',
                'Jane Doe',
                '0123456789',
                'Guaranty Trust Bank',
                350000.00,
                17500.00,
                28000.00,
                8750.00,
                15000.00,
                5000.00,
                0.00,
                25000.00,
                'Q2 performance bonus',
            ]);
            fputcsv($handle, [
                'John Staff',
                'john.staff@example.com',
                'John Staff',
                '9876543210',
                'Zenith Bank',
                250000.00,
                12500.00,
                20000.00,
                6250.00,
                0.00,
                5000.00,
                2000.00,
                0.00,
                '',
            ]);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function uploadPreview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:5120'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'pay_date' => ['required', 'date'],
        ]);

        $employerId = $request->user()->getEmployerId();

        try {
            $collection = Excel::toCollection(new \stdClass(), $request->file('file'));
        } catch (\Throwable $e) {
            return $this->sendError('Could not read the uploaded file: ' . $e->getMessage(), null, 422);
        }

        if ($collection->isEmpty() || $collection->first()->isEmpty()) {
            return $this->sendError('The uploaded file is empty.', null, 422);
        }

        $rows = $collection->first();
        $header = array_map('strtolower', array_map('trim', array_values($rows->first()->toArray())));
        $dataRows = $rows->skip(1)->values();

        $keys = [
            'name' => $this->findHeader($header, ['employee_name', 'name', 'staff_name', 'full_name']),
            'email' => $this->findHeader($header, ['email', 'staff_email', 'employee_email']),
            'account_name' => $this->findHeader($header, ['account_name', 'acct_name']),
            'account_number' => $this->findHeader($header, ['account_number', 'acct_number', 'account_no']),
            'bank_name' => $this->findHeader($header, ['bank_name', 'bank']),
            'gross_salary' => $this->findHeader($header, ['gross_salary', 'gross', 'salary']),
            'tax' => $this->findHeader($header, ['tax_deduction', 'tax', 'paye']),
            'pension_ee' => $this->findHeader($header, ['pension_employee', 'pension_ee', 'pension']),
            'nhf' => $this->findHeader($header, ['nhf']),
            'loan' => $this->findHeader($header, ['loan_deduction', 'loan', 'salary_advance', 'advance_deduction']),
            'hmo' => $this->findHeader($header, ['hmo_deduction', 'hmo', 'health']),
            'other' => $this->findHeader($header, ['other_deductions', 'other_deduction', 'other']),
            'bonus' => $this->findHeader($header, ['bonus_amount', 'bonus']),
            'notes' => $this->findHeader($header, ['notes', 'note', 'remark', 'remarks']),
        ];

        if ($keys['name'] === null || $keys['gross_salary'] === null) {
            return $this->sendError('Required columns are missing. The file must include employee_name and gross_salary.', null, 422);
        }

        $existingStaff = User::where('parent_id', $employerId)->staff()->get();
        $byEmail = $existingStaff->keyBy(fn ($s) => strtolower(trim($s->email)));
        $byName = $existingStaff->keyBy(fn ($s) => strtolower(trim($s->name)));
        $byAccount = $existingStaff->keyBy(fn ($s) => trim($s->account_number ?? '') . '|' . strtolower(trim($s->account_name ?? '')));

        $sessionReference = 'PRLUP-' . Str::upper(Str::random(10));

        $cleanRows = [];
        $flaggedRows = [];
        $flagRecords = [];

        foreach ($dataRows as $idx => $row) {
            $cells = array_values($row->toArray());
            $cell = function ($idx) use ($cells) {
                return $idx === null ? null : ($cells[$idx] ?? null);
            };

            $name = trim((string) $cell($keys['name']));
            $email = trim((string) $cell($keys['email']));
            $accountName = trim((string) $cell($keys['account_name']));
            $accountNumber = trim((string) $cell($keys['account_number']));
            $bankName = trim((string) $cell($keys['bank_name']));
            $gross = (float) $cell($keys['gross_salary']);
            $tax = (float) ($cell($keys['tax']) ?? 0);
            $pensionEE = (float) ($cell($keys['pension_ee']) ?? 0);
            $nhf = (float) ($cell($keys['nhf']) ?? 0);
            $loan = (float) ($cell($keys['loan']) ?? 0);
            $hmo = (float) ($cell($keys['hmo']) ?? 0);
            $other = (float) ($cell($keys['other']) ?? 0);
            $bonus = (float) ($cell($keys['bonus']) ?? 0);
            $notes = (string) $cell($keys['notes']);

            if (empty($name) && empty($email) && $gross <= 0) {
                continue;
            }

            $rowData = [
                'employee_name' => $name,
                'email' => $email,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'bank_name' => $bankName,
                'gross_salary' => $gross,
                'tax_deduction' => $tax,
                'pension_employee' => $pensionEE,
                'nhf' => $nhf,
                'loan_deduction' => $loan,
                'hmo_deduction' => $hmo,
                'other_deductions' => $other,
                'bonus_amount' => $bonus,
                'notes' => $notes,
            ];

            $flags = [];
            $matchedStaff = null;

            if (!empty($email) && $byEmail->has(strtolower($email))) {
                $matchedStaff = $byEmail->get(strtolower($email));
            } elseif (!empty($name) && $byName->has(strtolower($name))) {
                $matchedStaff = $byName->get(strtolower($name));
            }

            $netComputed = $gross - $tax - $pensionEE - $nhf - $loan - $hmo - $other + $bonus;

            if (!$matchedStaff) {
                $flags[] = 'new_staff';
            } else {
                if (strtolower(trim($matchedStaff->name ?? '')) !== strtolower($name)) {
                    $flags[] = 'name_mismatch';
                }
                $acctKey = trim($matchedStaff->account_number ?? '') . '|' . strtolower(trim($matchedStaff->account_name ?? ''));
                $fileKey = $accountNumber . '|' . strtolower($accountName);
                if (!empty($accountNumber) && !empty($accountName) && $acctKey !== $fileKey) {
                    $flags[] = 'account_mismatch';
                }
            }

            if ($gross <= 0) {
                $flags[] = 'invalid_amount';
            }
            if ($netComputed < 0) {
                $flags[] = 'invalid_amount';
            }

            $normalized = [
                'staff_id' => $matchedStaff?->id,
                'name' => $matchedStaff?->name ?: $name,
                'employee_name' => $matchedStaff?->name ?: $name,
                'email' => $matchedStaff?->email ?: $email,
                'account_name' => $matchedStaff?->account_name ?: $accountName,
                'account_number' => $matchedStaff?->account_number ?: $accountNumber,
                'bank_name' => $matchedStaff?->bank_name ?: $bankName,
                'gross_salary' => $gross,
                'tax_deduction' => $tax,
                'pension_employee' => $pensionEE,
                'nhf' => $nhf,
                'loan_deduction' => $loan,
                'hmo_deduction' => $hmo,
                'other_deductions' => $other,
                'bonus_amount' => $bonus,
                'net_pay' => max($netComputed, 0),
                'net_salary' => max($netComputed, 0),
                'notes' => $notes,
            ];

            if (empty($flags)) {
                $cleanRows[] = array_merge($normalized, ['row_index' => $idx]);
            } else {
                $flaggedRow = array_merge($normalized, [
                    'row_index' => $idx,
                    'flags' => $flags,
                    'matched_staff_id' => $matchedStaff?->id,
                ]);
                $flaggedRows[] = $flaggedRow;

                $flagRecords[] = [
                    'user_id' => $employerId,
                    'session_reference' => $sessionReference,
                    'row_index' => $idx,
                    'row_data' => json_encode($rowData),
                    'flags' => json_encode($flags),
                    'matched_staff' => $matchedStaff ? json_encode(['id' => $matchedStaff->id, 'name' => $matchedStaff->name, 'email' => $matchedStaff->email]) : null,
                    'gross_amount' => $gross,
                    'net_amount' => max($netComputed, 0),
                    'status' => PayrollUploadFlag::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($flagRecords)) {
            DB::table('payroll_upload_flags')->insert($flagRecords);
        }

        $cleanTotalNet = collect($cleanRows)->sum('net_pay');
        $cleanTotalGross = collect($cleanRows)->sum('gross_salary');
        $flaggedTotalNet = collect($flaggedRows)->sum('net_pay');

        $response = [
            'session_reference' => $sessionReference,
            'period' => [
                'start' => $request->period_start,
                'end' => $request->period_end,
                'pay_date' => $request->pay_date,
            ],
            'clean_rows' => $cleanRows,
            'flagged_rows' => $flaggedRows,
            'totals' => [
                'rows_total' => count($cleanRows) + count($flaggedRows),
                'clean_count' => count($cleanRows),
                'flagged_count' => count($flaggedRows),
                'flag_breakdown' => collect($flaggedRows)->flatMap->flags->countBy()->all(),
                'clean_gross' => round($cleanTotalGross, 2),
                'clean_net' => round($cleanTotalNet, 2),
                'flagged_net' => round($flaggedTotalNet, 2),
            ],
        ];

        return $this->sendResponse($response, 'File validated');
    }

    public function uploadCommit(Request $request)
    {
        $request->validate([
            'session_reference' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'pay_date' => ['required', 'date'],
            'clean_rows' => ['required', 'array'],
            'clean_rows.*.staff_id' => ['required', 'exists:users,id'],
            'clean_rows.*.gross_salary' => ['required', 'numeric', 'min:0'],
        ]);

        $employerId = $request->user()->getEmployerId();

        $payrollRequest = new Request([
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,
            'pay_date' => $request->pay_date,
            'staff_data' => collect($request->clean_rows)->map(function ($row) {
                $deductions = [];
                if (!empty($row['tax_deduction'])) {
                    $deductions[] = ['name' => 'PAYE / Income Tax', 'key' => 'tax', 'amount' => $row['tax_deduction']];
                }
                if (!empty($row['pension_employee'])) {
                    $deductions[] = ['name' => 'Pension (Employee)', 'key' => 'pension_ee', 'amount' => $row['pension_employee']];
                }
                if (!empty($row['nhf'])) {
                    $deductions[] = ['name' => 'NHF', 'key' => 'nhf', 'amount' => $row['nhf']];
                }
                if (!empty($row['loan_deduction'])) {
                    $deductions[] = ['name' => 'Company Loan', 'key' => 'loan', 'amount' => $row['loan_deduction']];
                }
                if (!empty($row['hmo_deduction'])) {
                    $deductions[] = ['name' => 'HMO / Health Insurance', 'key' => 'hmo', 'amount' => $row['hmo_deduction']];
                }
                if (!empty($row['other_deductions'])) {
                    $deductions[] = ['name' => 'Other', 'key' => 'other', 'amount' => $row['other_deductions']];
                }
                return [
                    'id' => $row['staff_id'],
                    'gross_salary' => $row['gross_salary'] ?? 0,
                    'deductions' => $deductions,
                    'bonus_amount' => $row['bonus_amount'] ?? 0,
                    'bonus_type' => null,
                ];
            })->all(),
        ]);

        $response = $this->store($payrollRequest, $request->user());

        $payload = $response->getData(true);
        $payrollId = $payload['data']['id'] ?? null;
        if ($payrollId) {
            PayrollUploadFlag::where('user_id', $employerId)
                ->where('session_reference', $request->session_reference)
                ->update(['payroll_id' => $payrollId]);
        }

        return $response;
    }

    public function flaggedRows(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $query = PayrollUploadFlag::forEmployer($employerId)->orderBy('created_at', 'desc');

        if ($request->filled('session_reference')) {
            $query->where('session_reference', $request->session_reference);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('flag')) {
            $flag = $request->flag;
            $query->whereRaw('JSON_CONTAINS(flags, ?)', [json_encode($flag)]);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('row_data', 'like', $search)
                    ->orWhereHas('employer', function ($u) use ($search) {
                        $u->where('name', 'like', $search);
                    });
            });
        }

        $perPage = (int) $request->input('per_page', $request->input('page') ? 15 : 0);
        if ($perPage > 0) {
            $paginator = $query->paginate($perPage);
            $items = $paginator->getCollection();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        } else {
            $items = $query->get();
            $pagination = [
                'current_page' => 1,
                'per_page' => $items->count(),
                'total' => $items->count(),
                'last_page' => 1,
            ];
        }

        $rows = $items->map(function ($f) {
            return [
                'id' => $f->id,
                'session_reference' => $f->session_reference,
                'row_index' => $f->row_index,
                'row_data' => $f->row_data,
                'flags' => $f->flags,
                'matched_staff' => $f->matched_staff,
                'gross_amount' => (float) $f->gross_amount,
                'net_amount' => (float) $f->net_amount,
                'status' => $f->status,
                'review_notes' => $f->review_notes,
                'reviewed_by' => $f->reviewed_by,
                'reviewed_at' => $f->reviewed_at?->toISOString(),
                'created_at' => $f->created_at?->toISOString(),
            ];
        })->values();

        $flagTypes = PayrollUploadFlag::forEmployer($employerId)
            ->pluck('flags')
            ->flatMap(fn ($arr) => $arr ?? [])
            ->countBy()
            ->map(fn ($count, $key) => ['flag' => $key, 'count' => $count])
            ->values();

        return $this->sendResponse([
            'rows' => $rows,
            'pagination' => $pagination,
            'filters' => [
                'statuses' => [PayrollUploadFlag::STATUS_PENDING, PayrollUploadFlag::STATUS_APPROVED, PayrollUploadFlag::STATUS_REJECTED, PayrollUploadFlag::STATUS_APPLIED],
                'flag_types' => $flagTypes,
            ],
        ], 'Flagged rows retrieved');
    }

    public function approveFlagged(Request $request, PayrollUploadFlag $flag)
    {
        $employerId = $request->user()->getEmployerId();
        $actor = $request->user();

        if ($flag->user_id !== $employerId) {
            return $this->sendError('Not found.', null, 404);
        }

        if (!$this->userCanApprove($actor)) {
            return $this->sendError('You do not have permission to approve flagged payroll rows.', null, 403);
        }

        $request->validate([
            'review_notes' => ['nullable', 'string', 'max:1000'],
            'mapped_staff_id' => ['nullable', 'exists:users,id'],
        ]);

        $mappedStaffId = $request->mapped_staff_id;
        if ($mappedStaffId) {
            $staff = User::where('id', $mappedStaffId)->where('parent_id', $employerId)->staff()->first();
            if (!$staff) {
                return $this->sendError('Mapped staff ID does not belong to this employer.', null, 422);
            }
            $rowData = $flag->row_data ?? [];
            $rowData['resolved_staff_id'] = $staff->id;
            $matchedStaff = [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'resolved_by' => $actor->id,
            ];
            $flag->row_data = $rowData;
            $flag->matched_staff = $matchedStaff;
        }

        $flag->update([
            'status' => PayrollUploadFlag::STATUS_APPROVED,
            'review_notes' => $request->review_notes,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $this->sendResponse($flag, 'Flagged row approved');
    }

    public function rejectFlagged(Request $request, PayrollUploadFlag $flag)
    {
        $employerId = $request->user()->getEmployerId();
        $actor = $request->user();

        if ($flag->user_id !== $employerId) {
            return $this->sendError('Not found.', null, 404);
        }

        if (!$this->userCanApprove($actor)) {
            return $this->sendError('You do not have permission to reject flagged payroll rows.', null, 403);
        }

        $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        $flag->update([
            'status' => PayrollUploadFlag::STATUS_REJECTED,
            'review_notes' => $request->review_notes,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $this->sendResponse($flag, 'Flagged row rejected');
    }

    private function userCanApprove(User $user): bool
    {
        $employerId = $user->getEmployerId();
        if ((int) $user->id === (int) $employerId) {
            return true;
        }
        if ($user->hasRole('admin') || $user->hasRole('finance_manager')) {
            return true;
        }
        try {
            if ($user->hasPermissionTo('approve_flagged_payroll')) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return (bool) ($user->is_finance_admin ?? $user->is_admin ?? false);
    }

    private function findHeader(array $header, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $idx = array_search(strtolower($c), $header, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }
        return null;
    }

    private function generatePayrollReference(): string
    {
        do {
            $reference = 'PRL-' . Str::upper(Str::random(10));
        } while (Payroll::where('reference', $reference)->exists());
        return $reference;
    }

    private function generatePayslipReference(): string
    {
        do {
            $reference = 'PSL-' . Str::upper(Str::random(10));
        } while (Payslip::where('reference', $reference)->exists());
        return $reference;
    }
}
