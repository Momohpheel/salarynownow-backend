<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $advances = SalaryAdvance::where('user_id', $employerId)
            ->with('staff:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'salary_advances' => $advances->map(function($advance) {
                return [
                    'id' => $advance->id,
                    'staff_name' => $advance->staff->name,
                    'amount' => '₦' . number_format($advance->amount, 2),
                    'status' => $advance->status,
                    'date' => $advance->created_at->format('d M Y'),
                ];
            }),
        ]);
    }

    public function show(Request $request, SalaryAdvance $salaryAdvance)
    {
        $employerId = $request->user()->getEmployerId();

        if ($salaryAdvance->user_id !== $employerId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'salary_advance' => $salaryAdvance->load('staff'),
        ]);
    }
}
