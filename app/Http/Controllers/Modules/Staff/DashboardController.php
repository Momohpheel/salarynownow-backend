<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employer = User::where('id', $user->parent_id)->first(); // The employee/company that added this staff

        // Payroll runs monthly, so the next payday is the last day of the
        // current month (today, if today is the last day).
        $today = now();
        $dueDate = $today->copy()->endOfMonth()->startOfDay();

        $salary = (float) $user->salary;

        $data = [
            'greeting' => "Hello, {$user->first_name} 👋",
            'company_name' => $employer?->company_name ?? 'Test Ind',
            'net_salary' => [
                'amount' => '₦' . number_format($salary, 2),
                'amount_raw' => $salary,
                'label' => 'Current Month Net Salary',
                'status' => 'Upcoming',
                'due_date' => $dueDate->toIso8601String(),
                'due_label' => $dueDate->isToday() ? 'Today' : 'End of month',
            ],
            'recommendations' => [
                [
                    'title' => 'Wakanow',
                    'category' => 'Travel',
                    'description' => 'Exclusive flight deals for payroll staff — up to 15% off',
                    'sub_text' => 'Based on your salary — exclusive rates unlocked',
                    'color' => 'purple'
                ],
                [
                    'title' => 'CIG Motors',
                    'category' => 'Auto',
                    'description' => 'Special staff pricing on GAC vehicles — 0% deposit',
                    'sub_text' => 'Your advance is cleared — ready to upgrade your ride?',
                    'color' => 'blue'
                ]
            ]
        ];

        return $this->sendResponse($data, 'Staff dashboard data retrieved successfully');
    }
}
