<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $employerIds = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->pluck('id');

        // Summary Cards
        $totalStaff = User::where('type', User::TYPE_STAFF)->whereIn('parent_id', $employerIds)->count();
        $activeStaff = User::where('type', User::TYPE_STAFF)->whereIn('parent_id', $employerIds)->where('is_active', true)->count();
        
        $monthlyGross = Payroll::whereIn('user_id', $employerIds)
            ->where('status', Payroll::STATUS_COMPLETED)
            ->whereMonth('processed_at', Carbon::now()->month)
            ->sum('amount');

        $payrollReady = User::where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $employerIds)
            ->whereNotNull('bank_name')
            ->whereNotNull('account_number')
            ->whereNotNull('salary')
            ->count();

        // Staff List
        $query = User::with(['parent' => function($query) {
                $query->select('id', 'company_name');
            }])
            ->where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('parent', function ($parentQuery) use ($search) {
                        $parentQuery->where('company_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $staff = $query->latest()->paginate(10);

        $data = [
            'summary' => [
                'total_staff' => $totalStaff,
                'active_staff' => $activeStaff,
                'monthly_gross' => '₦' . number_format($monthlyGross, 2),
                'payroll_ready' => $payrollReady,
            ],
            'staff' => $staff->through(function ($member) {
                return [
                    'id' => $member->id,
                    'staff' => [
                        'name' => $member->name,
                        'email' => $member->email,
                    ],
                    'company' => $member->parent->company_name ?? '—',
                    'role' => $member->job_title ?? '—',
                    'compensation' => $member->salary ? '₦' . number_format($member->salary, 2) : '—',
                    'bank_pension' => [
                        'bank' => $member->bank_name ?? '—',
                        'pension' => '—',
                    ],
                    'advances' => $member->staffAdvances()->count(),
                    'joined' => $member->created_at->format('d M Y'),
                    'status' => $member->is_active ? 'Active' : 'Inactive',
                ];
            }),
            'pagination' => [
                'total' => $staff->total(),
                'per_page' => $staff->perPage(),
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
            ]
        ];

        return $this->sendResponse($data, 'Staff directory data retrieved successfully');
    }
}
