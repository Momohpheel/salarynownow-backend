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

            try {
                $response = $this->sarepayService->verifyTransfer($transaction->reference);
                \Illuminate\Support\Facades\Log::info('Sarepay verifyTransfer response: ' . json_encode($response));

                if ($response && isset($response->data->status)) {
                    if (strtolower($response->data->status) === 'Successful' || strtolower($response->data->status) === 'completed') {
                        $transaction->status = Transaction::STATUS_SUCCESS;
                        $transaction->save();
                        
                        $transaction->payslip->update(['status' => Payslip::STATUS_DISBURSED]);
                        $transaction->payslip->load('user');

                        if ($transaction->payslip->user?->email) {
                            Mail::to($transaction->payslip->user->email)->send(new PayslipMail($transaction->payslip));
                        }
                        
                        $this->info("Transaction {$transaction->reference} marked as SUCCESS.");
                    } elseif (strtolower($response->data->status) === 'failed') {
                        $transaction->update([
                            'status' => Transaction::STATUS_FAILED,
                            'response_message' => $response->data->failure_reason ?? $response->message ?? 'Transaction failed'
                        ]);
                        
                        $transaction->payslip->update(['status' => Payslip::STATUS_FAILED]);

                        $employer = $transaction->payslip->payroll->user;
                        $walletOwner = $employer->resolveSharedWalletOwner();
                        $employerWallet = $walletOwner->wallet;

                        if ($employerWallet) {
                            $principalAmount = (float) $transaction->amount;
                            $storedFeeAmount = 0.0;

                            $originalDebitLog = WalletLog::where('wallet_id', $employerWallet->id)
                                ->where('type', 'debit')
                                ->whereJsonContains('metadata->transaction_reference', $transaction->reference)
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
                                'description' => "Refund for failed transaction: {$transaction->reference}",
                                'balance_before' => $balanceBefore,
                                'balance_after' => (float) $employerWallet->balance,
                                'metadata' => [
                                    'business_user_id' => $employer->id,
                                    'business_company_name' => $employer->company_name ?? $employer->name ?? null,
                                    'transaction_id' => $transaction->id,
                                    'payslip_id' => $transaction->payslip_id,
                                    'payroll_id' => $transaction->payroll_id,
                                    'failed_reference' => $transaction->reference,
                                    'principal_refund' => $principalAmount,
                                    'charge_amount_refund' => $storedFeeAmount,
                                    'total_refund' => $totalRefund,
                                    'original_debit_log_id' => $originalDebitLog?->id,
                                ],
                            ]);

                            $this->info("Refunded {$totalRefund} to wallet owner {$walletOwner->name} (principal + fee).");
                        }
                        
                        $this->error("Transaction {$transaction->reference} marked as FAILED.");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error requerying {$transaction->reference}: " . $e->getMessage());
            }
        }

        $this->checkPayrollCompletion();
    }

    private function checkPayrollCompletion()
    {
        $processingPayrolls = Payroll::where('status', Payroll::STATUS_PROCESSING)->get();

        foreach ($processingPayrolls as $payroll) {
            $totalPayslips = $payroll->payslips()->count();
            $completedPayslips = $payroll->payslips()->where('status', Payslip::STATUS_DISBURSED)->count();
            $failedPayslips = $payroll->payslips()->where('status', Payslip::STATUS_FAILED)->count();

            if ($totalPayslips === ($completedPayslips + $failedPayslips)) {
                $status = $failedPayslips > 0 ? Payroll::STATUS_FAILED : Payroll::STATUS_COMPLETED;
                $payroll->update(['status' => $status]);
                $this->info("Payroll ID: {$payroll->id} updated to status: {$status}");
            }
        }
    }
}
