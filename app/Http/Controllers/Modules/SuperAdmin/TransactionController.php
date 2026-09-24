<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\Payroll;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    private function isReversalLog(WalletLog $log): bool
    {
        $desc = (string) strtolower($log->description ?? '');
        $type = strtolower((string) $log->type);
        if ($type === 'reversal') return true;
        return str_contains($desc, 'reverse')
            || str_contains($desc, 'reversal')
            || str_contains($desc, 'refund for failed');
    }

    private function walletTxnStatus(WalletLog $log): string
    {
        $status = (string) ($log->metadata['status'] ?? '');
        if ($status) return ucfirst(strtolower($status));
        $pending = strtolower((string) ($log->metadata['pending'] ?? 'true'));
        if ($pending === 'false' || $pending === '0') return 'Confirmed';
        $desc = (string) strtolower($log->description ?? '');
        $type = strtolower((string) $log->type);
        if ($type === 'reversal' || str_contains($desc, 'reversal')) return 'Reversed';
        $needsConfirm = str_contains($desc, 'topup')
            || str_contains($desc, 'virtual account')
            || str_contains($desc, 'fund wallet')
            || str_contains($type, 'credit') && (int) ($log->balance_before ?? 0) === 0;
        return $needsConfirm ? 'Pending' : 'Confirmed';
    }

    private function walletLogReference(WalletLog $log): string
    {
        return (string) ($log->metadata['transaction_reference']
            ?? $log->metadata['reference']
            ?? $log->metadata['failed_reference']
            ?? '-');
    }

    public function index(Request $request)
    {
        $adminIds = User::where('type', User::TYPE_ADMIN)->pluck('id');
        $query = Transaction::with([
            'payroll:id,user_id,title,reference,run_date,status',
            'payroll.user:id,name,company_name',
            'payslip:id,user_id,amount,gross_salary,status',
            'payslip.user:id,name',
        ])->whereIn('user_id', $adminIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('payroll.user', fn($u) => $u->where('company_name', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                  ->orWhereHas('payslip.user', fn($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $statuses = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) $request->input('status', '')))));
        if ($statuses) {
            $query->whereIn(DB::raw('LOWER(status)'), $statuses);
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->latest('id')->paginate($perPage);

        $rows = collect($paginator->items())->map(function (Transaction $t) {
            $canRequery = in_array(strtolower((string) $t->status), [
                Transaction::STATUS_PENDING, Transaction::STATUS_PROCESSING, 'initiated', 'processing', 'pending'
            ], true);
            return [
                'id' => $t->id,
                'reference' => $t->reference,
                'company' => $t->payroll?->user?->company_name ?? $t->payroll?->user?->name ?? '—',
                'company_id' => $t->payroll?->user_id,
                'staff' => $t->payslip?->user?->name ?? '—',
                'amount' => '₦' . number_format((float) $t->amount, 2),
                'amount_raw' => (float) $t->amount,
                'status' => ucfirst(strtolower((string) $t->status)),
                'response_message' => $t->response_message,
                'payroll_id' => $t->payroll_id,
                'payroll_title' => $t->payroll?->title,
                'date' => $t->created_at?->format('d M Y, H:i') ?? '-',
                'can_requery' => $canRequery,
            ];
        })->values();

        $summary = [
            'total' => $paginator->total(),
            'pending' => (int) Transaction::whereIn('user_id', $adminIds)->whereIn('status', [Transaction::STATUS_PENDING, Transaction::STATUS_PROCESSING])->count(),
            'success' => (int) Transaction::whereIn('user_id', $adminIds)->where('status', Transaction::STATUS_SUCCESS)->count(),
            'failed'  => (int) Transaction::whereIn('user_id', $adminIds)->where('status', Transaction::STATUS_FAILED)->count(),
        ];

        $pagination = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return $this->sendResponse(compact('rows', 'summary', 'pagination'), 'Transactions retrieved');
    }

    public function requeryTransaction(Transaction $transaction)
    {
        $response = $this->sarepayService->verifyTransfer($transaction->reference);

        if ($response && isset($response->status)) {
            $status = strtolower((string) $response->status);
            if ($status === 'success' || $status === 'completed') {
                $transaction->update(['status' => Transaction::STATUS_SUCCESS]);
                if ($transaction->payslip) {
                    $transaction->payslip->update(['status' => Payslip::STATUS_DISBURSED]);
                }
            } elseif ($status === 'failed') {
                $transaction->update([
                    'status' => Transaction::STATUS_FAILED,
                    'response_message' => is_object($response->data ?? null)
                        ? ($response->data->failure_reason ?? $response->message ?? 'Transaction failed')
                        : ($response->message ?? 'Transaction failed'),
                ]);
                if ($transaction->payslip) {
                    $transaction->payslip->update(['status' => Payslip::STATUS_FAILED]);
                }

                $employer = $transaction->payslip?->payroll?->user;
                if ($employer) {
                    $employerWallet = $employer->wallet;
                    if ($employerWallet) {
                        $principalAmount = (float) $transaction->amount;
                        $storedFeeAmount = 0.0;

                        $originalDebitLog = WalletLog::where('wallet_id', $employerWallet->id)
                            ->where('type', 'debit')
                            ->whereJsonContains('metadata->transaction_reference', $transaction->reference)
                            ->orderBy('id', 'desc')
                            ->first();

                        if ($originalDebitLog && isset($originalDebitLog->metadata['charge_amount'])) {
                            $storedFeeAmount = (float) $originalDebitLog->metadata['charge_amount'];
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
        }

        return $this->sendResponse($transaction->fresh(), 'Transaction requery completed');
    }

    public function requeryWalletTransaction(WalletLog $walletTransaction)
    {
        $reference = $this->walletLogReference($walletTransaction);
        if (!$reference || $reference === '-') {
            return $this->sendResponse($walletTransaction, 'No reference available to requery');
        }

        $resp = $this->sarepayService->requery($reference);
        $respStatus = is_object($resp) ? strtolower((string) ($resp->status ?? $resp->transaction_status ?? '')) : '';
        $meta = is_array($walletTransaction->metadata) ? $walletTransaction->metadata : [];
        $meta['last_requery'] = now()->toIso8601String();
        $meta['last_requery_status'] = $respStatus ?: (is_object($resp) ? json_encode($resp) : (string) $resp);

        if (in_array($respStatus, ['success', 'successful', 'completed', 'confirmed', 'paid', 'credit'], true)) {
            $meta['status'] = 'confirmed';
            $meta['pending'] = 'false';
        } elseif (in_array($respStatus, ['failed', 'failure', 'declined', 'reversed'], true)) {
            $meta['status'] = 'failed';
            $meta['pending'] = 'false';
        } elseif (in_array($respStatus, ['pending', 'processing', 'initiated'], true)) {
            $meta['status'] = $respStatus;
            $meta['pending'] = 'true';
        }

        $walletTransaction->update(['metadata' => $meta]);

        return $this->sendResponse([
            'wallet_log_id' => $walletTransaction->id,
            'reference' => $reference,
            'requery_status' => $respStatus ?: 'unknown',
            'wallet_log_status' => $meta['status'] ?? 'pending',
            'transformed' => [
                'type' => $this->isReversalLog($walletTransaction) ? 'Reversal' : ucfirst((string) $walletTransaction->type),
                'status' => $this->walletTxnStatus($walletTransaction),
                'reference' => $reference,
            ],
        ], 'Wallet transaction requery completed');
    }

    public function transformWalletLog(WalletLog $log): array
    {
        $desc = (string) ($log->description ?? '');
        $isReversal = $this->isReversalLog($log);
        $isCreditLike = $isReversal || strtolower((string) $log->type) === 'credit';
        if ($isReversal) {
            $typeLabel = 'Reversal';
        } else {
            $d = strtolower($desc);
            $t = strtolower((string) $log->type);
            if (str_contains($d, 'topup') || str_contains($d, 'virtual account') || str_contains($d, 'fund wallet')) {
                $typeLabel = 'Topup';
            } elseif (str_contains($d, 'payroll') || str_contains($t, 'debit') || str_contains($d, 'payout') || str_contains($d, 'disburse')) {
                $typeLabel = 'Payroll Disbursement';
            } elseif (str_contains($d, 'salary advance') || str_contains($d, 'advance')) {
                $typeLabel = 'Advance Disbursement';
            } elseif (str_contains($d, 'pension') || str_contains($d, 'remit')) {
                $typeLabel = 'Pension Remittance';
            } else {
                $typeLabel = ucfirst($t);
            }
        }
        return [
            'wallet_log_id' => $log->id,
            'date' => $log->created_at?->format('d M Y, H:i') ?? '-',
            'company' => $log->wallet?->user?->company_name ?? $log->wallet?->user?->name ?? '—',
            'company_id' => $log->wallet?->user_id,
            'wallet_id' => $log->wallet_id,
            'type' => $isReversal ? 'reversal' : (string) $log->type,
            'type_label' => $typeLabel,
            'is_reversal' => $isReversal,
            'direction' => $isReversal ? 'reversal' : ($isCreditLike ? 'in' : 'out'),
            'amount' => '₦' . number_format((float) $log->amount, 2),
            'amount_raw' => (float) $log->amount,
            'status' => $this->walletTxnStatus($log),
            'status_raw' => strtolower($this->walletTxnStatus($log)),
            'reference' => $this->walletLogReference($log),
            'description' => $desc,
            'can_requery' => in_array(strtolower($this->walletTxnStatus($log)), ['pending', 'processing', 'initiated'], true),
        ];
    }
}
