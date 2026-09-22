<?php

namespace App\Http\Controllers\Modules\Partner;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\WalletLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function me(Request $request)
    {
        $u = $request->user();
        return $this->sendResponse($u->only([
            'id', 'name', 'email', 'phone_number', 'type',
            'company_name', 'category', 'website_url', 'description',
            'status', 'is_active', 'is_approved', 'created_at',
        ]), 'Partner profile');
    }

    public function dashboard(Request $request)
    {
        $partner = $request->user();
        $partnerId = $partner->id;
        $startOfMonth = now()->startOfMonth();

        $activeClients = MarketplaceEnquiry::where('partner_id', $partnerId)
            ->where(function ($q) {
                $q->where('status', 'open')
                  ->orWhere('status', 'replied')
                  ->orWhere('status', 'converted');
            })
            ->distinct()
            ->count(DB::raw("COALESCE((metadata->>'$.employer_id'), submitter_id)"));

        $revenueThisMonthRaw = (float) WalletLog::where('wallet_logs.type', 'credit')
            ->where('wallet_logs.created_at', '>=', $startOfMonth)
            ->join('wallets', 'wallets.id', '=', 'wallet_logs.wallet_id')
            ->where('wallets.user_id', $partnerId)
            ->sum('wallet_logs.amount');
        $revenueThisMonth = '₦' . number_format($revenueThisMonthRaw, 0);

        $settledPayouts = (int) DB::table('transactions')
            ->where(function ($q) use ($partnerId) {
                $q->where('user_id', $partnerId)
                  ->orWhereJsonContains('metadata->partner_id', $partnerId);
            })
            ->where('status', 'success')
            ->where('type', 'payout')
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $pendingSettlements = (int) MarketplaceEnquiry::where('partner_id', $partnerId)
            ->where('status', 'pending_settlement')
            ->count();

        $pendingEnquiries = MarketplaceEnquiry::where('partner_id', $partnerId)
            ->whereNull('replied_at')
            ->where('status', '!=', 'closed')
            ->count();

        $recentEnquiries = MarketplaceEnquiry::with('submitter')
            ->where('partner_id', $partnerId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'offer_name' => $e->offer_name,
                    'email' => $e->email,
                    'phone_number' => $e->phone_number,
                    'message_preview' => mb_substr($e->message ?? '', 0, 120),
                    'status' => $e->status,
                    'replied_at' => $e->replied_at?->toIso8601String(),
                    'created_at' => $e->created_at->toIso8601String(),
                    'submitter' => $e->submitter ? $e->submitter->only(['id', 'name', 'email', 'phone_number', 'type']) : null,
                ];
            });

        $monthlyEnquiriesTrend = MarketplaceEnquiry::where('partner_id', $partnerId)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%b %Y') as label"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied"),
            )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('label')
            ->orderBy(DB::raw('MIN(created_at)'))
            ->get();

        $data = [
            'active_clients' => max(0, $activeClients),
            'revenue_this_month' => $revenueThisMonth,
            'revenue_this_month_raw' => $revenueThisMonthRaw,
            'settled_payouts' => $settledPayouts,
            'pending_settlements' => $pendingSettlements,
            'pending_enquiries' => $pendingEnquiries,
            'recent_enquiries' => $recentEnquiries,
            'monthly_enquiries_trend' => $monthlyEnquiriesTrend,
        ];

        return $this->sendResponse($data, 'Partner dashboard');
    }
}
