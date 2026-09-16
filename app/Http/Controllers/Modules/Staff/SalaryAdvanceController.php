<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    public function eligibility(Request $request)
    {
        $user = $request->user();
        $salary = $user->salary;
        $maxAdvance = $salary * 0.5;

        $data = [
            'monthly_salary' => '₦' . number_format($salary, 2),
            'max_advance' => '₦' . number_format($maxAdvance, 2),
            'max_advance_raw' => $maxAdvance,
        ];

        return $this->sendResponse($data, 'Salary advance eligibility retrieved');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $maxAdvance = $user->salary * 0.5;

        $request->validate([
            'amount' => "required|numeric|min:1000|max:{$maxAdvance}",
        ]);

        $employerId = $user->parent_id ?? $user->employer_id;

        $advance = SalaryAdvance::create([
            'user_id' => $employerId,
            'staff_id' => $user->id,
            'amount' => $request->amount,
            'status' => 'pending',
        ]);

        try {
            Notification::notify($user, [
                'category' => 'salary_advance',
                'type' => 'advance_requested_staff',
                'title' => 'Advance request submitted',
                'body' => 'Your request for ' . '₦' . number_format($request->amount, 2) . ' is pending review.',
                'icon' => 'wallet',
                'deep_link' => '/my-pay/advances',
                'metadata' => ['advance_id' => $advance->id, 'amount' => (float)$request->amount],
            ]);
            if ($employerId) {
                $employerIds = array_values(array_filter([(int)$employerId]));
                $coOwners = User::where('parent_id', $employerId)
                    ->where('type', User::TYPE_EMPLOYEE)
                    ->whereNotNull('role_id')
                    ->pluck('id')
                    ->all();
                $recipients = array_unique(array_merge($employerIds, $coOwners));
                foreach ($recipients as $rid) {
                    Notification::notify((int)$rid, [
                        'category' => 'salary_advance',
                        'type' => 'advance_requested_employer',
                        'title' => 'New advance request',
                        'body' => ($user->name ?? 'A staff member') . ' requested ' . '₦' . number_format($request->amount, 2),
                        'icon' => 'wallet',
                        'deep_link' => '/advances',
                        'metadata' => ['advance_id' => $advance->id, 'staff_id' => $user->id, 'amount' => (float)$request->amount],
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        return $this->sendResponse($advance, 'Salary advance request submitted successfully', true, 201);
    }
}
