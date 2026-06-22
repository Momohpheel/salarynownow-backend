<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employerId = $user->getEmployerId();
        $employer = $user->type === User::TYPE_EMPLOYEE ? User::find($employerId) : $user;

        // 1. Stat Cards Data
        $totalStaff = User::where('employer_id', $employerId)->staff()->where('is_active', true)->count();
        $newStaffCount = User::where('employer_id', $employerId)->staff()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $lastPayroll = $employer->payrolls()
            ->orderBy('processed_at', 'desc')
            ->first();
        
        $activeAdvances = SalaryAdvance::where('user_id', $employerId)
            ->where('status', 'approved')
            ->sum('amount');

        $totalPension = DB::table('payslips')
            ->join('payrolls', 'payslips.payroll_id', '=', 'payrolls.id')
            ->where('payrolls.user_id', $employerId)
            ->sum('pension');
        
        // 2. Wallet Data
        $wallet = $employer->wallet;
        $lastFunding = $wallet ? $wallet->logs()
            ->where('type', 'credit')
            ->latest()
            ->first() : null;

        // 3. Recent Payroll Runs
        $recentPayrolls = $employer->payrolls()
            ->orderBy('processed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'title' => $p->processed_at->format('d M Y') . ' run',
                    'meta' => "{$p->staff_count} employees",
                    'amount' => $this->formatLargeAmount($p->amount),
                    'status' => ucfirst($p->status),
                ];
            });

        // 4. Advance Utilization by Department
        $advanceUtilByDept = User::where('employer_id', $employerId)
            ->staff()
            ->select('department', DB::raw('SUM(salary) as total_salary'))
            ->groupBy('department')
            ->get()
            ->map(function($dept) use ($employerId) {
                $drawn = SalaryAdvance::whereHas('staff', function($q) use ($dept, $employerId) {
                    $q->where('department', $dept->department)
                      ->where('employer_id', $employerId);
                })->where('status', 'approved')->sum('amount');

                $cap = $dept->total_salary * 0.5; // 50% of total salary as cap

                return [
                    'department' => $dept->department ?? 'General',
                    'drawn' => '₦' . number_format($drawn, 0),
                    'cap' => '₦' . $this->formatLargeAmount($cap, 0),
                    'utilization' => $cap > 0 ? round(($drawn / $cap) * 100) . '%' : '0%',
                ];
            });

        $totalCap = User::where('employer_id', $employerId)->staff()->sum('salary') * 0.5;
        $overallUtil = $totalCap > 0 ? round(($activeAdvances / $totalCap) * 100) : 0;

        // 5. Recent Staff Changes (Last 7 days)
        $recentStaffChanges = User::where('employer_id', $employerId)
            ->staff()
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function($s) {
                return [
                    'name' => $s->name,
                    'action' => 'Added to ' . ($s->department ?? 'General'),
                    'date' => $s->created_at->diffForHumans(),
                ];
            });

        $data = [
            'onboarding_status' => [
                'kyb_submitted' => !empty($employer->rc_number) && !empty($employer->cac_certificate_path),
                'is_approved' => (bool) $employer->is_approved,
                'status_message' => $employer->is_approved 
                    ? 'Your account is fully approved.' 
                    : (!empty($employer->rc_number) ? 'KYB submitted, awaiting approval.' : 'Please complete your KYB profile.'),
            ],
            'greeting' => [
                'title' => $this->getGreeting() . ", {$user->name}",
                'subtitle' => "Here's how {$employer->company_name} is moving today.",
            ],
            'stats' => [
                'total_payroll' => [
                    'value' => $lastPayroll ? '₦' . number_format($lastPayroll->amount, 0) : '₦0',
                    'change' => '↗ 4.2%', 
                    'label' => 'last run',
                ],
                'staff_count' => [
                    'value' => $totalStaff,
                    'change' => "↗ +{$newStaffCount}",
                    'label' => 'active',
                ],
                'advances_out' => [
                    'value' => '₦' . number_format($activeAdvances, 0),
                    'change' => '↘ ' . $overallUtil . '% util', 
                    'label' => 'of cap',
                ],
                'pension_filed' => [
                    'value' => '₦' . $this->formatLargeAmount($totalPension),
                    'change' => '↗ On track',
                    'label' => now()->format('d M Y'),
                ],
            ],
            'wallet' => [
                'balance' => '₦' . number_format($wallet?->balance ?? 0, 0),
                'meta' => 'Last funded ' . ($lastFunding ? $lastFunding->created_at->format('d M Y') : now()->format('d M Y')) . ' • Auto-debit enabled',
            ],
            'recent_payroll_runs' => $recentPayrolls,
            'advance_utilization' => [
                'overall_percentage' => $overallUtil . '% overall',
                'items' => $advanceUtilByDept,
            ],
            'recent_staff_changes' => $recentStaffChanges,
        ];

        return $this->sendResponse($data, 'Dashboard data retrieved successfully');
    }

    private function formatLargeAmount($amount, $precision = 1)
    {
        if ($amount >= 1000000000) {
            return number_format($amount / 1000000000, $precision) . 'B';
        }
        if ($amount >= 1000000) {
            return number_format($amount / 1000000, $precision) . 'M';
        }
        if ($amount >= 1000) {
            return number_format($amount / 1000, $precision) . 'K';
        }
        return number_format($amount, $precision);
    }

    private function getGreeting()
    {
        $hour = now()->hour;
        if ($hour < 12) return 'Good morning';
        if ($hour < 17) return 'Good afternoon';
        return 'Good evening';
    }
}
