<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    public function eligibility(Request $request)
    {
        $user = $request->user();
        $salary = $user->salary;
        $maxAdvance = $salary * 0.5;

        return response()->json([
            'monthly_salary' => '₦' . number_format($salary, 2),
            'max_advance' => '₦' . number_format($maxAdvance, 2),
            'max_advance_raw' => $maxAdvance,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $maxAdvance = $user->salary * 0.5;

        $request->validate([
            'amount' => "required|numeric|min:1000|max:{$maxAdvance}",
        ]);

        $advance = SalaryAdvance::create([
            'user_id' => $user->parent_id, // The Employer/Employee account
            'staff_id' => $user->id,
            'amount' => $request->amount,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Salary advance request submitted successfully',
            'advance' => $advance,
        ], 201);
    }
}
