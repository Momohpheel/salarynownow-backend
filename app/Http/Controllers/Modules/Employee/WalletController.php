<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\User;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WalletController extends Controller
{
    use ResolvesBusinessContext;

    public function index(Request $request)
    {
        $user = $request->user();
        $employer = $user->type === \App\Models\User::TYPE_EMPLOYEE && $user->employer_id
            ? $user->employer()
            : $user;

        if ($user->employer_id){
            $employer = User::find($user->employer_id);
        }

        $walletOwner = $employer->resolveSharedWalletOwner();
        $wallet = $walletOwner->wallet;
        if (!$wallet) {
            return $this->sendError('Wallet not found for this user.', null, 404);
        }

        $logsQuery = $wallet->logs()
            ->orderBy('created_at', 'desc')
            ->limit(50);

        $filterBusinessId = $request->input('filter_business_id');
        if ($filterBusinessId !== null && $filterBusinessId !== '') {
            $bizId = (int) $filterBusinessId;
            $logsQuery->whereRaw(
                "JSON_EXTRACT(wallet_logs.metadata, '$.business_user_id') = ?",
                [$bizId]
            );
        }

        $logs = $logsQuery->get();

        $data = [
            'available_balance' => '₦' . number_format($wallet->balance, 2),
            'transaction_count' => $logs->count(),
            'account_details' => [
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_name' => $wallet->bank_name,
            ],
            'transactions' => $logs->map(function($log) {
                $desc = strtolower((string) ($log->description ?? ''));
                $typeRaw = strtolower((string) $log->type);
                $isReversal = $typeRaw === 'reversal'
                    || str_contains($desc, 'reverse')
                    || str_contains($desc, 'reversal')
                    || str_contains($desc, 'refund for failed');

                if ($isReversal) {
                    $typeLabel = 'Reversal';
                    $amountPrefix = '+ ';
                    $status = 'Reversed';
                } elseif ($log->type === 'credit') {
                    $typeLabel = '+ Topup';
                    $amountPrefix = '+ ';
                    $status = 'Confirmed';
                } else {
                    $typeLabel = '- Withdrawal';
                    $amountPrefix = '- ';
                    $status = 'Confirmed';
                }

                return [
                    'date' => $log->created_at->format('d M Y, H:i'),
                    'type' => $typeLabel,
                    'is_reversal' => $isReversal,
                    'amount' => $amountPrefix . '₦' . number_format((float) $log->amount, 2),
                    'amount_raw' => (float) $log->amount,
                    'status' => $status,
                    'description' => (string) ($log->description ?? ''),
                    'reference' => $log->metadata['transaction_reference']
                        ?? $log->metadata['failed_reference']
                        ?? ($log->metadata['reference'] ?? '-'),
                ];
            }),
        ];

        return $this->sendResponse($data, 'Wallet details retrieved successfully');
    }

    public function sharedMeta(Request $request)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();
        $businesses = $ogOwner->ownedBusinesses();

        if ($businesses->isEmpty()) {
            $fallbackBiz = $actingUser->type === User::TYPE_EMPLOYEE ? $actingUser : ($actingUser->employer()->first() ?? $actingUser);
            $businesses = collect([$fallbackBiz]);
        }

        $businessIds = $businesses->pluck('id')->all();
        $combinedBalance = 0;
        $wallet = $ogOwner->wallet;
        if ($wallet) {
            $combinedBalance = (float) $wallet->balance;
        }

        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();

        $payrollStats = Payroll::whereIn('user_id', $businessIds)
            ->where(function ($q) use ($thirtyDaysAgo) {
                $q->where('processed_at', '>=', $thirtyDaysAgo)
                    ->orWhere('created_at', '>=', $thirtyDaysAgo);
            })
            ->select(
                'user_id',
                DB::raw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as total_disbursed'),
                DB::raw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count'),
            )
            ->addBinding([Payroll::STATUS_COMPLETED, Payroll::STATUS_FAILED])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $businessBreakdown = $businesses->map(function ($biz) use ($payrollStats) {
            $stats = $payrollStats->get($biz->id);
            return [
                'business_id' => $biz->id,
                'company_name' => $biz->company_name ?? $biz->name,
                'total_disbursed_30d' => (float) ($stats?->total_disbursed ?? 0),
                'failed_disbursement_count_30d' => (int) ($stats?->failed_count ?? 0),
            ];
        })->values();

        $data = [
            'owner_user_id' => $ogOwner->id,
            'all_business_ids' => $businessIds,
            'business_breakdown_by_amount' => $businessBreakdown->all(),
            'combined_balance' => $combinedBalance,
        ];

        return $this->sendResponse($data, 'Wallet shared metadata retrieved successfully');
    }

    public function history(Request $request)
    {
        $actingUser = $request->user();
        $employer = $actingUser->type === User::TYPE_EMPLOYEE && $actingUser->employer_id
            ? $actingUser->employer()
            : $actingUser;

        if ($actingUser->employer_id) {
            $employer = User::find($actingUser->employer_id);
        }

        $walletOwner = $employer->resolveSharedWalletOwner();
        $wallet = $walletOwner->wallet;
        if (!$wallet) {
            return $this->sendError('Wallet not found for this user.', null, 404);
        }

        $logsQuery = $wallet->logs()
            ->orderBy('created_at', 'desc')
            ->limit(500);

        $filterBusinessId = $request->input('filter_business_id');
        if ($filterBusinessId !== null && $filterBusinessId !== '') {
            $bizId = (int) $filterBusinessId;
            $logsQuery->whereRaw(
                "JSON_EXTRACT(wallet_logs.metadata, '$.business_user_id') = ?",
                [$bizId]
            );
        }

        $logs = $logsQuery->get();

        $ogOwner = $employer->resolveSharedWalletOwner();
        $businesses = $ogOwner->ownedBusinesses();
        if ($businesses->isEmpty()) {
            $fallbackBiz = $actingUser->type === User::TYPE_EMPLOYEE ? $actingUser : ($actingUser->employer()->first() ?? $actingUser);
            $businesses = collect([$fallbackBiz]);
        }
        $businessOptions = $businesses->map(function ($biz) {
            return [
                'id' => $biz->id,
                'company_name' => $biz->company_name ?? $biz->name,
            ];
        })->values()->all();

        $transactions = $logs->map(function($log) {
            $desc = strtolower((string) ($log->description ?? ''));
            $typeRaw = strtolower((string) $log->type);
            $isReversal = $typeRaw === 'reversal'
                || str_contains($desc, 'reverse')
                || str_contains($desc, 'reversal')
                || str_contains($desc, 'refund for failed');

            if ($isReversal) {
                $typeLabel = 'Reversal';
                $amountPrefix = '+ ';
                $status = 'Reversed';
            } elseif ($log->type === 'credit') {
                $typeLabel = '+ Topup';
                $amountPrefix = '+ ';
                $status = 'Confirmed';
            } else {
                $typeLabel = '- Withdrawal';
                $amountPrefix = '- ';
                $status = 'Confirmed';
            }

            return [
                'date' => $log->created_at->format('d M Y, H:i'),
                'type' => $typeLabel,
                'is_reversal' => $isReversal,
                'amount' => $amountPrefix . '₦' . number_format((float) $log->amount, 2),
                'amount_raw' => (float) $log->amount,
                'status' => $status,
                'description' => (string) ($log->description ?? ''),
                'reference' => $log->metadata['transaction_reference']
                    ?? $log->metadata['failed_reference']
                    ?? ($log->metadata['reference'] ?? '-'),
            ];
        });

        $data = [
            'available_balance' => '₦' . number_format($wallet->balance, 2),
            'balance' => (float) $wallet->balance,
            'transaction_count' => $logs->count(),
            'account_details' => [
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_name' => $wallet->bank_name,
            ],
            'business_options' => $businessOptions,
            'transactions' => $transactions,
        ];

        return $this->sendResponse($data, 'Wallet history retrieved successfully');
    }
}
