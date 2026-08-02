<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Charge;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function credit(Request $request, User $company)
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
        ]);

        $charge = Charge::where('name', 'wallet-top-up')->first();
        $chargeAmount = 0;
        if ($charge) {
            if ($charge->type === 'percentage') {
                $chargeAmount = ($request->amount * $charge->amount) / 100;
                if ($charge->cap && $chargeAmount > $charge->cap) {
                    $chargeAmount = $charge->cap;
                }
            }
        }

        $netAmount = $request->amount - $chargeAmount;

        $wallet = $company->wallet;

        if (!$wallet) {
            return $this->sendError('Wallet not found for this company.', null, 404);
        }

        $balanceBefore = (float) $wallet->balance;
        $wallet->increment('balance', $netAmount);
        $wallet->refresh();

        $wallet->logs()->create([
            'amount' => $request->amount,
            'type' => 'credit',
            'description' => $request->description,
            'balance_before' => $balanceBefore,
            'balance_after' => (float) $wallet->balance,
            'metadata' => [
                'charge_amount' => $chargeAmount,
            ],
        ]);

        return $this->sendResponse($wallet, 'Wallet credited successfully');
    }

    public function debit(Request $request, User $company)
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
        ]);

        $wallet = $company->wallet;

        if (!$wallet) {
            return $this->sendError('Wallet not found for this company.', null, 404);
        }

        if ($wallet->balance < $request->amount) {
            return $this->sendError('Insufficient wallet balance.', null, 400);
        }

        $balanceBefore = (float) $wallet->balance;
        $wallet->decrement('balance', $request->amount);
        $wallet->refresh();

        $wallet->logs()->create([
            'amount' => $request->amount,
            'type' => 'debit',
            'description' => $request->description,
            'balance_before' => $balanceBefore,
            'balance_after' => (float) $wallet->balance,
        ]);

        return $this->sendResponse($wallet, 'Wallet debited successfully');
    }
}
