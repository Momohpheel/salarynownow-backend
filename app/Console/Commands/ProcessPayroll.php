<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Mail\PayslipMail;
use App\Mail\PayrollCompleted;
use App\Models\FeeConfig;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Notification;
use App\Models\Transaction;
use App\Services\Sarepay\SarepayService;
use App\Traits\ResolvesFeeConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Signature('app:process-payroll')]
#[Description('Process and disburse salaries for scheduled payrolls')]
class ProcessPayroll extends Command
{
    use ResolvesFeeConfig;

    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        parent::__construct();
        $this->sarepayService = $sarepayService;
    }

    public function handle()
    {
        $this->info('Checking for payrolls to disburse...');
        $bankCodeLookup = $this->buildBankCodeLookup();

        $payrolls = Payroll::whereIn('status', [
                Payroll::STATUS_PENDING,
                Payroll::STATUS_PROCESSING,
            ])
            ->whereDate('processed_at', '<=', now())
            ->get();

        if ($payrolls->isEmpty()) {
            $this->info('No pending or processing payrolls for today.');
            return;
        }

        foreach ($payrolls as $payroll) {
            $employer = $payroll->user;
            $employerName = $employer?->company_name ?? 'Unknown Employer';
            if (! $employer) {
                $this->warn("Skipping payroll ID: {$payroll->id} — no employer user (orphan record).");
                try {
                    $payroll->update([
                        'status' => Payroll::STATUS_FAILED,
                    ]);
                } catch (\Throwable) {
                }
                continue;
            }
            $this->info("Processing payroll ID: {$payroll->id} for employer: {$employerName}");

            $payroll->update(['status' => Payroll::STATUS_PROCESSING]);
            $employerWallet = $employer->wallet;
            $availableBalance = (float) ($employerWallet?->balance ?? 0);
            $hasFailures = false;
            $hasPayslipFailureReasonCol = Schema::hasColumn('payslips', 'failure_reason');
            $hasPayslipFailureCodeCol = Schema::hasColumn('payslips', 'failure_code');
            $failureSummary = [
                'total' => 0,
                'by_code' => [
                    'wallet_missing' => 0,
                    'insufficient_balance' => 0,
                    'bank_code_missing' => 0,
                    'api_error' => 0,
                    'staff_missing' => 0,
                    'unknown' => 0,
                ],
                'failed_payslips' => [],
            ];

            $payslips = Payslip::where('payroll_id', $payroll->id)
               ->where('status', Payslip::STATUS_PENDING)
                ->get();

            foreach ($payslips as $payslip) {
                $staff = $payslip->user;
                $reference = 'SAL-' . Str::upper(Str::random(10));
                $netSalary = (float) $payslip->net_salary;

                if (! $staff) {
                    $hasFailures = true;
                    $this->error("Payslip #{$payslip->id} has no linked staff user — skipping disbursement.");
                    $this->recordPayslipFailure(
                        $payslip,
                        $reference,
                        $netSalary,
                        'No staff record attached to this payslip.',
                        'staff_missing',
                        $failureSummary
                    );
                    continue;
                }

                $this->info("Initiating transfer of ₦" . number_format($netSalary, 2) . " to {$staff->name} ({$staff->account_number})");

                try {
                    if (! $employerWallet) {
                        $this->error("Employer wallet not found for {$payroll->user->name}");
                        throw new \Exception("Employer wallet not found.", 1001);
                    }

                    $feeResolution = $this->resolveAndComputeFee(
                        $employer,
                        FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
                        $netSalary
                    );
                    $feeAmount = (float) ($feeResolution['amount'] ?? 0.0);

                    $totalDeduction = $netSalary + $feeAmount;

                    $this->info(sprintf(
                        '  Fee: ₦%s (scope=%s calc=%s) — wallet deduction: ₦%s = ₦%s (net) + ₦%s (fee)',
                        number_format($feeAmount, 2),
                        $feeResolution['scope_label'] ?? 'none',
                        $feeResolution['calculation_label'] ?? 'none',
                        number_format($totalDeduction, 2),
                        number_format($netSalary, 2),
                        number_format($feeAmount, 2)
                    ));

                    if ($availableBalance < $totalDeduction) {
                        $need = number_format($totalDeduction, 2);
                        $have = number_format($availableBalance, 2);
                        $shortfall = number_format($totalDeduction - $availableBalance, 2);
                        $this->error("Insufficient employer wallet balance for {$payroll->user->name} to cover salary and charges");
                        throw new \Exception(
                            "Insufficient wallet balance. Need ₦{$need} (salary + fees), have ₦{$have} — shortfall ₦{$shortfall}.",
                            1002
                        );
                    }

                    $bankCode = $this->resolveBankCode($staff->bank_name, $bankCodeLookup);

                    if (! $bankCode) {
                        $this->error("Bank code not found for {$staff->bank_name}");
                        throw new \Exception(
                            "Bank code not found for bank: {$staff->bank_name}. Please update the bank name to a recognised one (e.g. \"Access Bank\", \"GTBank\", \"UBA\") and retry.",
                            1003
                        );
                    }

                    try {
                        $response = $this->sarepayService->transfer(
                            $reference,
                            $staff->account_number,
                            $bankCode,
                            $netSalary,
                            "Salary for {$employerName} - {$payroll->description}"
                        );
                    } catch (\Throwable $apiE) {
                        $msg = trim($apiE->getMessage()) !== ''
                            ? $apiE->getMessage()
                            : 'Payment provider call failed without a message.';
                        throw new \Exception($msg, 1004);
                    }

                    if (is_array($response)) {
                        $rawStatus = $response['data']['status']
                            ?? $response['status']
                            ?? $response['data_status']
                            ?? null;
                        $rawMessage = $response['data']['message']
                            ?? $response['message']
                            ?? $response['data']['failure_reason']
                            ?? $response['failure_reason']
                            ?? null;
                    } elseif (is_object($response)) {
                        $rawStatus = data_get($response, 'data.status')
                            ?? data_get($response, 'status')
                            ?? data_get($response, 'data_status')
                            ?? null;
                        $rawMessage = data_get($response, 'data.message')
                            ?? data_get($response, 'message')
                            ?? data_get($response, 'data.failure_reason')
                            ?? data_get($response, 'failure_reason')
                            ?? null;
                    } else {
                        $rawStatus = null;
                        $rawMessage = null;
                    }
                    $transferStatus = is_string($rawStatus) && $rawStatus !== ''
                        ? strtolower($rawStatus)
                        : Transaction::STATUS_PENDING;

                    Transaction::create([
                        'user_id' => $staff->id,
                        'payroll_id' => $payroll->id,
                        'payslip_id' => $payslip->id,
                        'reference' => $reference,
                        'amount' => $netSalary,
                        'status' => $transferStatus,
                        'response_message' => is_string($rawMessage) && $rawMessage !== '' ? $rawMessage : null,
                        'metadata' => (array) $response,
                    ]);

                    if ($transferStatus === Transaction::STATUS_FAILED) {
                        $failReason = is_string($rawMessage) && $rawMessage !== ''
                            ? $rawMessage
                            : 'Payment provider returned a failed status with no additional reason.';
                        throw new \Exception($failReason, 1004);
                    }

                    $balanceBefore = (float) $employerWallet->balance;
                    $employerWallet->decrement('balance', $totalDeduction);
                    $employerWallet->refresh();

                    $employerWallet->logs()->create([
                        'amount' => $netSalary,
                        'type' => 'debit',
                        'description' => "Salary payment for {$staff->name} — {$employerName} ({$payroll->description})",
                        'balance_before' => $balanceBefore,
                        'balance_after' => (float) $employerWallet->balance,
                        'metadata' => [
                            'payroll_id' => $payroll->id,
                            'payslip_id' => $payslip->id,
                            'transaction_reference' => $reference,
                            'charge_amount' => $feeAmount,
                            'fee_scope_label' => $feeResolution['scope_label'] ?? null,
                            'fee_calculation_label' => $feeResolution['calculation_label'] ?? null,
                            'fee_breakdown' => $feeResolution['breakdown'] ?? null,
                            'principal_amount' => $netSalary,
                            'total_deduction' => $totalDeduction,
                        ],
                    ]);

                    $availableBalance = (float) $employerWallet->balance;

                    $resetPayload = ['status' => Payslip::STATUS_DISBURSED];
                    if ($hasPayslipFailureReasonCol) $resetPayload['failure_reason'] = null;
                    if ($hasPayslipFailureCodeCol) $resetPayload['failure_code'] = null;
                    $payslip->update($resetPayload);
                    $payslip->load('user');

                    if ($payslip->user?->email) {
                        Mail::to($payslip->user->email)->send(new PayslipMail($payslip));
                    }

                    try {
                        if ($payslip->user) {
                            $net = (float)$payslip->net_salary;
                            Notification::notify($payslip->user, [
                                'category' => 'payroll',
                                'type' => 'payslip_disbursed',
                                'title' => 'Salary paid',
                                'body' => "Your salary for {$payroll->period_label} has been disbursed — ₦" . number_format($net, 2),
                                'icon' => 'banknote',
                                'deep_link' => '/my-pay/payslips/' . ($payslip->id ?? ''),
                                'metadata' => [
                                    'payroll_id' => $payroll->id,
                                    'payslip_id' => $payslip->id,
                                    'period' => $payroll->period_label,
                                    'amount' => $net,
                                ],
                            ]);
                        }
                    } catch (\Throwable) {
                    }

                } catch (\Exception $e) {
                    $hasFailures = true;
                    $this->error("Failed to initiate transfer for {$staff->name}: " . $e->getMessage());
                    $codeMap = [
                        1001 => 'wallet_missing',
                        1002 => 'insufficient_balance',
                        1003 => 'bank_code_missing',
                        1004 => 'api_error',
                    ];
                    $code = $codeMap[$e->getCode()] ?? 'unknown';
                    $reason = $e->getMessage() !== '' ? $e->getMessage() : 'Disbursement failed without a reason.';
                    $this->recordPayslipFailure(
                        $payslip,
                        $reference,
                        $netSalary,
                        $reason,
                        $code,
                        $failureSummary
                    );
                }
            }

            $payrollUpdate = [
                'status' => $hasFailures ? Payroll::STATUS_FAILED : Payroll::STATUS_COMPLETED,
            ];
            if (Schema::hasColumn('payrolls', 'failure_summary')) {
                $payrollUpdate['failure_summary'] = $hasFailures ? $failureSummary : null;
            }
            $payroll->update($payrollUpdate);

            try {
                $employer = $payroll->user;
                if ($employer) {
                    $statusLabel = $hasFailures ? 'has failures' : 'complete';
                    $totalStaff = (int) ($payroll->staff_count ?? $payslips->count());
                    $disbursedCount = max(0, $totalStaff - (int) ($failureSummary['total'] ?? 0));
                    $failedCount = (int) ($failureSummary['total'] ?? 0);

                    if ($hasFailures) {
                        $codeLabelMap = [
                            'wallet_missing' => 'Missing employer wallet',
                            'insufficient_balance' => 'Insufficient wallet balance',
                            'bank_code_missing' => 'Unrecognised bank name(s)',
                            'api_error' => 'Payment provider error',
                            'staff_missing' => 'Missing staff record',
                            'unknown' => 'Unknown error',
                        ];
                        $summaryLines = [];
                        foreach (($failureSummary['by_code'] ?? []) as $code => $count) {
                            if ((int) $count > 0) {
                                $summaryLines[] = ($codeLabelMap[$code] ?? ucfirst(str_replace('_', ' ', (string) $code))) . " — {$count}";
                            }
                        }
                        $body = "Payroll run for {$payroll->period_label} has {$failedCount} failed payment(s) out of {$totalStaff}. {$disbursedCount} paid successfully.\nFailure reasons: " . implode('; ', $summaryLines ?: ['See the payroll detail page.']) . ". Open the payroll detail page to see per-staff reason.";
                    } else {
                        $body = "Payroll run for {$payroll->period_label} ({$payroll->staff_count} staff, ₦" . number_format($payroll->amount, 2) . ") {$statusLabel}.";
                    }

                    Notification::notify($employer, [
                        'category' => 'payroll',
                        'type' => 'payroll_completed',
                        'title' => "Payroll {$statusLabel}",
                        'body' => $body,
                        'icon' => $hasFailures ? 'alert-triangle' : 'check',
                        'deep_link' => "/payroll/{$payroll->id}",
                        'metadata' => [
                            'payroll_id' => $payroll->id,
                            'period' => $payroll->period_label,
                            'staff_count' => $payroll->staff_count,
                            'amount' => $payroll->amount,
                            'has_failures' => $hasFailures,
                            'failed_count' => $failedCount,
                            'disbursed_count' => $disbursedCount,
                            'failure_summary' => $hasFailures ? $failureSummary : null,
                        ],
                    ]);
                    if ($employer->parent_id) {
                        $adminBody = $hasFailures
                            ? "Payroll run for {$payroll->period_label} for " . ($employer->company_name ?? $employer->name) . " has {$failedCount} failed payment(s) out of {$totalStaff}. {$disbursedCount} paid successfully."
                            : "Payroll run for {$payroll->period_label} for " . ($employer->company_name ?? $employer->name) . " ({$payroll->staff_count} staff, ₦" . number_format($payroll->amount, 2) . ") {$statusLabel}.";
                        Notification::notify($employer->parent_id, [
                            'category' => 'payroll',
                            'type' => 'payroll_completed',
                            'title' => $hasFailures
                                ? "Payroll has failures for " . ($employer->company_name ?? $employer->name)
                                : "Payroll {$statusLabel} for " . ($employer->company_name ?? $employer->name),
                            'body' => $adminBody,
                            'icon' => $hasFailures ? 'alert-triangle' : 'check',
                            'deep_link' => "/admin/payrolls",
                            'metadata' => [
                                'payroll_id' => $payroll->id,
                                'employer_id' => $employer->id,
                                'has_failures' => $hasFailures,
                                'failed_count' => $failedCount,
                                'disbursed_count' => $disbursedCount,
                            ],
                        ]);
                    }
                }
            } catch (\Throwable) {
            }

            if (! $hasFailures && $payroll->user?->email) {
                $payroll->load('user');
                Mail::to($payroll->user->email)->send(new PayrollCompleted($payroll));
            }

            $this->info("Payroll ID: {$payroll->id} processing complete.");
        }
    }

    private function buildBankCodeLookup(): array
    {
        $lookup = [];

        try {
            $banks = $this->sarepayService->getBanks();

            foreach ($banks as $bank) {
                $name = is_array($bank)
                    ? ($bank['name'] ?? $bank['bank_name'] ?? null)
                    : ($bank->name ?? $bank->bank_name ?? null);

                $code = is_array($bank)
                    ? ($bank['code'] ?? $bank['bank_code'] ?? null)
                    : ($bank->code ?? $bank->bank_code ?? null);

                if ($name && $code) {
                    $lookup[$this->normalizeBankName($name)] = $code;
                }
            }
        } catch (\Exception $e) {
            $this->error('Failed to fetch bank list: ' . $e->getMessage());
        }

        return $lookup;
    }

    private function resolveBankCode(?string $bankName, array $bankCodeLookup): ?string
    {
        if (! $bankName) {
            return null;
        }

        return $bankCodeLookup[$this->normalizeBankName($bankName)] ?? null;
    }

    private function normalizeBankName(string $bankName): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $bankName)));
    }

    private function recordPayslipFailure(
        Payslip $payslip,
        string $reference,
        float $netSalary,
        string $reason,
        string $code,
        array &$failureSummary
    ): void {
        static $hasReasonCol = null, $hasCodeCol = null;
        if ($hasReasonCol === null) {
            $hasReasonCol = Schema::hasColumn('payslips', 'failure_reason');
            $hasCodeCol = Schema::hasColumn('payslips', 'failure_code');
        }

        $payslipUpdate = ['status' => Payslip::STATUS_FAILED];
        if ($hasReasonCol) $payslipUpdate['failure_reason'] = $reason;
        if ($hasCodeCol) $payslipUpdate['failure_code'] = $code;
        $payslip->update($payslipUpdate);

        Transaction::create([
            'user_id' => $payslip->user_id,
            'payroll_id' => $payslip->payroll_id,
            'payslip_id' => $payslip->id,
            'reference' => $reference,
            'amount' => $netSalary,
            'status' => Transaction::STATUS_FAILED,
            'response_message' => $reason,
            'metadata' => [
                'failure_code' => $code,
            ],
        ]);

        $failureSummary['total'] = ($failureSummary['total'] ?? 0) + 1;
        if (!isset($failureSummary['by_code'][$code])) {
            $failureSummary['by_code'][$code] = 0;
        }
        $failureSummary['by_code'][$code] += 1;

        $failureSummary['failed_payslips'][] = [
            'payslip_id' => $payslip->id,
            'reference' => $reference,
            'user_id' => $payslip->user_id,
            'staff_name' => $payslip->user?->name ?? null,
            'amount' => $netSalary,
            'code' => $code,
            'reason' => $reason,
        ];
    }
}
