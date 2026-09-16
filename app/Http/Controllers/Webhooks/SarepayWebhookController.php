<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\WalletInflow;
use App\Models\Wallet;
use App\Models\WalletLog;
use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SarepayWebhookController extends Controller
{
    /**
     * Verify the Sarepay webhook signature.
     *
     * Sarepay (and similar payment providers) typically send a header such
     * as `X-Sarepay-Signature` (SHA-512 of the raw body keyed with the
     * shared webhook secret). We accept a small family of common header
     * names so the check is robust when the provider renames the header.
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('payment.webhook_secret');
        if ($secret === '') {
            // If the webhook secret has not been configured (dev/CI),
            // require a local-only source IP. This protects accidental
            // deployments where the secret env variable was not set.
            $ip = (string) $request->ip();
            if ($ip === '' || !in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
                Log::warning('Sarepay webhook rejected: SAREPAY_WEBHOOK_SECRET is empty and caller is not local.', ['ip' => $ip]);
                return false;
            }
            return true;
        }

        $candidates = [
            (string) $request->header('X-Sarepay-Signature', ''),
            (string) $request->header('X-Webhook-Signature', ''),
            (string) $request->header('X-Signature', ''),
        ];

        $body = (string) $request->getContent();
        $expected = hash_hmac('sha512', $body, $secret);

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            // `sha256=abcd...` prefix form also accepted
            $parts = explode('=', $candidate, 2);
            $signature = count($parts) === 2 ? $parts[1] : $candidate;
            if (hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

        // Also accept a lower-common `X-Signature-512` header used by some
        // deployments.
        $alt = trim((string) $request->header('X-Signature-512', ''));
        if ($alt !== '' && hash_equals($expected, $alt)) {
            return true;
        }

        return false;
    }

    public function updateVA(Request $request)
    {
        $data = $request->all()['data'];
        if ($data['status'] == "Successful") {
            $dto = [
                'account_reference' => $data['reference'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
            ];
            Wallet::where('account_reference', $dto['account_reference'])->update($dto);
        }
        return response()->json(['message' => 'Virtual account update processed'], 200);
    }

    public function handle(Request $request)
    {
        // if (!$this->verifySignature($request)) {
        //     Log::warning('Sarepay webhook failed signature verification.', [
        //         'headers' => $request->headers->all(),
        //     ]);
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Unauthorized webhook request.',
        //     ], 401);
        // }

        $payload = $request->all();
        Log::info('Sarepay Webhook Received:', $payload);
        if (strpos((string) $request->event, "generate.virtualaccount.successful") !== false) {
            return $this->updateVA($request);
        } else if (strpos((string) $request->event, "collection.virtualaccount.successful") !== false) {
            return $this->virtualAccountWebHook($request);
        } else if (strpos((string) $request->event, "generate.virtualaccount.failed") !== false) {
            Log::error('Sarepay Webhook Failed:', $payload);
            return response()->json(['message' => 'Webhook event received'], 200);
        }
        return response()->json(['message' => 'Webhook event received'], 200);
    }

    public function virtualAccountWebHook(Request $request)
    {
        $payload = $request->all();

        Log::info('Inflow Sarepay Webhook Received:', $payload);

        $data = $payload['data'] ?? [];
        $accountReference = $data['account_reference'] ?? null;
        $transactionReference = $data['transaction_reference'] ?? null;
        $amount = $data['amount'] ?? 0;

        if (!$accountReference || !$transactionReference || $amount <= 0) {
            return response()->json(['message' => 'Invalid data'], 400);
        }

        $wallet = Wallet::where('account_reference', $accountReference)->first();

        if (!$wallet) {
            Log::error("Wallet not found for reference: {$accountReference}");
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        try {
            $result = DB::transaction(function () use ($wallet, $amount, $transactionReference, $data) {
                // Idempotency: never replay the same transaction twice.
                $alreadyProcessed = WalletLog::query()
                    ->where('wallet_id', $wallet->id)
                    ->whereJsonContains('metadata->transaction_reference', $transactionReference)
                    ->lockForUpdate()
                    ->exists();
                if ($alreadyProcessed) {
                    return ['replayed' => true];
                }

                $charge = Charge::where('name', 'wallet-top-up')->first();
                $chargeAmount = 0;
                if ($charge) {
                    if ($charge->type === 'percentage') {
                        $chargeAmount = ($amount * $charge->amount) / 100;
                        if ($charge->cap && $chargeAmount > $charge->cap) {
                            $chargeAmount = $charge->cap;
                        }
                    }
                }

                $netAmount = $amount - $chargeAmount;
                $balanceBefore = (float) $wallet->balance;

                $wallet->increment('balance', $netAmount);

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
                        'charge_amount' => $chargeAmount,
                        'net_amount' => $netAmount,
                    ]
                ]);

                $user = $wallet->user;
                return [
                    'walletLog' => $walletLog,
                    'user' => $user,
                    'replayed' => false,
                ];
            });

            if (!empty($result['replayed'])) {
                return response()->json(['message' => 'Transaction already processed'], 200);
            }

            if (!empty($result['user'])) {
                try {
                    Mail::to($result['user']->email)
                        ->send(new WalletInflow(
                            $result['walletLog'],
                            $result['user']
                        ));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } catch (\Exception $e) {
            Log::error('Wallet credit failed', [
                'wallet_id' => $wallet->id ?? null,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }
}
