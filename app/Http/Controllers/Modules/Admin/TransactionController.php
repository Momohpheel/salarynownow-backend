<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\Transaction;
use App\Models\WalletLog;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TransactionController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function requeryTransaction(Transaction $transaction)
    {
        $response = $this->sarepayService->verifyTransfer($transaction->reference);

        if ($response && isset($response->status)) {
            if (strtolower($response->status) === 'success' || strtolower($response->status) === 'completed') {
                $transaction->update(['status' => Transaction::STATUS_SUCCESS]);
                $payslipUpdate = ['status' => Payslip::STATUS_DISBURSED];
                if (Schema::hasColumn('payslips', 'failure_reason')) $payslipUpdate['failure_reason'] = null;
                if (Schema::hasColumn('payslips', 'failure_code')) $payslipUpdate['failure_code'] = null;
                $transaction->payslip->update($payslipUpdate);
            } elseif ($response->status === 'failed') {
                $failReason = is_object($response->data ?? null)
                    ? ($response->data->failure_reason ?? $response->message ?? 'Transaction failed')
                    : ($response->message ?? 'Transaction failed');
                $transaction->update([
                    'status' => Transaction::STATUS_FAILED,
                    'response_message' => $failReason,
                ]);
                $payslipUpdate = ['status' => Payslip::STATUS_FAILED];
                if (Schema::hasColumn('payslips', 'failure_reason')) $payslipUpdate['failure_reason'] = $failReason;
                if (Schema::hasColumn('payslips', 'failure_code')) $payslipUpdate['failure_code'] = 'api_error';
                $transaction->payslip->update($payslipUpdate);

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

                    $balanceBefore = (float) $employerWallet->balance;
                    $employerWallet->increment('balance', $totalRefund);
                    $employerWallet->refresh();

                    $employerWallet->logs()->create([
                        'amount' => $totalRefund,
                        'type' => 'reversal',
                        'description' => "Reversal: Refund for failed transaction: {$transaction->reference}",
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
                }
            }
        }

        return $this->sendResponse($transaction->fresh(), 'Transaction requery completed');
    }
}
