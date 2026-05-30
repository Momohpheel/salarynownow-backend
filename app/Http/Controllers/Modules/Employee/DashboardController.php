<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employerId = $user->getEmployerId();
        $employer = $user->parent_id ? User::find($employerId) : $user;

        // 1. Total Staff
        $totalStaff = User::where('parent_id', $employerId)->staff()->count();

        // 2. Next Payroll Date (Simulated as 25th of current/next month)
        $today = now();
        $nextPayrollDate = $today->day > 25 
            ? $today->addMonth()->day(25)->format('jS M Y')
            : $today->day(25)->format('jS M Y');

        // 3. Last Payroll
        $lastPayroll = $employer->payrolls()
            ->where('status', 'completed')
            ->orderBy('processed_at', 'desc')
            ->first();

        // 4. Pending Advances
        $pendingAdvancesCount = $employer->salaryAdvances()
            ->where('status', 'pending')
            ->count();

        // 5. Recent Payroll Runs
        $recentPayrolls = $employer->payrolls()
            ->orderBy('processed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($payroll) {
                return [
                    'description' => $payroll->description,
                    'meta' => "{$payroll->staff_count} Employees • Monthly",
                    'amount' => '₦' . number_format($payroll->amount, 2),
                    'status' => $payroll->status,
                    'date' => $payroll->processed_at->format('jS M Y'),
                ];
            });

        $data = [
            'greeting' => $this->getGreeting() . ", {$user->name}.",
            'summary' => [
                'total_staff' => [
                    'value' => $totalStaff,
                    'label' => 'Active employees',
                ],
                'next_payroll_date' => [
                    'value' => '25th',
                    'label' => now()->format('M Y'),
                ],
                'last_payroll' => [
                    'value' => $lastPayroll ? '₦' . number_format($lastPayroll->amount, 2) : '₦0.00',
                    'label' => $lastPayroll ? 'Processed ' . $lastPayroll->processed_at->format('j M') : 'No recent payroll',
                ],
                'pending_advances' => [
                    'value' => $pendingAdvancesCount,
                    'label' => 'Review requests',
                ],
            ],
            'recent_payroll_runs' => $recentPayrolls,
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
