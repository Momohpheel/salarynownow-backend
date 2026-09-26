<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerGroupController extends Controller
{
    public function index(Request $request)
    {
        $allEmployers = User::where('type', User::TYPE_EMPLOYEE)
            ->get();

        if ($allEmployers->isEmpty()) {
            return $this->sendResponse([], 'Owner groups retrieved successfully');
        }

        $employerIds = $allEmployers->pluck('id')->toArray();

        $ownerUserIds = [];
        foreach ($allEmployers as $emp) {
            $ownerId = !is_null($emp->owner_group_user_id) && (int) $emp->owner_group_user_id !== 0
                ? (int) $emp->owner_group_user_id
                : (int) $emp->id;
            if (in_array($ownerId, $employerIds, true)) {
                $ownerUserIds[$ownerId] = $ownerId;
            } else {
                $ownerUserIds[(int) $emp->id] = (int) $emp->id;
            }
        }

        $ownerUsers = User::whereIn('id', array_values($ownerUserIds))
            ->where('type', User::TYPE_EMPLOYEE)
            ->get()
            ->keyBy('id');

        $groupMapping = [];
        foreach ($allEmployers as $emp) {
            $resolvedOwnerId = !is_null($emp->owner_group_user_id) && (int) $emp->owner_group_user_id !== 0
                ? (int) $emp->owner_group_user_id
                : (int) $emp->id;
            if (!isset($ownerUsers[$resolvedOwnerId])) {
                $resolvedOwnerId = (int) $emp->id;
            }
            $groupMapping[$resolvedOwnerId][] = (int) $emp->id;
        }

        $thirtyDaysAgo = now()->subDays(30);

        $allGroupBusinessIds = [];
        foreach ($groupMapping as $businessIds) {
            foreach ($businessIds as $bid) {
                $allGroupBusinessIds[] = $bid;
            }
        }
        $allGroupBusinessIds = array_unique($allGroupBusinessIds);

        $staffCounts = User::whereIn('parent_id', $allGroupBusinessIds)
            ->where('type', User::TYPE_STAFF)
            ->select('parent_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('parent_id')
            ->pluck('cnt', 'parent_id');

        $payrollCounts30d = Payroll::whereIn('user_id', $allGroupBusinessIds)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $walletBalances = Wallet::whereIn('user_id', $allGroupBusinessIds)
            ->select('user_id', DB::raw('COALESCE(SUM(balance), 0) as bal'))
            ->groupBy('user_id')
            ->pluck('bal', 'user_id');

        $disbursed30d = Payroll::whereIn('user_id', $allGroupBusinessIds)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('status', Payroll::STATUS_COMPLETED)
            ->select('user_id', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $failedPayrolls30d = Payroll::whereIn('user_id', $allGroupBusinessIds)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('status', Payroll::STATUS_FAILED)
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $staffUserIds = User::whereIn('parent_id', $allGroupBusinessIds)
            ->where('type', User::TYPE_STAFF)
            ->pluck('id')
            ->toArray();

        $perBusinessAdvanceCounts = SalaryAdvance::whereIn('user_id', $allGroupBusinessIds)
            ->whereIn('status', ['pending', 'approved'])
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $staffAdvanceByEmployer = [];
        if (!empty($staffUserIds)) {
            $staffAdvanceRows = SalaryAdvance::whereIn('staff_id', $staffUserIds)
                ->whereIn('status', ['pending', 'approved'])
                ->join('users as staff_u', 'staff_u.id', '=', 'salary_advances.staff_id')
                ->select('staff_u.parent_id', DB::raw('COUNT(DISTINCT salary_advances.id) as cnt'))
                ->groupBy('staff_u.parent_id')
                ->pluck('cnt', 'parent_id');
            foreach ($staffAdvanceRows as $pid => $cnt) {
                $staffAdvanceByEmployer[(int) $pid] = (int) $cnt;
            }
        }

        $results = [];
        foreach ($groupMapping as $ownerId => $businessIds) {
            $ownerUser = $ownerUsers[$ownerId] ?? null;
            if (!$ownerUser) {
                continue;
            }

            $businesses = [];
            $combinedWalletBalance = 0;
            $totalStaff = 0;
            $last30dDisbursed = 0;
            $failedPayrollCount30d = 0;
            $activeAdvanceCount = 0;

            foreach ($businessIds as $bid) {
                $biz = $allEmployers->firstWhere('id', $bid);
                if (!$biz) {
                    $biz = User::find($bid);
                }
                if (!$biz) {
                    continue;
                }

                $bizStaffCount = (int) ($staffCounts[$bid] ?? 0);
                $bizPayrollCount30d = (int) ($payrollCounts30d[$bid] ?? 0);
                $bizWallet = (float) ($walletBalances[$bid] ?? 0);
                $bizDisbursed = (float) ($disbursed30d[$bid] ?? 0);
                $bizFailed = (int) ($failedPayrolls30d[$bid] ?? 0);
                $bizAdvances = (int) ($perBusinessAdvanceCounts[$bid] ?? 0);
                $bizStaffAdvances = (int) ($staffAdvanceByEmployer[$bid] ?? 0);

                $businesses[] = [
                    'id' => $biz->id,
                    'name' => $biz->company_name ?? $biz->name,
                    'email' => $biz->email,
                    'staff_count' => $bizStaffCount,
                    'payroll_count_30d' => $bizPayrollCount30d,
                ];

                $combinedWalletBalance += $bizWallet;
                $totalStaff += $bizStaffCount;
                $last30dDisbursed += $bizDisbursed;
                $failedPayrollCount30d += $bizFailed;
                $activeAdvanceCount += ($bizAdvances + $bizStaffAdvances);
            }

            $results[] = [
                'owner_user_id' => (int) $ownerUser->id,
                'owner_company_name' => $ownerUser->company_name ?? $ownerUser->name,
                'owner_user' => [
                    'id' => (int) $ownerUser->id,
                    'company_name' => $ownerUser->company_name ?? $ownerUser->name,
                    'email' => $ownerUser->email,
                ],
                'businesses' => $businesses,
                'combined_wallet_balance' => round($combinedWalletBalance, 2),
                'total_staff' => $totalStaff,
                'last_30d_disbursed' => round($last30dDisbursed, 2),
                'failed_payroll_count_30d' => $failedPayrollCount30d,
                'active_advance_count' => $activeAdvanceCount,
            ];
        }

        return $this->sendResponse($results, 'Owner groups retrieved successfully');
    }
}
