<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $employerIds = User::where('type', User::TYPE_EMPLOYEE)->pluck('id');

        $walletQuery = Wallet::with('user:id,name,company_name')
            ->whereIn('user_id', $employerIds);

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

        $allWalletIds = Wallet::whereIn('user_id', $employerIds)->pluck('id');

        $txnPerPage = (int) $request->input('txn_per_page', $request->input('txn_page') ? 10 : 0);
        $txnSearch = $request->input('txn_search');

        $txnQuery = WalletLog::with('wallet.user:id,name,company_name')
            ->whereIn('wallet_id', $allWalletIds);

        if ($txnSearch) {
            $txnQuery->where(function ($q) use ($txnSearch) {
                $q->whereHas('wallet.user', static function ($u) use ($txnSearch) {
                    $u->where('company_name', 'like', "%{$txnSearch}%")
                        ->orWhere('name', 'like', "%{$txnSearch}%");
                })->orWhere('type', 'like', "%{$txnSearch}%");
            });
        }

        $txnTransform = static function ($log) {
            return [
                'date' => $log->created_at->format('d M Y'),
                'company' => $log->wallet->user->company_name ?? $log->wallet->user->name ?? '—',
                'type' => ucfirst($log->type),
                'amount' => '₦' . number_format((float) $log->amount, 2),
                'status' => 'Confirmed',
                'reference' => $log->metadata['transaction_reference']
                    ?? $log->metadata['reference']
                    ?? '-',
            ];
        };

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
