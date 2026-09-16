<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Mail\PayslipMail;
use App\Mail\PayrollCompleted;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Charge;
use App\Models\Notification;
use App\Models\Transaction;
use App\Services\Sarepay\SarepayService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

#[Signature('app:process-payroll')]
#[Description('Process and disburse salaries for scheduled payrolls')]
class ProcessPayroll extends Command
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        parent::__construct();
        $this->sarepayService = $sarepayService;
    }

    /**
     * Execute the console command.
     */
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

        //$disbursementCharge = Charge::where('name', 'disbursement')->first();

        foreach ($payrolls as $payroll) {
            // Guard: orphan payroll (user relationship missing. Skip gracefully so other payrolls keep processing.
            $employer = $payroll->user;
            $employerName = $employer?->name ?? 'Unknown Employer';
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

            $payslips = Payslip::where('payroll_id', $payroll->id)
               ->where('status', Payslip::STATUS_PENDING)
                ->get();

            foreach ($payslips as $payslip) {
                $staff = $payslip->user;
                $reference = 'SAL-' . Str::upper(Str::random(10));

                $this->info("Initiating transfer of ₦" . number_format($payslip->net_salary, 2) . " to {$staff->name} ({$staff->account_number})");

                try {
                    if (! $employerWallet) {
                        $this->error("Employer wallet not found for {$payroll->user->name}");
                        throw new \Exception("Employer wallet not found.");
                    }

                    $chargeAmount = 0;
                    // if ($disbursementCharge && $disbursementCharge->type === 'fixed') {
                    //     $chargeAmount = $disbursementCharge->amount;
                    // }

                    $totalDeduction = $payslip->net_salary + $chargeAmount;

                    if ($availableBalance < $totalDeduction) {
                        $this->error("Insufficient employer wallet balance for {$payroll->user->name} to cover salary and charges");
                        throw new \Exception("Insufficient employer wallet balance for this transaction and charges.");
                    }

                    $bankCode = $this->resolveBankCode($staff->bank_name, $bankCodeLookup);

                    if (! $bankCode) {
                        $this->error("Bank code not found for {$staff->bank_name}");
                        throw new \Exception("Bank code not found for {$staff->bank_name}.");
                    }

                    $response = $this->sarepayService->transfer(
                        $reference,
                        $staff->account_number,
                        $bankCode,
                        $payslip->net_salary,
                        "Salary for {$payroll->description}"
                    );

                    // Null-safe Sarepay response status extraction
                    // Expected: {success: true, data: {status: "SUCCESS"}}, but accept any shape.
                    if (is_array($response)) {
                        $rawStatus = $response['data']['status']
                            ?? $response['status']
                            ?? $response['data_status']
                            ?? null;
                    } elseif (is_object($response)) {
                        $rawStatus = data_get($response, 'data.status')
                            ?? data_get($response, 'status')
                            ?? data_get($response, 'data_status')
                            ?? null;
                    } else {
                        $rawStatus = null;
                    }
                    $transferStatus = is_string($rawStatus) && $rawStatus !== ''
                        ? strtolower($rawStatus)
                        : Transaction::STATUS_PENDING;

                    // Create transaction record
                    Transaction::create([
                        'user_id' => $staff->id,
                        'payroll_id' => $payroll->id,
                        'payslip_id' => $payslip->id,
                        'reference' => $reference,
                        'amount' => $payslip->net_salary,
                        'status' => $transferStatus,
                        'metadata' => (array) $response,
                    ]);

                    $balanceBefore = (float) $employerWallet->balance;
                    $employerWallet->decrement('balance', $totalDeduction);
                    $employerWallet->refresh();

                    $employerWallet->logs()->create([
                        'amount' => $payslip->net_salary,
                        'type' => 'debit',
                        'description' => "Salary payment for {$staff->name} ({$payroll->description})",
                        'balance_before' => $balanceBefore,
                        'balance_after' => (float) $employerWallet->balance,
                        'metadata' => [
                            'payroll_id' => $payroll->id,
                            'payslip_id' => $payslip->id,
                            'transaction_reference' => $reference,
                            'charge_amount' => $chargeAmount,
                        ],
                    ]);

                    $availableBalance = (float) $employerWallet->balance;
                    $payslip->update(['status' => Payslip::STATUS_DISBURSED]);
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
                    
                    Transaction::create([
                        'user_id' => $staff->id,
                        'payroll_id' => $payroll->id,
                        'payslip_id' => $payslip->id,
                        'reference' => $reference,
                        'amount' => $payslip->net_salary,
                        'status' => Transaction::STATUS_FAILED,
                        'response_message' => $e->getMessage(),
                    ]);
                }
            }

            $payroll->update([
                'status' => $hasFailures ? Payroll::STATUS_FAILED : Payroll::STATUS_COMPLETED,
            ]);

            try {
                $employer = $payroll->user;
                if ($employer) {
                    $statusLabel = $hasFailures ? 'has failures' : 'complete';
                    Notification::notify($employer, [
                        'category' => 'payroll',
                        'type' => 'payroll_completed',
                        'title' => "Payroll {$statusLabel}",
                        'body' => "Payroll run for {$payroll->period_label} ({$payroll->staff_count} staff, ₦" . number_format($payroll->amount, 2) . ") {$statusLabel}.",
                        'icon' => 'check',
                        'deep_link' => "/payroll/{$payroll->id}",
                        'metadata' => [
                            'payroll_id' => $payroll->id,
                            'period' => $payroll->period_label,
                            'staff_count' => $payroll->staff_count,
                            'amount' => $payroll->amount,
                            'has_failures' => $hasFailures,
                        ],
                    ]);
                    if (!$hasFailures && $employer->parent_id) {
                        Notification::notify($employer->parent_id, [
                            'category' => 'payroll',
                            'type' => 'payroll_completed',
                            'title' => "Payroll {$statusLabel} for " . ($employer->company_name ?? $employer->name),
                            'body' => "Payroll run for {$payroll->period_label} ({$payroll->staff_count} staff, ₦" . number_format($payroll->amount, 2) . ") {$statusLabel}.",
                            'icon' => 'check',
                            'deep_link' => "/admin/payrolls",
                            'metadata' => [
                                'payroll_id' => $payroll->id,
                                'employer_id' => $employer->id,
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
}
