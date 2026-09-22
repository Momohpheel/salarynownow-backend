<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\User;
use Illuminate\Http\Request;

class PartnersController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('type', User::TYPE_PARTNER);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('company_name', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('contact_person', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $partners = $query->latest()->limit(1000)->get();

        $offerCounts = [];
        $enquiryCounts = [];
        try {
            $offerCounts = MarketplaceEnquiry::whereNotNull('partner_id')
                ->select('partner_id', \DB::raw('count(distinct offer_id) as cnt'))
                ->groupBy('partner_id')
                ->pluck('cnt', 'partner_id')
                ->all();
        } catch (\Throwable) {
            $offerCounts = [];
        }
        try {
            $enquiryCounts = MarketplaceEnquiry::whereNotNull('partner_id')
                ->select('partner_id', \DB::raw('count(*) as cnt'))
                ->groupBy('partner_id')
                ->pluck('cnt', 'partner_id')
                ->all();
        } catch (\Throwable) {
            $enquiryCounts = [];
        }

        $rows = $partners->map(function ($p) use ($offerCounts, $enquiryCounts) {
            $status = $p->status ?? ($p->is_approved ? 'approved' : 'pending');
            return [
                'id' => $p->id,
                'company_name' => $p->company_name ?? $p->name ?? 'Partner ' . $p->id,
                'contact_person' => $p->contact_person ?? $p->name ?? '—',
                'email' => $p->email,
                'phone_number' => $p->phone_number ?? '—',
                'status' => $status,
                'revenue_share_pct' => $p->revenue_share_pct ?? 5,
                'offer_count' => (int) ($offerCounts[$p->id] ?? 0),
                'enquiry_count' => (int) ($enquiryCounts[$p->id] ?? 0),
                'rc_number' => $p->rc_number ?? null,
                'industry' => $p->industry ?? null,
                'created_at' => $p->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        })->values();

        $summary = [
            'total' => $partners->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'rejected' => $rows->where('status', 'rejected')->count(),
            'total_offer_count' => (int) array_sum($offerCounts),
            'total_enquiry_count' => (int) array_sum($enquiryCounts),
        ];

        return $this->sendResponse([
            'summary' => $summary,
            'partners' => $rows,
        ], 'Partner accounts retrieved');
    }

    public function show(string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        return $this->sendResponse($this->map($user), 'Partner retrieved');
    }

    public function approve(string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $user->forceFill([
            'status' => 'approved',
            'is_approved' => true,
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse($this->map($user), 'Partner approved');
    }

    public function reject(Request $request, string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $user->forceFill([
            'status' => 'rejected',
            'is_approved' => false,
            'suspension_reason' => $request->input('reason') ?? $user->suspension_reason,
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse($this->map($user), 'Partner rejected');
    }

    public function toggle(string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $current = $user->status ?? ($user->is_approved ? 'approved' : 'pending');
        $next = $current === 'approved' ? 'rejected' : 'approved';
        $user->forceFill([
            'status' => $next,
            'is_approved' => $next === 'approved',
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse($this->map($user), "Partner {$next}");
    }

    public function updateRevenue(Request $request, string $partner)
    {
        $request->validate([
            'revenue_share_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $user->forceFill([
            'revenue_share_pct' => (float) $request->revenue_share_pct,
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse($this->map($user), 'Revenue share updated');
    }

    private function map(User $p): array
    {
        $status = $p->status ?? ($p->is_approved ? 'approved' : 'pending');
        return [
            'id' => $p->id,
            'company_name' => $p->company_name ?? $p->name ?? 'Partner ' . $p->id,
            'contact_person' => $p->contact_person ?? $p->name ?? '—',
            'email' => $p->email,
            'phone_number' => $p->phone_number ?? '—',
            'status' => $status,
            'revenue_share_pct' => $p->revenue_share_pct ?? 5,
            'rc_number' => $p->rc_number ?? null,
            'industry' => $p->industry ?? null,
            'created_at' => $p->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
