<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\EmployerRegistered;
use App\Mail\ProfileCompleted;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;

class RegistrationController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function register(Request $request)
    {
        $request->validate([
            // Step 1: Personal Details
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'link_name' => ['nullable', 'string', 'exists:users,link_name'],
        ]);

        $merchantId = null;
        if ($request->link_name) {
            $merchant = User::where('link_name', $request->link_name)
                ->where('type', User::TYPE_ADMIN)
                ->first();
            $merchantId = $merchant?->id;
        }

        if (!$merchantId) {
            $defaultMerchant = User::where('type', User::TYPE_ADMIN)
                ->where('link_name', 'main')
                ->first();
            $merchantId = $defaultMerchant?->id;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $merchantId, // Assigned under a merchant using slug lookup
        ]);

        Mail::to($user->email)->send(new EmployerRegistered($user));

        return $this->sendResponse($user, 'Employee registered successfully', true, 201);
    }

    /**
     * Complete profile and auto-approve account.
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if ($user->is_approved) {
            return $this->sendError('Account is already approved.', null, 400);
        }

        $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'rc_number' => ['required', 'string', 'max:100'],
            'industry' => ['required', 'string', 'max:255'],
            'company_address' => ['required', 'string'],
            'number_of_staff' => ['required', 'integer', 'min:1'],
            'bvn' => ['required', 'string', 'digits:11'],
            'cac_certificate' => ['required', 'file', 'mimes:pdf,jpg,png', 'max:2048'],
            'director_id' => ['required', 'file', 'mimes:pdf,jpg,png', 'max:2048'],
            'utility_bill' => ['nullable', 'file', 'mimes:pdf,jpg,png', 'max:2048'],
        ]);

        // Handle file uploads
        $cacPath = $request->file('cac_certificate')->store('kyb_documents', 'public');
        $directorIdPath = $request->file('director_id')->store('kyb_documents', 'public');
        $utilityBillPath = $request->hasFile('utility_bill') 
            ? $request->file('utility_bill')->store('kyb_documents', 'public') 
            : null;

        DB::transaction(function () use ($user, $request, $cacPath, $directorIdPath, $utilityBillPath) {
            // Update user details
            $user->update([
                'company_name' => $request->company_name,
                'rc_number' => $request->rc_number,
                'industry' => $request->industry,
                'company_address' => $request->company_address,
                'number_of_staff' => $request->number_of_staff,
                'bvn' => $request->bvn,
                'cac_certificate_path' => $cacPath,
                'director_id_path' => $directorIdPath,
                'utility_bill_path' => $utilityBillPath,
                'is_approved' => true,
            ]);

            // Call Sarepay to create virtual account
            $accountData = $this->sarepayService->createAccount($user);

            // Create wallet
            Wallet::firstOrCreate([
                'user_id' => $user->id,
            ], [
                'balance' => 0.00,
                'currency' => 'NGN',
                'account_number' => $accountData->account_number,
                'account_name' => $accountData->account_name,
                'account_reference' => $accountData->account_reference,
                'bank_name' => $accountData->bank_name,
            ]);
        });

        Mail::to($user->email)->send(new ProfileCompleted($user));

        return $this->sendResponse($user->fresh('wallet'), 'Profile completed and account approved successfully.');
    }
}
