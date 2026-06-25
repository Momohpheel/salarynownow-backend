<?php
namespace App\Services\Sarepay;

use App\Enums\Sarepay;

use App\Utilities\Helpers;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SarepayService{

    public $key;
    public $iv;

    public function __construct()
    {
        $this->key = env('SAREPAY_AES_KEY');
        $this->iv  = env('SAREPAY_IV');
    }
    protected function headers()
    {
        return [
            'api-key' => $this->constant('api_key'),
            'X-Pub-Key' => $this->constant('public_key'),
            'Authorization' => "Bearer " . $this->constant('api_key'),
        ];
    }

    public function apiGet(string $endpoint, array $payload = null)
    {

        $client = new Client();
        $request = $client->get(
            $endpoint,
            [
                'form_params' => $payload,
                'headers' => $this->headers(),
                'exceptions' => false,
                'http_errors' => false
            ]
        );
        $response = $request->getBody()->getContents();
        return (json_decode($response));
    }

    public function apiPost(string $endpoint, array $payload)
    {
        $client = new Client();
        $request = $client->post(
            $endpoint,
            [
                'json' => $payload,
                'headers' => $this->headers(),
                'exceptions' => false,
                'http_errors' => false
            ]
        );
        $response = $request->getBody()->getContents();
        return (json_decode($response));
    }

    private function constant(string $key)
    {
        $data = [
            'base_url' => config('payment.base_url'),
            'api_key' => config('payment.api_key'),
            'public_key' => config('payment.public_key'),
            'token' => config('payment.token'),
        ];

        $result = $data[$key];
        if (!$result)
            throw new Exception('Error getting Sarepay [' . $key . '] from env');
        return $result;
    }

    public function checkoutInit (
        $amount, object $customer, string $reference
    )
    {
        $checkoutDTO = [
           'key' => $this->constant('public_key'),
            'token' => $this->constant('token'),
            'amount' => $amount,
            'currency' => Sarepay::CURRENCY,
            'feeBearer' => Sarepay::FEEBEARER,
            'defaultPaymentMethod' => Sarepay::DEFAULTPAYMENTMODE,
            'paymentMethods' => $this->paymentMethods(),
            'customer' => $customer ,
            "containerId" => "payment-container",
            // 'metadata' => $this->metaData(),
            'reference' => $reference,
        ];

        $endpoint = $this->constant('base_url') . '/payments/initialize';
        $response = $this->apiPost($endpoint,$checkoutDTO);

        if ($response->status == true) {
            return (Object)[
                'status' => $response->status,
                'data' => $response->data,
            ];
        } else {
            return false;
        }

    }

    public function verify ($reference){
        $verifyDTO = [
            'reference' => $reference,
        ];
        $endpoint = $this->constant('base_url') . '/checkout/payment-info';
        $response = $this->apiPost($endpoint,$verifyDTO);

        if ($response->status == 'success') {
            return (Object)[
                'status' => $response->status,
                'data' => $response->data,
            ];
        } else {
            return false;
        }
    }

    public function metaData ()
    {
        return (Object)[
            'tester' => 'Me'
        ];
    }

    public function paymentMethods ()
    {
        return [
            'transfer',
            'card'
        ];
    }

    public function createAccount($data){
        // Normalize data to use object properties if it's a User model
        $isModel = is_object($data);
        $userId = $isModel ? $data->id : (isset($data['id']) ? $data['id'] : null);
        
        // Generate a unique customer reference if not provided
        
        $customerReference = 'SNN_' . $userId . '_' . \Illuminate\Support\Str::random(12);
       
        
        if (config('app.env') === 'staging' || config('app.env') === 'testing') {
            return (object) [
                "account_number" => "1234567890",
                "account_name" => ($isModel ? $data->name : ($data['name'] ?? 'Test')) . ' ' . ($isModel ? $data->name : ($data['name'] ?? 'User')),
                "account_reference" => $customerReference,
                "bank_name" => "Mock Bank",
            ];
        }

        $fullName = trim((string) ($isModel ? ($data->name ?? '') : ($data['name'] ?? '')));
        $nameParts = preg_split('/\s+/', $fullName, 3, PREG_SPLIT_NO_EMPTY) ?: [];
        [$splitFirstName, $splitLastName, $splitOtherName] = array_pad($nameParts, 3, null);

        $accountDto = [
            "customer_reference" => $customerReference,
            "first_name" => $isModel ? ($data->first_name ?? $splitFirstName ) : ($data['first_name'] ?? $splitFirstName),
            "last_name" => $isModel ? ($data->last_name ?? $splitLastName) : ($data['last_name'] ?? $splitLastName),
            "other_name" => $isModel ? ($data->other_name ?? $splitOtherName) : ($data['other_name'] ?? $splitOtherName),
            "dob" => $isModel ? ($data->dob ?? "2000-01-01") : ($data['dob'] ?? "2000-01-01"),
            // "city" => $isModel ? "Lagos" : ($data['city'] ?? "Lagos"),
            // "state" => $isModel ? "Lagos" : ($data['state'] ?? "Lagos"),
            // "gender" => $isModel ? "Male" : ($data['gender'] ?? "Male"),
            // "marital_status" => $isModel ? "SINGLE" : ($data['marital_status'] ?? "SINGLE"),
            // "address" => $isModel ? ($data->company_address ?? "Test Address") : ($data['company_address'] ?? "Test Address"),
            //"email" => $isModel ? $data->email : ($data['email'] ?? null),
            //"business_name" => $isModel ? ($data->company_name ?? "Test Business") : ($data['company_name'] ?? "Test Business"),
            "bvn" => $isModel ? $data->bvn : ($data['bvn'] ?? null),
            "phone_number" => $isModel ? $data->phone_number : ($data['phone_number'] ?? null),
            "business_type" => "Main",
            //"type" => "Corporate",
            'type' => "Personal",
           // "rc_number" => $isModel ? $data->rc_number : ($data['rc_number'] ?? null),
            //"corporate_account_type" => "COMPANY",
            "currency" => "NGN",
            "channel" => "Globus",
        ];

        Log::info([$accountDto]);

        $endpoint = $this->constant('base_url') . '/virtual-accounts/permanents';
        $response = $this->apiPost($endpoint, $accountDto);

        Log::info([$response]);

        $this->checkFalseAccount($response);
        $response = $response->data;
        return (object) [
            "account_number" => $response->account_number,
            "account_name" => $response->account_name,
            "account_reference" => $response->account_reference,
            "bank_name" => $response->bank,
        ];
    }


    public function createBusinessAccount($data){
        if (config('app.env') === 'staging') {
            return (object) [
                "account_number" => "0987654321",
                "account_name" => $data['business_name'] ?? 'Mock Business',
                "account_reference" => "mock_" . \Illuminate\Support\Str::random(),
                "bank_name" => "Mock Bank",
            ];
        }

        $accountDto = [
            "business_name" => $data['business_name'] ?? null,
            "bvn" => $data['bvn'] ?? null,
            'type' => "Corporate",
            'dob' =>  $data['dob'],
            "business_type" => "Main",
            "rc_number" =>  $data['rc_number'] ?? null,
            "currency" => "NGN",
            "phone_number" => $data["phone"]
        ];

        $endpoint = $this->constant('base_url') . '/virtual-accounts/permanents';
        $response = $this->apiPost($endpoint, $accountDto);
        $this->checkFalseAccount($response);
        $response = $response->data;
        return (object) [
            "account_number" => $response->account_number,
            "account_name" => $response->account_name,
            "account_reference" => $response->account_reference,
            "bank_name" => $response->bank,
        ];
    }


    private function checkFalseAccount($response){
        if(isset($response->status) && $response->status == 'error') throw new Exception($response->message);
        return;
    }

    public function getBanks ()
    {
        $endpoint = $this->constant('base_url') . '/disbursement/banks';
        $response = $this->apiGet($endpoint);
        return $response->data;
    }

    public function validateAccount ($account_no, $bank_code)
    {
        $dto = [
            'account_number' => $account_no,
            'bank_code' => $bank_code,
        ];

        $endpoint = $this->constant('base_url') . '/disbursement/verify-account-direct';
        $response = $this->apiPost($endpoint,$dto);

       return $response;

    }

    public function transfer ($reference, $account_number, $bank_code, $amount, $narration=null)
    {
        if (config('app.env') === 'testing') {
            return (object) [
                'success' => true,
                'status' => 'success',
                'message' => 'Mock transfer successful',
                'data' => ['reference' => $reference]
            ];
        }

        $validate = $this->validateAccount($account_number,$bank_code);

        if ($validate->success == true) {
            $recipient_name = $validate->data->account_name;

            $dto = [
                'customer_reference' => $reference,
                'account_number' => $account_number,
                'bank_code' => $bank_code,
                'amount' => $amount,
                'narration' => 'SALARYNOWNOW TRANSFER/' . $narration ?? "to $recipient_name",
                'recipient_name' => $recipient_name,
            ];

            $endpoint = $this->constant('base_url') . '/disbursement/transact';
            $response = $this->apiPost($endpoint,$dto);

           return $response;
        } else {
            throw new Exception("Account validation failed");
        }
    }

    public function verifyTransfer ($reference)
    {
        if (config('app.env') === 'testing') {
            return (object) [
                'status' => 'success',
                'message' => 'Mock verification successful'
            ];
        }

        $endpoint = $this->constant('base_url') . '/disbursement/requery/' . $reference;
        $response = $this->apiGet($endpoint);

        return $response;
    }

 

    public function requery($reference)
    {
      
        $endpoint = $this->constant('base_url') . '/query/'. $reference;
        $response = $this->apiGet($endpoint);
        // \Log::info($response);
        $response = $response->data;

        return $response;
    }

  
}
