<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();

        // 1. Stat Cards Data
        $companiesCount = User::where('type', User::TYPE_EMPLOYEE)->where('parent_id', $admin->id)->count();
        $staffCount = User::where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', function($query) use ($admin) {
                $query->select('id')->from('users')->where('parent_id', $admin->id);
            })->count();
        
        $totalProcessedPayroll = Payroll::whereIn('user_id', function($query) use ($admin) {
                $query->select('id')->from('users')->where('parent_id', $admin->id);
            })->where('status', Payroll::STATUS_COMPLETED)->sum('amount');
            
        $activeAdvances = SalaryAdvance::whereIn('user_id', function($query) use ($admin) {
                $query->select('id')->from('users')->where('parent_id', $admin->id);
            })->where('status', 'approved')->count();

        // 2. Priority Queues
        $pendingKybCount = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('is_approved', false)
            ->count();
            
        $lastPendingKyb = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('is_approved', false)
            ->latest()
            ->first();

        // 3. Live Activity Feed (Simplified implementation)
        // In a real app, this would be an AuditLog or Activity table
        $activityFeed = [];
        
        // Add some simulated activities based on real data for the UI
        $recentApprovals = User::where('type', User::TYPE_EMPLOYEE)
            ->where('parent_id', $admin->id)
            ->where('is_approved', true)
            ->latest()
            ->limit(3)
            ->get();
            
        foreach($recentApprovals as $user) {
            $activityFeed[] = [
                'type' => 'audit',
                'tag' => 'kyb approved • company',
                'content' => "{$user->company_name} approved",
                'time' => $user->updated_at->diffForHumans()
            ];
        }

        $data = [
            'stats' => [
                'companies' => $companiesCount,
                'staff' => $staffCount,
                'payroll_processed' => '₦' . number_format($totalProcessedPayroll, 0),
                'active_advances' => $activeAdvances,
                'marketplace_enquiries' => 0, // Placeholder
            ],
            'priority_queues' => [
                'pending_kyb' => [
                    'count' => $pendingKybCount,
                    'message' => $lastPendingKyb ? "{$lastPendingKyb->company_name} is waiting for review." : "No pending reviews."
                ],
                'rejected_employers' => [
                    'count' => 0, // Placeholder
                    'message' => "No rejected employer onboarding cases."
                ],
                'pending_partners' => [
                    'count' => 0, // Placeholder
                    'message' => "Partner applications are awaiting approval or rejection."
                ],
                'payroll_oversight' => [
                    'count' => Payroll::whereIn('user_id', function($query) use ($admin) {
                        $query->select('id')->from('users')->where('parent_id', $admin->id);
                    })->count(),
                    'message' => "Review all payroll runs across employers from one control point."
                ]
            ],
            'live_activity_feed' => $activityFeed
        ];

        return $this->sendResponse($data, 'Admin dashboard data retrieved successfully');
    }
}
