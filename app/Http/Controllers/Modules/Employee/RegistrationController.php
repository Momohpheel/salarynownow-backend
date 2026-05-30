<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegistrationController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            // Step 1: Personal Details
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            
            // Step 2: Company Information
            'company_name' => ['required', 'string', 'max:255'],
            'rc_number' => ['required', 'string', 'max:100'],
            'industry' => ['required', 'string', 'max:255'],
            'company_address' => ['required', 'string'],
            'number_of_staff' => ['required', 'integer', 'min:1'],
            
            // Step 3: KYB Documents
            'cac_certificate' => ['required', 'file', 'mimes:pdf,jpg,png', 'max:5120'],
            'director_id' => ['required', 'file', 'mimes:pdf,jpg,png', 'max:5120'],
            'utility_bill' => ['nullable', 'file', 'mimes:pdf,jpg,png', 'max:5120'],
        ]);

        // Handle file uploads
        $cacPath = $request->file('cac_certificate')->store('kyb_documents', 'public');
        $directorIdPath = $request->file('director_id')->store('kyb_documents', 'public');
        $utilityBillPath = $request->hasFile('utility_bill') 
            ? $request->file('utility_bill')->store('kyb_documents', 'public') 
            : null;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'type' => User::TYPE_EMPLOYEE,
            
            'company_name' => $request->company_name,
            'rc_number' => $request->rc_number,
            'industry' => $request->industry,
            'company_address' => $request->company_address,
            'number_of_staff' => $request->number_of_staff,
            
            'cac_certificate_path' => $cacPath,
            'director_id_path' => $directorIdPath,
            'utility_bill_path' => $utilityBillPath,
        ]);

        return response()->json([
            'message' => 'Employee registered successfully',
            'user' => $user,
        ], 201);
    }
}
