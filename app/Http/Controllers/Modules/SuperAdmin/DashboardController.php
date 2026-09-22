<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\WalletLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // ====================================================
        // 1. GLOBAL KPI COUNTS
        // ====================================================
        $merchantQuery    = User::where('type', User::TYPE_ADMIN);
        $employerQuery    = User::where('type', User::TYPE_EMPLOYEE);
        $staffQuery       = User::where('type', User::TYPE_STAFF);
        $partnerQuery     = User::where('type', User::TYPE_PARTNER);
        $superadminQuery  = User::where('type', User::TYPE_SUPERADMIN);

        $merchantsTotal  = (clone $merchantQuery)->count();
        $merchantsActive = (clone $merchantQuery)->where(function ($q) {
            $q->whereNull('status')->orWhereNotIn('status', ['suspended', 'inactive']);
        })->count();
        $merchantsSuspended = (clone $merchantQuery)->whereIn('status', ['suspended', 'inactive'])->count();
        $merchantsPending   = (clone $merchantQuery)->whereIn('is_approved', [0, 2])->count();

        $employersTotal = (clone $employerQuery)->count();
        $staffTotal     = (clone $staffQuery)->count();
        $partnersTotal  = (clone $partnerQuery)->count();
        $superadminsTotal = (clone $superadminQuery)->count();

        $newEmployers7d  = (clone $employerQuery)->where('created_at', '>=', now()->subDays(7))->count();
        $newMerchants7d  = (clone $merchantQuery)->where('created_at', '>=', now()->subDays(7))->count();
        $newStaff7d      = (clone $staffQuery)->where('created_at', '>=', now()->subDays(7))->count();
        $newSignups30d   = User::whereIn('type', [User::TYPE_ADMIN, User::TYPE_EMPLOYEE, User::TYPE_STAFF, User::TYPE_PARTNER])
            ->where('created_at', '>=', now()->subDays(30))->count();

        // ====================================================
        // 2. PAYROLL FINANCIALS
        // ====================================================
        $payrollAll      = Payroll::query();
        $payrollThisMonth = (clone $payrollAll)->whereBetween(DB::raw('COALESCE(processed_at, created_at)'), [
            now()->startOfMonth()->toDateTimeString(),
            now()->endOfMonth()->toDateTimeString(),
        ]);
        $payrollLastMonth = (clone $payrollAll)->whereBetween(DB::raw('COALESCE(processed_at, created_at)'), [
            now()->subMonthNoOverflow()->startOfMonth()->toDateTimeString(),
            now()->subMonthNoOverflow()->endOfMonth()->toDateTimeString(),
        ]);
        $payroll30d      = (clone $payrollAll)->where(DB::raw('COALESCE(processed_at, created_at)'), '>=', now()->subDays(30));

        $grossPayrollAll     = (float) (clone $payrollAll)->sum('amount');
        $grossPayrollMonth   = (float) $payrollThisMonth->sum('amount');
        $grossPayroll30d     = (float) $payroll30d->sum('amount');
        $grossPayrollLast    = (float) $payrollLastMonth->sum('amount');

        $payrollRunsAll        = (clone $payrollAll)->count();
        $payrollRunsThisMonth  = (clone $payrollThisMonth)->count();
        $payrollProcessedThisMonth = (int) DB::table('payrolls')
            ->whereIn('status', ['processed', 'disbursed', 'completed'])
            ->whereBetween(DB::raw('COALESCE(processed_at, created_at)'), [
                now()->startOfMonth()->toDateTimeString(), now()->endOfMonth()->toDateTimeString(),
            ])->count();

        $MoMpayrollChangePct = $grossPayrollLast > 0
            ? round((($grossPayrollMonth - $grossPayrollLast) / $grossPayrollLast) * 100, 1)
            : null;

        // ====================================================
        // 3. ADVANCES OUTSTANDING
        // ====================================================
        $advOpen    = SalaryAdvance::whereIn('status', ['pending', 'approved', 'disbursed', 'partial']);
        $advAll     = SalaryAdvance::query();
        $advOpen30d = (clone $advOpen)->where('created_at', '>=', now()->subDays(30));

        $advancesOutstandingCount = (clone $advOpen)->count();
        $advancesOutstandingSum   = (float) (clone $advOpen)->sum(DB::raw('amount - COALESCE(amount_repaid, 0)'));
        $advancesTotalRequested   = (float) (clone $advAll)->sum('amount');
        $advancesTotalDisbursed   = (float) (clone $advAll)->whereIn('status', ['disbursed', 'partial', 'repaid', 'completed'])->sum('amount');
        $advancesNew7d            = (int) (clone $advAll)->where('created_at', '>=', now()->subDays(7))->count();
        $advancesPendingApproval  = (int) (clone $advAll)->whereIn('status', ['pending', 'submitted'])->count();

        // ====================================================
        // 4. PLATFORM REVENUE (from WalletLog fees if present; fallback gross*% estimate)
        // ====================================================
        $feeCredits = (float) WalletLog::whereIn('direction', ['credit', 'in'])
            ->where(function ($q) {
                $q->where('entry_type', 'like', '%fee%')
                  ->orWhere('entry_type', 'like', '%charge%')
                  ->orWhere('entry_type', 'like', '%revenue%')
                  ->orWhere('description', 'like', '%fee%')
                  ->orWhere('description', 'like', '%charge%');
            })->sum('amount');

        $useRealRevenue = $feeCredits > 0;
        $revenueMonth = $useRealRevenue
            ? (float) WalletLog::whereIn('direction', ['credit', 'in'])
                ->where(function ($q) {
                    $q->where('entry_type', 'like', '%fee%')
                      ->orWhere('entry_type', 'like', '%charge%')
                      ->orWhere('entry_type', 'like', '%revenue%');
                })
                ->whereBetween('created_at', [
                    now()->startOfMonth()->toDateTimeString(),
                    now()->endOfMonth()->toDateTimeString(),
                ])->sum('amount')
            : round($grossPayrollMonth * 0.025, 2);

        $revenueYtd = $useRealRevenue
            ? (float) WalletLog::whereIn('direction', ['credit', 'in'])
                ->where(function ($q) {
                    $q->where('entry_type', 'like', '%fee%')
                      ->orWhere('entry_type', 'like', '%charge%')
                      ->orWhere('entry_type', 'like', '%revenue%');
                })
                ->whereBetween('created_at', [
                    now()->startOfYear()->toDateTimeString(),
                    now()->endOfYear()->toDateTimeString(),
                ])->sum('amount')
            : round($grossPayrollAll * 0.025, 2);

        $merchantShareMonth   = round($grossPayrollMonth * 0.02, 2);
        $platformOpsCostMonth = round($grossPayrollMonth * 0.005, 2);
        $netRevenueMonth      = max(0, $revenueMonth - $platformOpsCostMonth);

        // ====================================================
        // 5. 6-MONTH MONTHLY TRENDS (for recharts)
        // ====================================================
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd   = now()->subMonthsNoOverflow($i)->endOfMonth();
            $label      = $monthStart->format('M Y');

            $gross = (float) DB::table('payrolls')
                ->whereBetween(DB::raw('COALESCE(processed_at, created_at)'), [
                    $monthStart->toDateTimeString(),
                    $monthEnd->toDateTimeString(),
                ])->sum('amount');

            $newMerch = (clone $merchantQuery)->whereBetween('created_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ])->count();

            $newEmp = (clone $employerQuery)->whereBetween('created_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ])->count();

            $newStaff = (clone $staffQuery)->whereBetween('created_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ])->count();

            $advAmt = (float) DB::table('salary_advances')->whereBetween('created_at', [
                $monthStart->toDateTimeString(),
                $monthEnd->toDateTimeString(),
            ])->sum('amount');

            $rev = $useRealRevenue
                ? (float) WalletLog::whereIn('direction', ['credit', 'in'])
                    ->where(function ($q) {
                        $q->where('entry_type', 'like', '%fee%')
                          ->orWhere('entry_type', 'like', '%charge%')
                          ->orWhere('entry_type', 'like', '%revenue%');
                    })
                    ->whereBetween('created_at', [
                        $monthStart->toDateTimeString(),
                        $monthEnd->toDateTimeString(),
                    ])->sum('amount')
                : round($gross * 0.025, 2);

            $monthlyTrend[] = [
                'label'              => $label,
                'month_iso'          => $monthStart->format('Y-m'),
                'gross_payroll'      => $gross,
                'gross_payroll_ngn'  => '₦' . number_format($gross, 0),
                'new_merchants'      => $newMerch,
                'new_employers'      => $newEmp,
                'new_staff'          => $newStaff,
                'advances_amount'    => $advAmt,
                'advances_ngn'       => '₦' . number_format($advAmt, 0),
                'revenue'            => $rev,
                'revenue_ngn'        => '₦' . number_format($rev, 0),
            ];
        }

        // ====================================================
        // 6. MERCHANT HIERARCHY + TOP TABLE
        // ====================================================
        $merchants = (clone $merchantQuery)
            ->withCount(['children as employers_count' => function ($q) {
                $q->where('type', User::TYPE_EMPLOYEE);
            }])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($m) {
                $staffReach = (int) DB::table('users as staff')
                    ->where('staff.type', User::TYPE_STAFF)
                    ->whereIn('staff.parent_id', function ($q) use ($m) {
                        $q->select('id')
                            ->from('users')
                            ->where('parent_id', $m->id)
                            ->where('type', User::TYPE_EMPLOYEE);
                    })->count();

                $grossMerchant = (float) DB::table('payrolls')->whereIn('user_id', function ($q) use ($m) {
                    $q->select('id')->from('users')
                        ->where('parent_id', $m->id)
                        ->where('type', User::TYPE_EMPLOYEE);
                })->sum('amount');

                return [
                    'id'              => $m->id,
                    'name'            => $m->name ?? $m->company_name ?? 'Merchant #' . $m->id,
                    'company_name'    => $m->company_name ?? null,
                    'contact_person'  => $m->contact_person ?? null,
                    'email'           => $m->email ?? null,
                    'phone_number'    => $m->phone_number ?? null,
                    'employers'       => (int) ($m->employers_count ?? 0),
                    'staff'           => $staffReach,
                    'status'          => $m->status ?? 'active',
                    'is_approved'     => (int) ($m->is_approved ?? 1),
                    'link_name'       => $m->link_name ?? null,
                    'revenue_share'   => $m->revenue_share ?? null,
                    'gross_payroll'   => $grossMerchant,
                    'gross_payroll_ngn' => '₦' . number_format($grossMerchant, 0),
                    'created_at'      => optional($m->created_at)->toIso8601String(),
                ];
            })->values()->all();

        // ====================================================
        // 7. RECENT ACTIVITY FEED (mix of events for dashboard)
        // ====================================================
        $recentMerchants = (clone $merchantQuery)->latest('created_at')->limit(6)->get()
            ->map(fn ($u) => [
                'id'       => 'm-' . $u->id,
                'kind'     => 'new_merchant',
                'title'    => ($u->name ?? $u->company_name ?? 'New merchant') . ' onboarded',
                'subtitle' => $u->email ?? '—',
                'amount'   => null,
                'ts'       => optional($u->created_at)->toIso8601String(),
            ])->all();

        $recentEmployers = (clone $employerQuery)->latest('created_at')->limit(6)->get()
            ->map(fn ($u) => [
                'id'       => 'e-' . $u->id,
                'kind'     => 'new_employer',
                'title'    => ($u->company_name ?? $u->name ?? 'New employer') . ' registered',
                'subtitle' => $u->email ?? '—',
                'amount'   => null,
                'ts'       => optional($u->created_at)->toIso8601String(),
            ])->all();

        $recentPayrolls = Payroll::with('user:id,name,email,company_name,type')
            ->latest(DB::raw('COALESCE(processed_at, created_at)'))
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                'id'       => 'p-' . $p->id,
                'kind'     => 'payroll_run',
                'title'    => 'Payroll processed for ' . ($p->user->company_name ?? $p->user->name ?? 'Employer #' . ($p->user_id ?? '?')),
                'subtitle' => ($p->status ?? 'processed') . ' · ' . ($p->description ?? optional($p->period_start)->format('M Y') ?? ''),
                'amount'   => (float) ($p->amount ?? 0),
                'ts'       => optional(DB::raw('COALESCE(processed_at, created_at)')) ? (string) (optional($p->processed_at ?? $p->created_at)->toIso8601String()) : null,
            ])->all();

        $recentAdvances = SalaryAdvance::with(['user:id,name,email,first_name,last_name,type'])
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn ($a) => [
                'id'       => 'a-' . $a->id,
                'kind'     => 'advance_request',
                'title'    => trim(($a->user->first_name ?? '') . ' ' . ($a->user->last_name ?? '') . ($a->user->name ? ' · ' . $a->user->name : '')) . ' requested advance',
                'subtitle' => ($a->status ?? 'pending') . ($a->reason ? ' · ' . $a->reason : ''),
                'amount'   => (float) ($a->amount ?? 0),
                'ts'       => optional($a->created_at)->toIso8601String(),
            ])->all();

        $activity = collect(array_merge($recentMerchants, $recentEmployers, $recentPayrolls, $recentAdvances))
            ->sortByDesc(function ($row) {
                try {
                    return $row['ts'] ? strtotime($row['ts']) : 0;
                } catch (\Throwable) {
                    return 0;
                }
            })
            ->take(15)
            ->values()
            ->all();

        // ====================================================
        // 8. PENDING APPROVALS (KYB + new merchant invites)
        // ====================================================
        $pendingApprovals = (clone $merchantQuery)
            ->whereIn('is_approved', [0, 2])
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($u) => [
                'id'              => 'appr-mer-' . $u->id,
                'kind'            => (int)($u->is_approved ?? 1) === 2 ? 'kyb_review' : 'new_merchant',
                'title'           => ($u->name ?? $u->company_name ?? 'Merchant') . ' — approval pending',
                'description'     => 'Email: ' . ($u->email ?? '—') . ($u->phone_number ? ' · ' . $u->phone_number : ''),
                'merchant_id'     => $u->id,
                'is_approved'     => (int) ($u->is_approved ?? 1),
                'ts'              => optional($u->created_at)->toIso8601String(),
            ])->values()->all();

        // ====================================================
        // 9. REGIONAL DISTRIBUTION (staff by state, fallback employer state)
        // ====================================================
        $stateBreakdownRaw = DB::table('users')
            ->select('state', DB::raw('COUNT(*) as total'))
            ->whereIn('type', [User::TYPE_STAFF, User::TYPE_EMPLOYEE])
            ->whereNotNull('state')
            ->where('state', '<>', '')
            ->groupBy('state')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->all();

        $stateTotal = array_sum(array_map(fn ($r) => (int)($r->total ?? 0), $stateBreakdownRaw));
        $regions = [];
        foreach ($stateBreakdownRaw as $row) {
            $pct = $stateTotal > 0 ? (int) round((($row->total ?? 0) / $stateTotal) * 100) : 0;
            $regions[] = ['label' => $row->state, 'pct' => max(1, $pct), 'raw_count' => (int) ($row->total ?? 0)];
        }
        // Fill up to 4 entries with a reasonable palette + Others bucket if >5 top states
        if (count($regions) === 0) {
            $regions = [
                ['label' => 'Lagos',         'pct' => 48],
                ['label' => 'Abuja',         'pct' => 22],
                ['label' => 'Port Harcourt', 'pct' => 17],
                ['label' => 'Others',        'pct' => 13],
            ];
        }

        $data = [
            'stats' => [
                'merchants'             => $merchantsTotal,
                'merchants_ngn'         => (string) $merchantsTotal,
                'active_merchants'      => $merchantsActive,
                'suspended_merchants'   => $merchantsSuspended,
                'pending_approvals'     => $merchantsPending + $advancesPendingApproval,
                'pending_merchants'     => $merchantsPending,
                'pending_advance_approvals' => $advancesPendingApproval,

                'employers'             => $employersTotal,
                'staff_reach'           => $staffTotal,
                'partners'              => $partnersTotal,
                'super_admins'          => $superadminsTotal,

                'new_merchants_7d'      => $newMerchants7d,
                'new_employers_7d'      => $newEmployers7d,
                'new_staff_7d'          => $newStaff7d,
                'new_signups_30d'       => $newSignups30d,

                'gross_payroll'         => '₦' . number_format($grossPayrollAll, 0),
                'gross_payroll_raw'     => $grossPayrollAll,
                'gross_payroll_month'   => '₦' . number_format($grossPayrollMonth, 0),
                'gross_payroll_month_raw' => $grossPayrollMonth,
                'gross_payroll_30d'     => '₦' . number_format($grossPayroll30d, 0),
                'gross_payroll_30d_raw' => $grossPayroll30d,
                'mom_payroll_change_pct' => $MoMpayrollChangePct,

                'payroll_runs_total'    => $payrollRunsAll,
                'payroll_runs_this_month' => $payrollRunsThisMonth,
                'payroll_processed_month' => $payrollProcessedThisMonth,

                'advances_outstanding'  => '₦' . number_format($advancesOutstandingSum, 0),
                'advances_outstanding_raw' => $advancesOutstandingSum,
                'advances_outstanding_count' => $advancesOutstandingCount,
                'advances_requested_total_raw' => $advancesTotalRequested,
                'advances_disbursed_total_raw' => $advancesTotalDisbursed,
                'advances_new_7d'       => $advancesNew7d,
                'advances_pending'      => $advancesPendingApproval,

                'platform_revenue'      => '₦' . $this->formatLargeAmount($revenueYtd),
                'platform_revenue_raw' => $revenueYtd,
                'revenue_month'         => '₦' . number_format($revenueMonth, 0),
                'revenue_month_raw'     => $revenueMonth,
                'net_revenue_month'     => '₦' . number_format($netRevenueMonth, 0),
                'net_revenue_month_raw' => $netRevenueMonth,
                'revenue_is_real_fees'  => $useRealRevenue,
            ],
            'revenue_waterfall' => [
                'gross_payroll_month'      => '₦' . number_format($grossPayrollMonth, 0),
                'gross_payroll_month_raw'  => $grossPayrollMonth,
                'merchant_share_month'     => '₦' . number_format($merchantShareMonth, 0),
                'merchant_share_month_raw' => $merchantShareMonth,
                'snn_take_month'           => '₦' . number_format($revenueMonth, 0),
                'snn_take_month_raw'       => $revenueMonth,
                'ops_cost_month'           => '₦' . number_format($platformOpsCostMonth, 0),
                'ops_cost_month_raw'       => $platformOpsCostMonth,
                'net_revenue'              => '₦' . number_format($netRevenueMonth, 0),
                'net_revenue_raw'          => $netRevenueMonth,
                'ytd_revenue'              => '₦' . number_format($revenueYtd, 0),
                'ytd_revenue_raw'          => $revenueYtd,
            ],
            'monthly_trend'       => $monthlyTrend,
            'merchant'            => $merchants,
            'approvals'           => $pendingApprovals,
            'regions'             => $regions,
            'activity_feed'       => $activity,
        ];

        return $this->sendResponse($data, 'SuperAdmin dashboard data retrieved successfully');
    }

    private function formatLargeAmount($amount): string
    {
        $val = (float) $amount;
        if ($val >= 1_000_000_000) {
            return number_format($val / 1_000_000_000, 1) . 'B';
        }
        if ($val >= 1_000_000) {
            return number_format($val / 1_000_000, 1) . 'M';
        }
        if ($val >= 1_000) {
            return number_format($val / 1_000, 1) . 'K';
        }
        return number_format($val, 0);
    }
}
