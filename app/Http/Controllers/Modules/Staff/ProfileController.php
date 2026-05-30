<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $employer = $user->parent;

        return response()->json([
            'personal_info' => [
                'name' => $user->name,
                'phone' => $user->phone_number,
                'email' => $user->email,
                'company' => $employer?->company_name ?? 'Test Ind',
            ],
            'bank_details' => [
                'bank' => $user->bank_name,
                'account_no' => $user->account_number,
                'account_name' => $user->account_name,
            ],
            'preferences' => [
                'marketplace_recommendations' => true,
            ]
        ]);
    }

    public function verifyBank(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        $result = $this->sarepayService->validateAccount(
            $request->account_number,
            $request->bank_code
        );

        return response()->json($result);
    }

    public function updateBank(Request $request)
    {
        $request->validate([
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
        ]);

        $user = $request->user();
        $user->update([
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
        ]);

        return response()->json([
            'message' => 'Bank details updated successfully',
            'bank_details' => [
                'bank' => $user->bank_name,
                'account_no' => $user->account_number,
                'account_name' => $user->account_name,
            ]
        ]);
    }
}
