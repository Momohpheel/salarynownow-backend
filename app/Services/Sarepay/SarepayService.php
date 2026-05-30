<?php
namespace App\Services\Sarepay;

use App\Enums\Sarepay;

use App\Utilities\Helpers;
use Exception;
use GuzzleHttp\Client;


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
        if (config('app.env') === 'staging' || config('app.env') === 'testing') {
            return [
                'status' => 'success',
                'data' => [
                    "account_number" => "1234567890",
                    "account_name" => ($data['first_name'] ?? 'Test') . ' ' . ($data['last_name'] ?? 'User'),
                    "account_reference" => "mock_" . \Illuminate\Support\Str::random(),
                    "bank_name" => "Mock Bank",
                ]
            ];
        }

        $accountDto = [
            "last_name" => $data['last_name'] ?? null,
            "first_name" => $data['first_name'] ?? null,
            "other_name" => $data['middle_name'] ?? null,
            "bvn" => $data['bvn'] ?? null,
            'type' => "Personal",
            'dob' =>  $data['dob'],
            "business_type" => "Main",
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

    public function createOneTimeAccount($data){

        $accountDto = [
            "account_name" => $data['last_name']." ".$data['first_name'] ?? null,
            "amount" =>  $data['amount'] ?? null,
            "meta" => [
                 "reference" => $data['reference']
            ],
            
        ];

        $endpoint = $this->constant('base_url') . '/virtual-accounts/onetime';
        $response = $this->apiPost($endpoint, $accountDto);
        $this->checkFalseAccount($response);
        $response = $response->data;

        return [
            "account_number" => $response->account_number,
            "account_name" => $response->account_name,
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
        $validate = $this->validateAccount($account_number,$bank_code);

        if ($validate->success == true) {
            $recipient_name = $validate->data->account_name;

            $dto = [
                'customer_reference' => $reference,
                'account_number' => $account_number,
                'bank_code' => $bank_code,
                'amount' => $amount,
                'narration' => 'SENDRABA TRANSFER/' . $narration ?? "to $recipient_name",
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