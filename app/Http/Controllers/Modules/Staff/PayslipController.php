<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        $payslips = $request->user()->payslips()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'payslip_history' => $payslips->map(function($p) {
                return [
                    'id' => $p->id,
                    'period' => $p->period,
                    'gross' => '₦' . number_format($p->gross_salary, 2),
                    'pension' => '₦' . number_format($p->pension, 2),
                    'other_deductions' => '₦' . number_format($p->other_deductions, 2),
                    'net' => '₦' . number_format($p->net_salary, 2),
                ];
            })
        ]);
    }
}
