<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayslipController extends Controller
{
    private function buildLegacyAwareDeductionBreakdown(Payslip $p)
    {
        $rows = collect();
        if ($p->relationLoaded('deductions') && $p->deductions && $p->deductions->isNotEmpty()) {
            foreach ($p->deductions as $d) {
                $rows->push([
                    'id' => $d->id,
                    'name' => $d->deduction_name,
                    'key' => $d->deduction_key,
                    'amount' => '₦' . number_format($d->amount, 2),
                    'amount_raw' => (float) $d->amount,
                ]);
            }
            return $rows->values();
        }
        if ((float) $p->tax_deduction > 0) {
            $rows->push([
                'id' => 'legacy_tax_' . $p->id,
                'name' => 'Tax (PAYE)',
                'key' => 'tax',
                'amount' => '₦' . number_format($p->tax_deduction, 2),
                'amount_raw' => (float) $p->tax_deduction,
            ]);
        }
        if ((float) $p->pension_employee > 0) {
            $rows->push([
                'id' => 'legacy_pension_ee_' . $p->id,
                'name' => 'Pension (Employee)',
                'key' => 'pension_ee',
                'amount' => '₦' . number_format($p->pension_employee, 2),
                'amount_raw' => (float) $p->pension_employee,
            ]);
        }
        if ((float) $p->nhf > 0) {
            $rows->push([
                'id' => 'legacy_nhf_' . $p->id,
                'name' => 'NHF (National Housing Fund)',
                'key' => 'nhf',
                'amount' => '₦' . number_format($p->nhf, 2),
                'amount_raw' => (float) $p->nhf,
            ]);
        }
        if ((float) $p->other_deductions > 0) {
            $label = trim((string) $p->deduction_type) ?: 'Other Deductions';
            $rows->push([
                'id' => 'legacy_other_' . $p->id,
                'name' => $label,
                'key' => 'other',
                'amount' => '₦' . number_format($p->other_deductions, 2),
                'amount_raw' => (float) $p->other_deductions,
            ]);
        }
        return $rows->values();
    }

    private function buildBonusBreakdown(Payslip $p)
    {
        $rows = collect();
        if ((float) $p->bonus_amount > 0) {
            $label = trim((string) $p->bonus_type) ?: 'Bonus';
            $rows->push([
                'id' => 'bonus_' . $p->id,
                'name' => $label,
                'key' => $p->bonus_type ? 'bonus_' . Str::slug($p->bonus_type) : 'bonus',
                'amount' => '₦' . number_format($p->bonus_amount, 2),
                'amount_raw' => (float) $p->bonus_amount,
            ]);
        }
        return $rows->values();
    }

    public function index(Request $request)
    {
        $payslips = $request->user()->payslips()
            ->with(['payroll:id,reference', 'deductions'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $payslips->map(function($p) {
            $ded = $this->buildLegacyAwareDeductionBreakdown($p);
            $bon = $this->buildBonusBreakdown($p);
            return [
                'id' => $p->id,
                'reference' => $p->reference,
                'payroll_reference' => $p->payroll?->reference,
                'period' => $p->period,
                'gross_salary' => '₦' . number_format($p->gross_salary, 2),
                'pension_employee' => '₦' . number_format($p->pension_employee, 2),
                'pension_employer' => '₦' . number_format($p->pension_employer, 2),
                'tax_deduction' => '₦' . number_format($p->tax_deduction, 2),
                'nhf' => '₦' . number_format($p->nhf, 2),
                'other_deductions' => '₦' . number_format($p->other_deductions, 2),
                'deduction_type' => $p->deduction_type,
                'net_salary' => '₦' . number_format($p->net_salary, 2),
                'bonus_amount' => $p->bonus_amount > 0 ? '₦' . number_format($p->bonus_amount, 2) : null,
                'bonus_type' => $p->bonus_type,
                'gross_raw' => (float) $p->gross_salary,
                'pension_employee_raw' => (float) $p->pension_employee,
                'other_deductions_raw' => (float) $p->other_deductions,
                'tax_deduction_raw' => (float) $p->tax_deduction,
                'nhf_raw' => (float) $p->nhf,
                'bonus_amount_raw' => (float) $p->bonus_amount,
                'net_raw' => (float) $p->net_salary,
                'deductions' => $ded->all(),
                'bonuses' => $bon->all(),
            ];
        });

        return $this->sendResponse($data, 'Payslip history retrieved successfully');
    }

    public function download($id)
    {
        $payslip = Payslip::with(['user.parent', 'deductions'])->findOrFail($id);
        $deductionBreakdown = $this->buildLegacyAwareDeductionBreakdown($payslip);
        $bonusBreakdown = $this->buildBonusBreakdown($payslip);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payslip', compact('payslip', 'deductionBreakdown', 'bonusBreakdown'));
        return $pdf->download('payslip-' . $payslip->period . '.pdf');
    }
}
