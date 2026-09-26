<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\Notification;
use App\Models\User;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    use ResolvesBusinessContext;

    public function index(Request $request)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        $advances = SalaryAdvance::whereIn('user_id', $scope->business_ids)
            ->with('staff:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $advances->map(function($advance) {
            return [
                'id' => $advance->id,
                'staff_name' => $advance->staff->name,
                'amount' => '₦' . number_format($advance->amount, 2),
                'amount_raw' => (float)$advance->amount,
                'status' => $advance->status,
                'date' => $advance->created_at->format('d M Y'),
            ];
        });

        return $this->sendResponse($data, 'Salary advances retrieved successfully');
    }

    public function show(Request $request, SalaryAdvance $salaryAdvance)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!in_array((int) $salaryAdvance->user_id, $scope->business_ids, true)) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        return $this->sendResponse($salaryAdvance->load('staff'), 'Salary advance details retrieved successfully');
    }

    public function approve(Request $request, SalaryAdvance $salaryAdvance)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());
        if (!in_array((int) $salaryAdvance->user_id, $scope->business_ids, true)) {
            return $this->sendError('Unauthorized.', null, 403);
        }
        if ($salaryAdvance->status !== 'pending' && $salaryAdvance->status !== 'referred') {
            return $this->sendError('Advance cannot be approved in its current state.', null, 400);
        }

        $salaryAdvance->update(['status' => 'approved']);

        try {
            if ($salaryAdvance->staff_id) {
                $staff = User::find($salaryAdvance->staff_id);
                Notification::notify($salaryAdvance->staff_id, [
                    'category' => 'salary_advance',
                    'type' => 'advance_approved',
                    'title' => 'Advance approved',
                    'body' => ($staff?->name ?? 'Your') . ' request for ' . '₦' . number_format($salaryAdvance->amount, 2) . ' has been approved.',
                    'icon' => 'check-circle',
                    'deep_link' => '/my-pay/advance/status',
                    'metadata' => ['advance_id' => $salaryAdvance->id, 'amount' => (float)$salaryAdvance->amount],
                ]);
            }
            Notification::notify($request->user(), [
                'category' => 'salary_advance',
                'type' => 'advance_approved_employer',
                'title' => 'Advance approved',
                'body' => 'Approval recorded for ' . '₦' . number_format($salaryAdvance->amount, 2),
                'icon' => 'check-circle',
                'deep_link' => '/advances',
                'metadata' => ['advance_id' => $salaryAdvance->id, 'amount' => (float)$salaryAdvance->amount],
            ]);
        } catch (\Throwable) {
        }

        return $this->sendResponse($salaryAdvance->fresh('staff'), 'Advance approved');
    }

    public function reject(Request $request, SalaryAdvance $salaryAdvance)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());
        if (!in_array((int) $salaryAdvance->user_id, $scope->business_ids, true)) {
            return $this->sendError('Unauthorized.', null, 403);
        }
        if ($salaryAdvance->status !== 'pending' && $salaryAdvance->status !== 'referred') {
            return $this->sendError('Advance cannot be declined in its current state.', null, 400);
        }

        $reason = $request->input('reason');
        $salaryAdvance->update([
            'status' => 'declined',
            'reject_reason' => is_string($reason) ? mb_substr($reason, 0, 255) : null,
        ]);

        try {
            if ($salaryAdvance->staff_id) {
                $staff = User::find($salaryAdvance->staff_id);
                Notification::notify($salaryAdvance->staff_id, [
                    'category' => 'salary_advance',
                    'type' => 'advance_declined',
                    'title' => 'Advance declined',
                    'body' => ($staff?->name ?? 'Your') . ' request for ' . '₦' . number_format($salaryAdvance->amount, 2) . ' was declined.',
                    'icon' => 'x-circle',
                    'deep_link' => '/my-pay/advance/status',
                    'metadata' => ['advance_id' => $salaryAdvance->id, 'amount' => (float)$salaryAdvance->amount, 'reason' => $reason],
                ]);
            }
            Notification::notify($request->user(), [
                'category' => 'salary_advance',
                'type' => 'advance_declined_employer',
                'title' => 'Advance declined',
                'body' => 'Decline recorded for ' . '₦' . number_format($salaryAdvance->amount, 2),
                'icon' => 'x-circle',
                'deep_link' => '/advances',
                'metadata' => ['advance_id' => $salaryAdvance->id, 'amount' => (float)$salaryAdvance->amount],
            ]);
        } catch (\Throwable) {
        }

        return $this->sendResponse($salaryAdvance->fresh('staff'), 'Advance declined');
    }
}
