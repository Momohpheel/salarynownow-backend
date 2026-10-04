<?php

namespace App\Console\Commands;

use App\Mail\PayslipMail;
use App\Models\WalletLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Transaction;
use App\Services\Sarepay\SarepayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

#[Signature('app:requery-transactions')]
#[Description('Requery pending transactions to update their status')]
class RequeryTransactions extends Command
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        parent::__construct();
        $this->sarepayService = $sarepayService;
    }

    public function handle()
    {
        $this->info('Checking for processing transactions...');

        $transactions = Transaction::whereIn('status', [
                Payroll::STATUS_PENDING,
                Payroll::STATUS_PROCESSING,
            ])->get();

        if ($transactions->isEmpty()) {
            $this->info('No transactions in processing state.');
            return;
        }

        foreach ($transactions as $transaction) {
            $this->info("Requerying transaction reference: {$transaction->reference}");

            $response = null;
            try {
                $response = $this->sarepayService->verifyTransfer($transaction->reference);
                \Illuminate\Support\Facades\Log::info('Sarepay verifyTransfer response: ' . json_encode($response));
            } catch (\Throwable $verifyE) {
                $this->error("Error requerying {$transaction->reference}: " . $verifyE->getMessage());
                continue;
            }

            try {
                if (is_array($response)) {
                    $rawStatus = $response['data']['status']
                        ?? $response['status']
                        ?? $response['data_status']
                        ?? null;
                    $rawMessage = $response['data']['failure_reason']
                        ?? $response['data']['message']
                        ?? $response['failure_reason']
                        ?? $response['message']
                        ?? null;
                    $rawSuccess = $response['success']
                        ?? $response['data']['success']
                        ?? null;
                    $rawCode = $response['code']
                        ?? $response['response_code']
                        ?? $response['data']['code']
                        ?? $response['data']['response_code']
                        ?? null;
                } elseif (is_object($response)) {
                    $rawStatus = data_get($response, 'data.status')
                        ?? data_get($response, 'status')
                        ?? data_get($response, 'data_status')
                        ?? null;
                    $rawMessage = data_get($response, 'data.failure_reason')
                        ?? data_get($response, 'data.message')
                        ?? data_get($response, 'failure_reason')
                        ?? data_get($response, 'message')
                        ?? null;
                    $rawSuccess = data_get($response, 'success')
                        ?? data_get($response, 'data.success')
                        ?? null;
                    $rawCode = data_get($response, 'code')
                        ?? data_get($response, 'response_code')
                        ?? data_get($response, 'data.code')
                        ?? data_get($response, 'data.response_code')
                        ?? null;
                } else {
                    $rawStatus = null;
                    $rawMessage = null;
                    $rawSuccess = null;
                    $rawCode = null;
                }

                $statusLabel = is_string($rawStatus) && trim($rawStatus) !== ''
                    ? strtolower(trim($rawStatus))
                    : null;

                if ($statusLabel === null) {
                    if ($rawSuccess === true || (is_string($rawSuccess) && in_array(strtolower(trim($rawSuccess)), ['1','true','yes','ok','success'], true))) {
                        $statusLabel = 'successful';
                    } elseif ($rawSuccess === false || (is_string($rawSuccess) && in_array(strtolower(trim($rawSuccess)), ['0','false','no','fail','failed'], true))) {
                        $statusLabel = 'failed';
                    } 
                }

                if ($statusLabel === null && is_string($rawMessage) && trim($rawMessage) !== '' && $rawSuccess === false) {
                    $statusLabel = 'failed';
                }

                $successLabels = ['successful', 'success', 'completed', 'paid', 'processed', 'successfull'];
                $failedLabels  = ['failed', 'failure', 'rejected', 'declined', 'unsuccessful'];

                if ($statusLabel !== null && in_array($statusLabel, $successLabels, true)) {
                    DB::transaction(function () use ($transaction) {
                        $lockedTx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();
                        if (!$lockedTx) return;

                        $txSuccess = strtolower((string) $lockedTx->status) === strtolower((string) Transaction::STATUS_SUCCESS);

                        $lockedPayslip = null;
                        $payslipDisbursed = false;
                        if ($lockedTx->payslip_id) {
                            $lockedPayslip = Payslip::where('id', $lockedTx->payslip_id)->lockForUpdate()->first();
                            if ($lockedPayslip) {
                                $payslipDisbursed = strtolower((string) $lockedPayslip->status) === strtolower((string) Payslip::STATUS_DISBURSED);
                            }
                        }

                        $lockedTx->status = Transaction::STATUS_SUCCESS;
                        $lockedTx->save();

                        if ($lockedPayslip && !$payslipDisbursed) {
                            $lockedPayslip->update(['status' => Payslip::STATUS_DISBURSED]);
                            $lockedPayslip->loadMissing('user');
                        } elseif ($lockedPayslip) {
                            $lockedPayslip->loadMissing('user');
                        }

                        $firstSuccessfulTransition = !$txSuccess || !$payslipDisbursed;
                        if ($firstSuccessfulTransition && $lockedPayslip && $lockedPayslip->user?->email) {
                            try {
                                Mail::to($lockedPayslip->user->email)->send(new PayslipMail($lockedPayslip));
                            } catch (\Throwable $mailE) {
                                $this->warn("Failed to send PayslipMail for payslip #{$lockedPayslip->id}: " . $mailE->getMessage());
                                \Illuminate\Support\Facades\Log::warning(
                                    'Requery: PayslipMail dispatch failed for payslip #' . ($lockedPayslip->id ?? 'null'),
                                    ['error' => $mailE->getMessage(), 'transaction_reference' => $lockedTx->reference]
                                );
                            }
                        }

                        $this->info("Transaction {$lockedTx->reference} marked as SUCCESS.");
                    });
                } elseif ($statusLabel !== null && in_array($statusLabel, $failedLabels, true)) {
                    $failReason = is_string($rawMessage) && trim($rawMessage) !== ''
                        ? trim($rawMessage)
                        : 'Transaction failed';

                    DB::transaction(function () use ($transaction, $failReason) {
                        $lockedTx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();
                        if (!$lockedTx) return;

                        $alreadyFailed = strtolower((string) $lockedTx->status) === strtolower((string) Transaction::STATUS_FAILED);

                        $lockedPayslip = null;
                        if ($lockedTx->payslip_id) {
                            $lockedPayslip = Payslip::where('id', $lockedTx->payslip_id)->lockForUpdate()->first();
                        }

                        $lockedTx->status = Transaction::STATUS_FAILED;
                        $lockedTx->response_message = $failReason;
                        $lockedTx->save();

                        if ($lockedPayslip) {
                            $lockedPayslip->update(['status' => Payslip::STATUS_FAILED]);
                        }

                        $employer = $lockedPayslip?->payroll?->user;
                        $walletOwner = $employer?->resolveSharedWalletOwner();
                        $employerWallet = $walletOwner?->wallet;

                        if ($employerWallet) {
                            $lockedWallet = DB::table('wallets')->where('id', $employerWallet->id)->lockForUpdate()->first();
                            if (!$lockedWallet) {
                                $employerWallet = null;
                            } else {
                                $employerWallet->refresh();
                            }
                        }

                        if ($employerWallet) {
                            $reversalAlreadyExists = WalletLog::where('wallet_id', $employerWallet->id)
                                ->where('type', 'credit')
                                ->where(function ($q) use ($lockedTx) {
                                    if (config('database.default') === 'mysql') {
                                        $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.failed_reference')) = ?", [$lockedTx->reference]);
                                    } else {
                                        $q->whereJsonContains('metadata->failed_reference', $lockedTx->reference);
                                    }
                                })
                                ->orWhere(function ($q) use ($lockedTx) {
                                    $q->where('type', 'credit')
                                      ->whereRaw("description = ?", ["Refund for failed transaction: {$lockedTx->reference}"]);
                                })
                                ->limit(1)
                                ->count() > 0;

                            if ($alreadyFailed && !$reversalAlreadyExists) {
                                $this->warn("Skipping reversal for {$lockedTx->reference}: transaction already failed but no prior credit log found — manual review advised.");
                            }

                            if (!$reversalAlreadyExists && !$alreadyFailed) {
                                $principalAmount = (float) $lockedTx->amount;
                                $storedFeeAmount = 0.0;

                                $originalDebitLog = WalletLog::where('wallet_id', $employerWallet->id)
                                    ->where('type', 'debit')
                                    ->where(function ($q) use ($lockedTx) {
                                        if (config('database.default') === 'mysql') {
                                            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.transaction_reference')) = ?", [$lockedTx->reference]);
                                        } else {
                                            $q->whereJsonContains('metadata->transaction_reference', $lockedTx->reference);
                                        }
                                    })
                                    ->orderBy('id', 'desc')
                                    ->first();

                                if ($originalDebitLog) {
                                    $expectedWalletId = $walletOwner->wallet?->id;
                                    if ($expectedWalletId === null || (int) $originalDebitLog->wallet_id !== (int) $expectedWalletId) {
                                        throw new \RuntimeException(sprintf(
                                            'Reversal wallet mismatch: original debit log wallet_id=%s, expected shared wallet owner wallet_id=%s (business_user_id=%s, business=%s)',
                                            $originalDebitLog->wallet_id,
                                            $expectedWalletId ?? 'null',
                                            $walletOwner->id,
                                            $walletOwner->company_name ?? $walletOwner->name ?? 'Unknown'
                                        ));
                                    }
                                    if (isset($originalDebitLog->metadata['charge_amount'])) {
                                        $storedFeeAmount = (float) $originalDebitLog->metadata['charge_amount'];
                                    }
                                }

                                $totalRefund = $principalAmount + $storedFeeAmount;

                                $this->info(sprintf(
                                    '  Reversal: refunding total ₦%s = ₦%s (principal) + ₦%s (fee from metadata.charge_amount)',
                                    number_format($totalRefund, 2),
                                    number_format($principalAmount, 2),
                                    number_format($storedFeeAmount, 2)
                                ));

                                $balanceBefore = (float) $employerWallet->balance;
                                $employerWallet->increment('balance', $totalRefund);
                                $employerWallet->refresh();

                                $employerWallet->logs()->create([
                                    'amount' => $totalRefund,
                                    'type' => 'credit',
                                    'description' => "Refund for failed transaction: {$lockedTx->reference}",
                                    'balance_before' => $balanceBefore,
                                    'balance_after' => (float) $employerWallet->balance,
                                    'metadata' => [
                                        'business_user_id' => $employer?->id,
                                        'business_company_name' => $employer?->company_name ?? $employer?->name ?? null,
                                        'transaction_id' => $lockedTx->id,
                                        'payslip_id' => $lockedPayslip?->id,
                                        'payroll_id' => $lockedTx->payroll_id,
                                        'failed_reference' => $lockedTx->reference,
                                        'principal_refund' => $principalAmount,
                                        'charge_amount_refund' => $storedFeeAmount,
                                        'total_refund' => $totalRefund,
                                        'original_debit_log_id' => $originalDebitLog?->id,
                                    ],
                                ]);

                                $this->info("Refunded {$totalRefund} to wallet owner {$walletOwner->name} (principal + fee).");
                            }
                        }

                        $this->error("Transaction {$lockedTx->reference} marked as FAILED.");
                    });
                }
            } catch (\Exception $e) {
                $this->error("Error requerying {$transaction->reference}: " . $e->getMessage());
            }
        }

        $this->checkPayrollCompletion();
    }

    private function checkPayrollCompletion()
    {
        $processingPayrolls = Payroll::whereIn('status', [
            Payroll::STATUS_PENDING,
            Payroll::STATUS_PROCESSING,
            Payroll::STATUS_FAILED,
        ])->get();

        foreach ($processingPayrolls as $payroll) {
            $totalPayslips = (int) ($payroll->staff_count ?? $payroll->payslips()->count());
            if ($totalPayslips <= 0) {
                $totalPayslips = $payroll->payslips()->count();
            }
            $completedPayslips = (int) $payroll->payslips()->where('status', Payslip::STATUS_DISBURSED)->count();
            $failedPayslips    = (int) $payroll->payslips()->where('status', Payslip::STATUS_FAILED)->count();

            $anyFailed = $failedPayslips > 0;
            $allDone   = ($completedPayslips + $failedPayslips) >= $totalPayslips;

            if ($anyFailed) {
                if ($payroll->status !== Payroll::STATUS_FAILED) {
                    $payroll->update(['status' => Payroll::STATUS_NOT_COMPLETED]);
                    $this->warn("Payroll ID: {$payroll->id} marked as NOT_COMPLETED ({$failedPayslips} failed payslip(s)).");
                } else {
                    $this->info("Payroll ID: {$payroll->id} already NOT_COMPLETED ({$failedPayslips} failed payslip(s)) — unchanged.");
                }
                continue;
            }

            if ($allDone && $completedPayslips >= $totalPayslips) {
                if ($payroll->status !== Payroll::STATUS_COMPLETED) {
                    $payroll->update(['status' => Payroll::STATUS_COMPLETED]);
                    $this->info("Payroll ID: {$payroll->id} updated to status: completed ({$completedPayslips}/{$totalPayslips} disbursed).");
                }
            } else {
                if ($payroll->status === Payroll::STATUS_COMPLETED) {
                    $payroll->update(['status' => Payroll::STATUS_PROCESSING]);
                    $this->warn("Payroll ID: {$payroll->id} moved back to processing ({$completedPayslips}/{$totalPayslips} finalized, none failed).");
                }
            }
        }
    }
}
