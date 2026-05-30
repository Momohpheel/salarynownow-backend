<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SarepayService
{
    /**
     * Create a virtual account for a user.
     * This is a mock implementation of the Sarepay API.
     */
    public function createVirtualAccount(User $user)
    {
        // In a real implementation, you would make an HTTP request to Sarepay
        // $response = Http::withToken(config('services.sarepay.key'))
        //     ->post('https://api.sarepay.com/v1/virtual-accounts', [
        //         'account_name' => $user->name,
        //         'email' => $user->email,
        //         // ... other required fields
        //     ]);
        
        // return $response->json();

        // Mocking a successful response from Sarepay
        return [
            'status' => 'success',
            'data' => [
                'account_number' => '012' . rand(1000000, 9999999),
                'account_name' => 'SalaryNowNow - ' . $user->name,
                'account_reference' => 'REF-' . strtoupper(Str::random(10)),
                'bank_name' => 'Sarepay Microfinance Bank',
            ]
        ];
    }

    /**
     * Lookup a bank account.
     */
    public function lookupAccount(string $accountNumber, string $bankCode)
    {
        // Mocking Sarepay Account Lookup
        return [
            'status' => 'success',
            'data' => [
                'account_number' => $accountNumber,
                'account_name' => 'CHIAMAKA OBIOHA',
                'bank_name' => 'Access Bank',
            ]
        ];
    }
}
