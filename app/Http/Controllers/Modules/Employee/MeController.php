<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\User;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    use ResolvesBusinessContext;

    public function ownedBusinesses(Request $request)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();
        $businesses = $ogOwner->ownedBusinesses();

        if ($businesses->isEmpty()) {
            $businesses = collect([$actingUser->type === User::TYPE_EMPLOYEE ? $actingUser : $actingUser->employer()->first() ?? $actingUser]);
        }

        $ownerId = $ogOwner->id;
        $businessIds = $businesses->pluck('id')->all();

        $staffCounts = User::whereIn('parent_id', $businessIds)
            ->where('type', User::TYPE_STAFF)
            ->select('parent_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('parent_id')
            ->get()
            ->keyBy('parent_id');

        $latestPayrollSubquery = Payroll::select(DB::raw('MAX(id) as max_id, user_id'))
            ->whereIn('user_id', $businessIds)
            ->groupBy('user_id');

        $latestPayrolls = Payroll::joinSub($latestPayrollSubquery, 'lp', function ($join) {
            $join->on('payrolls.id', '=', 'lp.max_id');
        })
            ->select('payrolls.user_id', 'payrolls.id as payroll_id', 'payrolls.status')
            ->get()
            ->keyBy('user_id');

        $result = $businesses->map(function ($biz) use ($ownerId, $staffCounts, $latestPayrolls) {
            $bizId = $biz->id;
            $latest = $latestPayrolls->get($bizId);
            return [
                'id' => $biz->id,
                'company_name' => $biz->company_name ?? $biz->name,
                'email' => $biz->email,
                'staff_count' => (int) ($staffCounts->get($bizId)?->cnt ?? 0),
                'latest_payroll_status' => $latest?->status ?? null,
                'latest_payroll_id' => $latest?->payroll_id ?? null,
                'is_owner' => (int) $bizId === (int) $ownerId,
            ];
        });

        $ownerRow = $result->firstWhere('is_owner', true);
        $otherRows = $result->where('is_owner', false)->sortBy('company_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $sorted = collect();
        if ($ownerRow) {
            $sorted->push($ownerRow);
        }
        foreach ($otherRows as $row) {
            $sorted->push($row);
        }

        return $this->sendResponse($sorted->values()->all(), 'Owned businesses retrieved successfully');
    }
}
