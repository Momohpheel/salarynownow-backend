<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Payslip;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;

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
                $transaction->payslip->update(['status' => Payslip::STATUS_DISBURSED]);
            } elseif ($response->status === 'failed') {
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
                }
            }
        }

        return $this->sendResponse($transaction->fresh(), 'Transaction requery completed');
    }
}
