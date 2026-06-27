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

        if ($employer->employer_id){
            $employer = User::find($employer->employer_id);
        }

        $wallet = $employer->employer;
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
                return [
                    'date' => $log->created_at->format('d M Y, H:i'),
                    'type' => $log->type === 'credit' ? '+ Topup' : '- Withdrawal',
                    'amount' => ($log->type === 'credit' ? '+ ' : '- ') . '₦' . number_format($log->amount, 2),
                    'status' => 'Confirmed', // Simplified status for UI
                    'reference' => $log->metadata['reference'] ?? '-',
                ];
            }),
        ];

        return $this->sendResponse($data, 'Wallet details retrieved successfully');
    }
}
