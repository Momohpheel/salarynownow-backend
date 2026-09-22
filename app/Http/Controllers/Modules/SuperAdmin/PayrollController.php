<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $employerIds = User::where('type', User::TYPE_EMPLOYEE)->pluck('id');

        $query = Payroll::with('user:id,name,company_name')
            ->whereIn('user_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', static function ($userQuery) use ($search) {
                    $userQuery->where('company_name', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                })->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }

        $summaryBuilder = (clone $query);
        $summaryRows = $summaryBuilder->get();
        $summary = [
            'payroll_runs' => $summaryRows->count(),
            'completed' => $summaryRows->where('status', Payroll::STATUS_COMPLETED)->count(),
            'total_processed' => '₦' . number_format(
                (float) $summaryRows->where('status', Payroll::STATUS_COMPLETED)->sum('amount'),
                2
            ),
            'people_included' => (int) $summaryRows->sum('staff_count'),
        ];

        $transformPayroll = static function ($payroll) {
            return [
                'id' => $payroll->id,
                'reference' => $payroll->reference,
                'company' => $payroll->user->company_name ?? $payroll->user->name ?? '—',
                'company_id' => $payroll->user_id,
                'run_date' => $payroll->processed_at?->format('d M Y') ?? '—',
                'pay_period' => $payroll->period_start && $payroll->period_end
                    ? $payroll->period_start->format('d M') . ' — ' . $payroll->period_end->format('d M Y')
                    : '—',
                'staff_count' => (int) $payroll->staff_count,
                'total_amount' => '₦' . number_format((float) $payroll->amount, 2),
                'total_amount_raw' => $payroll->amount,
                'status' => ucfirst($payroll->status),
                'status_raw' => $payroll->status,
                'created' => $payroll->created_at->format('d M Y'),
            ];
        };

        $perPage = (int) $request->input('per_page', $request->input('page') ? 10 : 0);

        if ($perPage > 0) {
            $paginator = $query->latest('processed_at')->paginate($perPage);
            $items = collect($paginator->items())->map($transformPayroll)->values();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        } else {
            $allPayrolls = $query->latest('processed_at')->get();
            $items = $allPayrolls->map($transformPayroll);
            $pagination = [
                'current_page' => 1,
                'per_page' => $allPayrolls->count(),
                'total' => $allPayrolls->count(),
                'last_page' => 1,
                'from' => $allPayrolls->count() ? 1 : null,
                'to' => $allPayrolls->count() ? $allPayrolls->count() : null,
            ];
        }

        $data = [
            'summary' => $summary,
            'items' => $items,
            'pagination' => $pagination,
        ];

        return $this->sendResponse($data, 'Payroll control data retrieved successfully');
    }
}
