<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Http\Request;

class WalletController extends Controller
{
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
            || (str_contains($type, 'credit') && (int) ($log->balance_before ?? 0) === 0);
        return $needsConfirm ? 'Pending' : 'Confirmed';
    }

    private function walletLogReference(WalletLog $log): string
    {
        return (string) ($log->metadata['transaction_reference']
            ?? $log->metadata['reference']
            ?? $log->metadata['failed_reference']
            ?? '-');
    }

    private function typeLabel(WalletLog $log): string
    {
        $desc = (string) ($log->description ?? '');
        $isReversal = $this->isReversalLog($log);
        if ($isReversal) return 'Reversal';
        $d = strtolower($desc);
        $t = strtolower((string) $log->type);
        if (str_contains($d, 'topup') || str_contains($d, 'virtual account') || str_contains($d, 'fund wallet')) {
            return 'Topup';
        }
        if (str_contains($d, 'payroll') || $t === 'debit' || str_contains($d, 'payout') || str_contains($d, 'disburse')) {
            return 'Payroll Disbursement';
        }
        if (str_contains($d, 'salary advance') || str_contains($d, 'advance')) {
            return 'Advance Disbursement';
        }
        if (str_contains($d, 'pension') || str_contains($d, 'remit')) {
            return 'Pension Remittance';
        }
        return ucfirst($t);
    }

    public function index(Request $request)
    {
        $employerIds = User::where('type', User::TYPE_EMPLOYEE)->pluck('id');
        $walletOwners = User::whereIn('type', [User::TYPE_EMPLOYEE, User::TYPE_ADMIN])->pluck('id');

        $walletQuery = Wallet::with('user:id,name,company_name')
            ->whereIn('user_id', $walletOwners);

        if ($request->filled('search')) {
            $search = $request->search;
            $walletQuery->whereHas('user', function ($query) use ($search) {
                $query->where('company_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', $request->input('page') ? 10 : 0);

        $summaryBuilder = (clone $walletQuery);
        $summary = [
            'active_wallets' => $summaryBuilder->count(),
            'total_held' => '₦' . number_format((float) $summaryBuilder->sum('balance'), 2),
        ];

        $transformWallet = static function ($wallet) {
            return [
                'wallet_id' => $wallet->id,
                'company' => $wallet->user->company_name ?? $wallet->user->name ?? '—',
                'company_id' => $wallet->user_id,
                'balance' => '₦' . number_format((float) $wallet->balance, 2),
                'balance_raw' => $wallet->balance,
                'last_updated' => $wallet->updated_at->format('d M Y, H:i'),
            ];
        };

        if ($perPage > 0) {
            $paginator = $walletQuery->latest('updated_at')->paginate($perPage);
            $walletBalances = collect($paginator->items())->map($transformWallet)->values();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        } else {
            $allWallets = $walletQuery->latest('updated_at')->get();
            $walletBalances = $allWallets->map($transformWallet);
            $pagination = [
                'current_page' => 1,
                'per_page' => $allWallets->count(),
                'total' => $allWallets->count(),
                'last_page' => 1,
                'from' => $allWallets->count() ? 1 : null,
                'to' => $allWallets->count() ? $allWallets->count() : null,
            ];
        }

        $allWalletIds = Wallet::whereIn('user_id', $walletOwners)->pluck('id');

        $txnPerPage = (int) $request->input('txn_per_page', $request->input('txn_page') ? 10 : 0);
        $txnSearch = $request->input('txn_search');
        $txnStatuses = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) $request->input('txn_status', '')))));

        $txnQuery = WalletLog::with('wallet.user:id,name,company_name')
            ->whereIn('wallet_id', $allWalletIds);

        if ($txnSearch) {
            $txnQuery->where(function ($q) use ($txnSearch) {
                $q->whereHas('wallet.user', static function ($u) use ($txnSearch) {
                    $u->where('company_name', 'like', "%{$txnSearch}%")
                        ->orWhere('name', 'like', "%{$txnSearch}%");
                })->orWhere('type', 'like', "%{$txnSearch}%")
                  ->orWhere('description', 'like', "%{$txnSearch}%");
            });
        }

        $txnTransform = function (WalletLog $log) {
            $status = $this->walletTxnStatus($log);
            return [
                'wallet_log_id' => $log->id,
                'date' => $log->created_at?->format('d M Y, H:i') ?? '-',
                'company' => $log->wallet?->user?->company_name ?? $log->wallet?->user?->name ?? '—',
                'company_id' => $log->wallet?->user_id,
                'wallet_id' => $log->wallet_id,
                'type' => $this->isReversalLog($log) ? 'reversal' : (string) $log->type,
                'type_label' => $this->typeLabel($log),
                'is_reversal' => $this->isReversalLog($log),
                'direction' => $this->isReversalLog($log) ? 'reversal' : (strtolower((string) $log->type) === 'credit' ? 'in' : 'out'),
                'amount' => '₦' . number_format((float) $log->amount, 2),
                'amount_raw' => (float) $log->amount,
                'status' => $status,
                'status_raw' => strtolower($status),
                'reference' => $this->walletLogReference($log),
                'description' => (string) ($log->description ?? ''),
                'can_requery' => in_array(strtolower($status), ['pending', 'processing', 'initiated'], true),
            ];
        };

        if ($txnStatuses) {
            $txnQuery->where(function ($q) use ($txnStatuses) {
                if (in_array('reversal', $txnStatuses, true)) {
                    $q->orWhere('type', 'reversal')
                      ->orWhere(function ($qq) {
                          $qq->where('description', 'like', '%reversal%')
                             ->orWhere('description', 'like', '%reverse%')
                             ->orWhere('description', 'like', '%refund for failed%');
                      });
                }
            });
        }

        if ($txnPerPage > 0) {
            $txnPaginator = $txnQuery->latest()->paginate($txnPerPage, ['*'], 'txn_page');
            $recentTransactions = collect($txnPaginator->items())->map($txnTransform)->values();
            $txnPagination = [
                'current_page' => $txnPaginator->currentPage(),
                'per_page' => $txnPaginator->perPage(),
                'total' => $txnPaginator->total(),
                'last_page' => $txnPaginator->lastPage(),
                'from' => $txnPaginator->firstItem(),
                'to' => $txnPaginator->lastItem(),
            ];
        } else {
            $allTxns = $txnQuery->latest()->limit(500)->get();
            $recentTransactions = $allTxns->map($txnTransform);
            $txnPagination = [
                'current_page' => 1,
                'per_page' => $allTxns->count(),
                'total' => $allTxns->count(),
                'last_page' => 1,
                'from' => $allTxns->count() ? 1 : null,
                'to' => $allTxns->count() ? $allTxns->count() : null,
            ];
        }

        $data = [
            'summary' => $summary,
            'wallet_balances' => $walletBalances,
            'pagination' => $pagination,
            'recent_transactions' => $recentTransactions,
            'transactions_pagination' => $txnPagination,
        ];

        return $this->sendResponse($data, 'Wallet oversight retrieved successfully');
    }
}
