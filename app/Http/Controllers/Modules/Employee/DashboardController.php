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
        $employer = $user->type === User::TYPE_EMPLOYEE && $user->parent_id ? User::find($employerId) : $user;

        // 1. Stat Cards Data
        $totalStaff = User::where('parent_id', $employerId)->staff()->where('is_active', true)->count();
        $newStaffCount = User::where('parent_id', $employerId)->staff()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $lastPayroll = $employer->payrolls()
            ->where('status', Payroll::STATUS_COMPLETED)
            ->orderBy('processed_at', 'desc')
            ->first();
        
        $activeAdvances = SalaryAdvance::where('user_id', $employerId)
            ->where('status', 'approved')
            ->sum('amount');
        
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
                    'description' => $p->description,
                    'meta' => "{$p->staff_count} Employees • Monthly",
                    'amount' => '₦' . number_format($p->amount, 2),
                    'status' => ucfirst($p->status),
                    'date' => $p->processed_at->format('jS M Y'),
                ];
            });

        // 4. Advance Utilization by Department
        $advanceUtilByDept = User::where('parent_id', $employerId)
            ->staff()
            ->whereHas('staffAdvances', function($q) {
                $q->where('status', 'approved');
            })
            ->select('department', DB::raw('SUM(salary) as total_salary'))
            ->groupBy('department')
            ->get()
            ->map(function($dept) use ($employerId) {
                $drawn = SalaryAdvance::whereHas('staff', function($q) use ($dept, $employerId) {
                    $q->where('department', $dept->department)
                      ->where('parent_id', $employerId);
                })->where('status', 'approved')->sum('amount');

                return [
                    'department' => $dept->department,
                    'drawn' => '₦' . number_format($drawn, 2),
                    'raw_drawn' => $drawn
                ];
            });

        // 5. Recent Staff Changes (Last 7 days)
        $recentStaffChanges = User::where('parent_id', $employerId)
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
            'greeting' => [
                'title' => $this->getGreeting() . ", {$user->name}",
                'subtitle' => "Here's how {$employer->company_name} is moving today.",
            ],
            'stats' => [
                'total_payroll' => [
                    'value' => $lastPayroll ? '₦' . number_format($lastPayroll->amount, 2) : '₦0',
                    'change' => '↗ 4.2%', // Simulated trend
                    'label' => 'last run',
                ],
                'staff_count' => [
                    'value' => $totalStaff,
                    'change' => "↗ +{$newStaffCount}",
                    'label' => 'active',
                ],
                'advances_out' => [
                    'value' => '₦' . number_format($activeAdvances, 2),
                    'change' => '↘ 0% util', // Simulated util
                    'label' => 'of cap',
                ],
                'pension_filed' => [
                    'value' => '₦0',
                    'change' => '↗ On track',
                    'label' => now()->format('d M Y'),
                ],
            ],
            'wallet' => [
                'balance' => '₦' . number_format($wallet?->balance ?? 0, 0),
                'meta' => ($lastFunding ? 'Last funded ' . $lastFunding->created_at->format('d M Y') : 'No recent funding') . ' • Auto-debit enabled',
            ],
            'recent_payroll_runs' => $recentPayrolls,
            'advance_utilization' => [
                'overall_percentage' => '0% overall',
                'items' => $advanceUtilByDept,
            ],
            'recent_staff_changes' => $recentStaffChanges,
        ];

        return $this->sendResponse($data, 'Dashboard data retrieved successfully');
    }

    private function getGreeting()
    {
        $hour = now()->hour;
        if ($hour < 12) return 'Good morning';
        if ($hour < 17) return 'Good afternoon';
        return 'Good evening';
    }
}
