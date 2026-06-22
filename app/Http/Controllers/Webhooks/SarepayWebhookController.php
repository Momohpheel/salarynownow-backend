<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\WalletInflow;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SarepayWebhookController extends Controller
{

    public function updateVA(Request $request)
    {
        $data = $request->all()['data'];
        if ($data['status'] == "Successful") {
            $dto = [
                'account_reference' => $data['reference'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'status' => $data['status'],
                // 'bank_name' => $data['bank'] ?? 'Unknown Bank',
            ];
            // Find and update wallet by account reference
            Wallet::where('account_reference', $dto['account_reference'])->update($dto);
        }
        return response()->json(['message' => 'Virtual account update processed'], 200);
    }

    public function handle(Request $request)
    {
             $payload = $request->all();
        Log::info('Sarepay Webhook Received:', $payload);
        if (strpos($request->event, "generate.virtualaccount.successful") !== false){
            return $this->updateVA($request);
        } else if (strpos($request->event, "collection.virtualaccount.successful") !== false) {
            return $this->virtualAccountWebHook($request);
        }
        // else if (strpos($request->event, "transfer") !== false) {
        //     return $this->transferWebHook($request);
        // }
        return response()->json(['message' => 'Webhook event received'], 200);
    }

    public function virtualAccountWebHook(Request $request)
    {
        $payload = $request->all();
        
        //Log::info('Sarepay Webhook Received:', $payload);

        // 1. Validate the event type
        // if (($payload['event'] ?? '') !== 'collection.virtualaccount.successful') {
        //     return response()->json(['message' => 'Event ignored'], 200);
        // }

        $data = $payload['data'] ?? [];
        $accountReference = $data['customer_reference'] ?? null;
        $transactionReference = $data['transaction_reference'] ?? null;
        $amount = $data['amount'] ?? 0;

        if (!$accountReference || !$transactionReference || $amount <= 0) {
            return response()->json(['message' => 'Invalid data'], 400);
        }

        // 2. Find the wallet by account_reference
        $wallet = Wallet::where('account_reference', $accountReference)->first();

        if (!$wallet) {
            Log::error("Wallet not found for reference: {$accountReference}");
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        // 3. Check if this transaction has already been processed
        $alreadyProcessed = WalletLog::where('metadata->transaction_reference', $transactionReference)->exists();
        if ($alreadyProcessed) {
            return response()->json(['message' => 'Transaction already processed'], 200);
        }

        // 4. Update wallet balance and log the transaction
        return DB::transaction(function () use ($wallet, $amount, $transactionReference, $data) {
            $balanceBefore = $wallet->balance;
            
            // Credit the wallet
            $wallet->increment('balance', $amount);
            
            // Log the transaction
            $walletLog = $wallet->logs()->create([
                'amount' => $amount,
                'type' => 'credit',
                'description' => "Wallet Topup via Virtual Account",
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->fresh()->balance,
                'metadata' => [
                    'transaction_reference' => $transactionReference,
                    'provider' => 'Sarepay',
                    'sender_name' => $data['sender']['originatorName'] ?? 'Unknown',
                    'sender_bank' => $data['sender']['originatorBank'] ?? 'Unknown',
                ]
            ]);

            // Send email notification
            $user = $wallet->user;
            if ($user) {
                Mail::to($user->email)->send(new WalletInflow($walletLog, $user));
            }

            return response()->json(['status' => true, 'message' => 'Wallet credited successfully'], 200);
        });
    }
}
