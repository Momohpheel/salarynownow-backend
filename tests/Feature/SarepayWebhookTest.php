<?php

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SarepayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_sarepay_webhook_credits_wallet_successfully()
    {
        // 1. Arrange: Create a user and a wallet
        $user = User::factory()->employee()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 1000.00,
            'currency' => 'NGN',
            'account_number' => '8941011002',
            'account_name' => 'Test Account',
            'account_reference' => 'wls_FKBYSX51LVR6AEM1693813825',
            'bank_name' => 'UBA',
        ]);

        $payload = [
            "event" => "collection.virtualaccount.successful",
            "data" => [
                "customer_reference" => "wls_FKBYSX51LVR6AEM1693813825",
                "transaction_reference" => "trans_ref_123456",
                "processorReference" => "hjfgkdlkjfhgdkswwwq",
                "amount" => "2000.00",
                "charge" => "20.00",
                "netAmount" => "2020.00",
                "expectedAmount" => "2000.00",
                "channel" => "Virtual Account",
                "sender" => [
                    "originatorBank" => "UBA",
                    "originatorName" => "Test Sender",
                    "originatorAccountNumber" => "123456789"
                ],
                "recipient" => null,
                "status" => "Successful",
                "createdAt" => "2023-09-04T07:50:25.000000Z",
                "updatedAt" => "2023-09-04T07:50:25.000000Z",
                "meta" => [
                    "customerMeta" => null,
                    "notification" => [
                        "amount" => "2000",
                        "bankcode" => "058",
                        "bankname" => "UBA",
                        "craccount" => "8941011002",
                        "narration" => "Test Notification",
                        "sessionid" => "bjkxlkdjhgfdksawwwq",
                        "craccountname" => "Test Account",
                        "originatorname" => "Test",
                        "paymentreference" => "hjfgkdlkjfhgdkswwwq",
                        "originatoraccountnumber" => "123456789"
                    ]
                ]
            ],
            "hash" => "BkLUHqnU/2tpejYhBCGOo8WKcUnwIneWnv3WybMRLBc="
        ];

        // 2. Act: Send the webhook request
        $response = $this->postJson('/api/webhooks/sarepay', $payload);

        // 3. Assert
        $response->assertStatus(200)
            ->assertJsonPath('message', 'Wallet credited successfully');

        $this->assertEquals(3000.00, $wallet->fresh()->balance);
        
        $this->assertDatabaseHas('wallet_logs', [
            'wallet_id' => $wallet->id,
            'amount' => 2000.00,
            'type' => 'credit',
            'balance_before' => 1000.00,
            'balance_after' => 3000.00,
        ]);

        $log = WalletLog::where('wallet_id', $wallet->id)->first();
        $this->assertEquals('trans_ref_123456', $log->metadata['transaction_reference']);
    }

    public function test_sarepay_webhook_ignores_duplicate_transactions()
    {
        // 1. Arrange: Create a user and a wallet with an existing log
        $user = User::factory()->employee()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 3000.00,
            'currency' => 'NGN',
            'account_number' => '8941011002',
            'account_name' => 'Test Account',
            'account_reference' => 'wls_FKBYSX51LVR6AEM1693813825',
            'bank_name' => 'UBA',
        ]);

        $wallet->logs()->create([
            'amount' => 2000.00,
            'type' => 'credit',
            'balance_before' => 1000.00,
            'balance_after' => 3000.00,
            'metadata' => ['transaction_reference' => 'trans_ref_123456']
        ]);

        $payload = [
            "event" => "collection.virtualaccount.successful",
            "data" => [
                "customer_reference" => "wls_FKBYSX51LVR6AEM1693813825",
                "transaction_reference" => "trans_ref_123456",
                "amount" => "2000.00",
            ]
        ];

        // 2. Act: Send the same webhook request again
        $response = $this->postJson('/api/webhooks/sarepay', $payload);

        // 3. Assert
        $response->assertStatus(200)
            ->assertJsonPath('message', 'Transaction already processed');

        $this->assertEquals(3000.00, $wallet->fresh()->balance);
        $this->assertCount(1, WalletLog::all());
    }
}
