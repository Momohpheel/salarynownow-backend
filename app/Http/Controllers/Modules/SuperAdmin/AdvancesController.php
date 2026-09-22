<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Http\Request;

class AdvancesController extends Controller
{
    public function index(Request $request)
    {
        $employerIds = User::where('type', User::TYPE_EMPLOYEE)->pluck('id');

        $query = SalaryAdvance::with([
            'staff:id,name,email',
            'user:id,company_name,name',
        ])->whereIn('user_id', $employerIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('staff', function ($staffQuery) use ($search) {
                    $staffQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('company_name', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status') && strtolower($request->status) !== 'all') {
            $query->where('status', strtolower($request->status));
        }

        $advances = $query->latest()->limit(1000)->get();

        $data = [
            'summary' => [
                'total_advances' => $advances->count(),
                'approved_advances' => $advances->where('status', 'approved')->count(),
                'pending_advances' => $advances->where('status', 'pending')->count(),
                'total_amount' => '₦' . number_format((float) $advances->sum('amount'), 2),
            ],
            'items' => $advances->map(function ($advance) {
                return [
                    'id' => $advance->id,
                    'employee' => $advance->staff->name ?? 'Unknown',
                    'company' => $advance->user->company_name ?? $advance->user->name ?? 'Unknown',
                    'amount' => '₦' . number_format((float) $advance->amount, 2),
                    'lender' => 'Sugar Payroll',
                    'status' => ucfirst($advance->status),
                    'date' => $advance->created_at->format('d M Y'),
                ];
            }),
        ];

        return $this->sendResponse($data, 'Advance oversight data retrieved successfully');
    }
}
