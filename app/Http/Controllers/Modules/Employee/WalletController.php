<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employer = $user->type === \App\Models\User::TYPE_EMPLOYEE && $user->employer_id
            ? $user->employer()
            : $user;

        if ($user->employer_id){
            $employer = User::find($user->employer_id);
        }

        $wallet = $employer->wallet;
        if (!$wallet) {
            return $this->sendError('Wallet not found for this user.', null, 404);
        }

        $logs = $wallet->logs()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

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
}
