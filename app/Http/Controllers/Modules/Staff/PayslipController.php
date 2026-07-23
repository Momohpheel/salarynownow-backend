<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        $payslips = $request->user()->payslips()
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $payslips->map(function($p) {
            return [
                'id' => $p->id,
                'period' => $p->period,
                'gross_salary' => '₦' . number_format($p->gross_salary, 2),
                'pension_employee' => '₦' . number_format($p->pension_employee, 2),
                'pension_employer' => '₦' . number_format($p->pension_employer, 2),
                'tax_deduction' => '₦' . number_format($p->tax_deduction, 2),
                'nhf' => '₦' . number_format($p->nhf, 2),
                'other_deductions' => '₦' . number_format($p->other_deductions, 2),
                'deduction_type' => $p->deduction_type,
                'net_salary' => '₦' . number_format($p->net_salary, 2),
            ];
        });

        return $this->sendResponse($data, 'Payslip history retrieved successfully');
    }

    public function download(Request $request, $id)
    {
        $payslip = \App\Models\Payslip::with('user.parent')->findOrFail($id);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payslip', compact('payslip'));
        return $pdf->download('payslip-' . $payslip->period . '.pdf');
    }
}
