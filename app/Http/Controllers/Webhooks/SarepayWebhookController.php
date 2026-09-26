<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\WalletInflow;
use App\Models\Charge;
use App\Models\FeeConfig;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use App\Traits\ResolvesFeeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SarepayWebhookController extends Controller
{
    use ResolvesFeeConfig;

    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('payment.webhook_secret');
        if ($secret === '') {
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
            $parts = explode('=', $candidate, 2);
            $signature = count($parts) === 2 ? $parts[1] : $candidate;
            if (hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

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
        $amount = (float) ($data['amount'] ?? 0);

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
                $originalWalletOwner = $wallet->user;
                $creditWallet = $wallet;
                $walletOwner = $originalWalletOwner;
                $originalBusinessUserId = null;

                if ($originalWalletOwner && $originalWalletOwner->type === User::TYPE_EMPLOYEE) {
                    $walletOwner = $originalWalletOwner->resolveSharedWalletOwner();
                    $creditWallet = $walletOwner->wallet;
                    $originalBusinessUserId = $originalWalletOwner->id;
                    if (!$creditWallet) {
                        Log::error("Shared wallet not found for TYPE_EMPLOYEE user {$originalWalletOwner->id}, resolved owner {$walletOwner->id}");
                        throw new \RuntimeException("Shared wallet not found for owner group: " . ($walletOwner->company_name ?? $walletOwner->name ?? 'Unknown'));
                    }
                }

                $alreadyProcessed = WalletLog::query()
                    ->where('wallet_id', $creditWallet->id)
                    ->whereJsonContains('metadata->transaction_reference', $transactionReference)
                    ->lockForUpdate()
                    ->exists();
                if ($alreadyProcessed) {
                    return ['replayed' => true];
                }

                $chargeAmount = 0.0;
                $feeScopeLabel = null;
                $feeCalculationLabel = null;
                $feeBreakdown = null;
                $feeSource = 'none';

                if ($walletOwner) {
                    $feeResolution = $this->resolveAndComputeFee(
                        $walletOwner,
                        FeeConfig::EVENT_INFLOW_TOPUP,
                        $amount
                    );
                    $chargeAmount = (float) ($feeResolution['amount'] ?? 0.0);
                    $feeScopeLabel = $feeResolution['scope_label'] ?? null;
                    $feeCalculationLabel = $feeResolution['calculation_label'] ?? null;
                    $feeBreakdown = $feeResolution['breakdown'] ?? null;
                    $feeSource = $chargeAmount > 0 ? 'fee_config' : 'none';
                }

                if ($chargeAmount <= 0) {
                    $charge = Charge::where('name', 'wallet-top-up')->first();
                    if ($charge) {
                        if ($charge->type === 'percentage') {
                            $chargeAmount = ($amount * (float) $charge->amount) / 100;
                            if ($charge->cap && $chargeAmount > (float) $charge->cap) {
                                $chargeAmount = (float) $charge->cap;
                            }
                        } elseif ($charge->type === 'fixed') {
                            $chargeAmount = (float) $charge->amount;
                        }
                        $chargeAmount = round($chargeAmount, 2, PHP_ROUND_HALF_UP);
                        if ($chargeAmount > 0) {
                            $feeSource = 'legacy_charge';
                            $feeBreakdown = sprintf(
                                'Legacy Charge shim: name=wallet-top-up type=%s amount=%s cap=%s — computed fee=%.2f on base=%.2f',
                                $charge->type,
                                $charge->amount,
                                $charge->cap ?? 'null',
                                $chargeAmount,
                                $amount
                            );
                        }
                    }
                }

                Log::info(sprintf(
                    'Sarepay inflow fee resolution: amount=%.2f source=%s chargeAmount=%.2f scope=%s calc=%s',
                    $amount,
                    $feeSource,
                    $chargeAmount,
                    $feeScopeLabel ?? 'none',
                    $feeCalculationLabel ?? 'none'
                ));

                $netAmount = $amount - $chargeAmount;
                if ($netAmount < 0) {
                    $netAmount = 0;
                }
                $balanceBefore = (float) $creditWallet->balance;

                $creditWallet->increment('balance', $netAmount);

                $walletLog = $creditWallet->logs()->create([
                    'amount' => $amount,
                    'type' => 'credit',
                    'description' => "Wallet Topup via Virtual Account",
                    'balance_before' => $balanceBefore,
                    'balance_after' => $creditWallet->fresh()->balance,
                    'metadata' => array_filter([
                        'original_business_user_id' => $originalBusinessUserId,
                        'transaction_reference' => $transactionReference,
                        'provider' => 'Sarepay',
                        'sender_name' => $data['sender']['originatorName'] ?? 'Unknown',
                        'sender_bank' => $data['sender']['originatorBank'] ?? 'Unknown',
                        'charge_amount' => $chargeAmount,
                        'net_amount' => $netAmount,
                        'principal_amount' => $amount,
                        'fee_source' => $feeSource,
                        'fee_scope_label' => $feeScopeLabel,
                        'fee_calculation_label' => $feeCalculationLabel,
                        'fee_breakdown' => $feeBreakdown,
                    ], fn($v) => $v !== null)
                ]);

                $user = $walletOwner;
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
