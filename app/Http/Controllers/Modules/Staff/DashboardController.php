<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employer = $user->parent; // The employee/company that added this staff

        $data = [
            'greeting' => "Hello, {$user->first_name} 👋",
            'company_name' => $employer?->company_name ?? 'Test Ind',
            'net_salary' => [
                'amount' => '₦' . number_format($user->salary, 2),
                'label' => 'Current Month Net Salary',
                'status' => 'Upcoming'
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
