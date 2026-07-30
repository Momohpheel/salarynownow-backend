<?php

namespace App\Console\Commands;

use App\Mail\PayslipMail;
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

    /**
     * Execute the console command.
     */
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
                    // Update status based on Sarepay response
                    // Assuming 'success' means disbursed
                    if (strtolower($response->data->status) === 'Successful' || strtolower($response->data->status) === 'completed') {
                        $transaction->update(['status' => Transaction::STATUS_SUCCESS]);
                        
                        // Update related payslip
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
                        $employerWallet = $employer->wallet;

                        if ($employerWallet) {
                            $balanceBefore = (float) $employerWallet->balance;
                            $employerWallet->increment('balance', $transaction->amount);
                            $employerWallet->refresh();

                            $employerWallet->logs()->create([
                                'amount' => $transaction->amount,
                                'type' => 'credit',
                                'description' => "Refund for failed transaction: {$transaction->reference}",
                                'balance_before' => $balanceBefore,
                                'balance_after' => (float) $employerWallet->balance,
                                'metadata' => [
                                    'transaction_id' => $transaction->id,
                                    'payslip_id' => $transaction->payslip_id,
                                    'payroll_id' => $transaction->payroll_id,
                                ],
                            ]);

                            $this->info("Refunded {$transaction->amount} to employer {$employer->name}.");
                        }
                        
                        $this->error("Transaction {$transaction->reference} marked as FAILED.");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error requerying {$transaction->reference}: " . $e->getMessage());
            }
        }

        // Finally, check if any processing payrolls are now fully completed
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
