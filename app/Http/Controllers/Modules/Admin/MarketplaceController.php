<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\Notification;
use App\Models\SalaryAdvance;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    public function index(Request $request)
    {
        $partners = User::where('type', User::TYPE_PARTNER)
            ->latest()
            ->limit(500)
            ->get();

        $partnerRows = $partners->map(function ($p) {
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
        });

        $offers = collect([]);
        try {
            // Offers are stored via enquiries offer_id/offers metadata; build synthetic offers from enquiries + partners
            $enquiryRows = MarketplaceEnquiry::latest()
                ->with(['partner:id,company_name,name,email', 'submitter'])
                ->limit(1000)
                ->get();

            $offerMap = [];
            foreach ($enquiryRows as $e) {
                if (empty($e->offer_id)) continue;
                $key = (string) $e->offer_id;
                if (!isset($offerMap[$key])) {
                    $offerMap[$key] = [
                        'id' => $e->offer_id,
                        'partner_id' => $e->partner_id,
                        'partner_accounts' => [
                            'company_name' => $e->partner?->company_name ?? $e->partner?->name ?? 'Partner',
                        ],
                        'title' => $e->offer_name ?? 'Offer #' . $e->offer_id,
                        'category' => ($e->metadata['category'] ?? null) ?? 'Other',
                        'price' => $e->metadata['price'] ?? null,
                        'discount_pct' => $e->metadata['discount_pct'] ?? null,
                        'status' => $e->metadata['status'] ?? 'active',
                        'flag_note' => $e->metadata['flag_note'] ?? null,
                        'impressions' => (int) ($e->metadata['impressions'] ?? 0),
                        'clicks' => (int) ($e->metadata['clicks'] ?? 0),
                        'conversions' => 0,
                        'created_at' => $e->created_at?->toIso8601String(),
                    ];
                }
                $status = $e->status ?? 'open';
                if ($status === 'converted') {
                    $offerMap[$key]['conversions'] = ($offerMap[$key]['conversions'] ?? 0) + 1;
                }
                $offerMap[$key]['impressions'] += 1;
            }
            $offers = collect(array_values($offerMap));
        } catch (\Throwable) {
            $offers = collect([]);
        }

        if ($request->filled('offer_status') && $request->offer_status !== 'all') {
            $offers = $offers->filter(fn ($o) => ($o['status'] ?? 'active') === $request->offer_status)->values();
        }

        $enquiries = MarketplaceEnquiry::latest()
            ->with(['partner:id,company_name,name', 'submitter'])
            ->limit(1000)
            ->get()
            ->map(function ($e) {
                $submitterName = '—';
                if ($e->submitter) {
                    $submitterName = $e->submitter->company_name ?? $e->submitter->name ?? $e->submitter->email ?? '—';
                }
                return [
                    'id' => $e->id,
                    'partner_id' => $e->partner_id,
                    'offer_id' => $e->offer_id,
                    'offer_name' => $e->offer_name ?? '—',
                    'name' => $e->name ?? $submitterName,
                    'email' => $e->email ?? ($e->submitter->email ?? null),
                    'phone_number' => $e->phone_number ?? ($e->submitter->phone_number ?? null),
                    'company_name' => $e->company_name ?? ($e->submitter->company_name ?? null),
                    'message' => $e->message ?? '',
                    'status' => $e->status ?? 'open',
                    'partner_company' => $e->partner?->company_name ?? $e->partner?->name ?? '—',
                    'submitter_type' => $e->submitter_type,
                    'submitter_name' => $submitterName,
                    'replied_at' => $e->replied_at?->toIso8601String(),
                    'created_at' => $e->created_at?->toIso8601String(),
                ];
            })
            ->values();

        $platformSettings = $this->getPlatformSettings();
        $staffList = User::where('type', User::TYPE_STAFF)
            ->select('id', 'first_name', 'last_name', 'name', 'email', 'parent_id as company_id')
            ->orderByRaw("COALESCE(first_name, name)")
            ->limit(200)
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'first_name' => $s->first_name ?? explode(' ', (string) $s->name, 2)[0] ?? 'Staff',
                    'last_name' => $s->last_name ?? (explode(' ', (string) $s->name, 2)[1] ?? ''),
                    'email' => $s->email,
                    'company_id' => $s->company_id,
                    'name' => $s->name ?? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')),
                ];
            })
            ->values();

        $metrics = $this->buildMetrics($partners, $offers, $enquiries);

        return $this->sendResponse([
            'partners' => $partnerRows->values(),
            'offers' => $offers,
            'enquiries' => $enquiries,
            'settings' => $platformSettings,
            'staff' => $staffList,
            'metrics' => $metrics,
        ], 'Marketplace oversight loaded');
    }

    public function approvePartner(Request $request, string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $user->forceFill([
            'status' => 'approved',
            'is_approved' => true,
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse(['id' => $user->id, 'status' => 'approved'], 'Partner approved');
    }

    public function rejectPartner(Request $request, string $partner)
    {
        $user = User::where('type', User::TYPE_PARTNER)->findOrFail($partner);
        $reason = $request->input('reason');
        $user->forceFill([
            'status' => 'rejected',
            'is_approved' => false,
            'suspension_reason' => $reason ?? $user->suspension_reason,
            'updated_at' => now(),
        ])->save();
        return $this->sendResponse(['id' => $user->id, 'status' => 'rejected'], 'Partner rejected');
    }

    public function updateOffer(Request $request, string $offerId)
    {
        $status = $request->input('status');
        $flagNote = $request->input('flag_note');
        $discountPct = $request->input('discount_pct');
        $price = $request->input('price');

        $enquiries = MarketplaceEnquiry::where('offer_id', $offerId)->get();
        foreach ($enquiries as $e) {
            $meta = is_array($e->metadata) ? $e->metadata : [];
            if ($status !== null) $meta['status'] = $status;
            if ($flagNote !== null) $meta['flag_note'] = $flagNote;
            if ($discountPct !== null) $meta['discount_pct'] = $discountPct;
            if ($price !== null) $meta['price'] = $price;
            $e->metadata = $meta;
            $e->save();
        }

        return $this->sendResponse(['id' => $offerId, 'status' => $status, 'updated' => $enquiries->count()], 'Offer updated');
    }

    public function bulkSuspendOffers(Request $request)
    {
        $request->validate(['ids' => ['required', 'array']]);
        $ids = $request->ids;
        $count = 0;
        foreach ($ids as $offerId) {
            $enquiries = MarketplaceEnquiry::where('offer_id', (string) $offerId)->get();
            foreach ($enquiries as $e) {
                $meta = is_array($e->metadata) ? $e->metadata : [];
                $meta['status'] = 'paused';
                $e->metadata = $meta;
                $e->save();
                $count++;
            }
        }
        return $this->sendResponse(['count' => $count], 'Offers suspended');
    }

    public function getPlatformSettings()
    {
        $settings = Setting::all()->pluck('value', 'key');
        $crossSellEnabled = filter_var($settings['cross_sell_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        return [
            'id' => 1,
            'cross_sell_enabled' => $crossSellEnabled,
            'cross_sell_disabled_reason' => $settings['cross_sell_disabled_reason'] ?? null,
            'updated_at' => $settings['cross_sell_updated_at'] ?? now()->toIso8601String(),
        ];
    }

    public function savePlatformSettings(Request $request)
    {
        $enabled = $request->boolean('cross_sell_enabled');
        $reason = $request->input('cross_sell_disabled_reason');

        Setting::updateOrCreate(['key' => 'cross_sell_enabled'], ['value' => var_export($enabled, true)]);
        Setting::updateOrCreate(['key' => 'cross_sell_disabled_reason'], ['value' => $enabled ? null : $reason]);
        Setting::updateOrCreate(['key' => 'cross_sell_updated_at'], ['value' => now()->toIso8601String()]);

        return $this->sendResponse($this->getPlatformSettings(), 'Platform settings saved');
    }

    public function fireTrigger(Request $request)
    {
        $request->validate([
            'staff_id' => ['required', 'exists:users,id'],
            'type' => ['required', 'string'],
        ]);

        $staffId = $request->staff_id;
        $type = $request->type;

        $defs = [
            'post_payday' => [
                'category' => 'deals',
                'title' => 'Smart picks now your salary has landed',
                'body' => 'Your salary has landed. Here are 3 deals matched to your spending profile.',
                'icon' => 'sparkles',
                'deep_link' => '/employee/marketplace',
            ],
            'new_offer' => [
                'category' => 'deals',
                'title' => 'A new offer matches your profile',
                'body' => 'We just added a new partner offer that matches your interests.',
                'icon' => 'tag',
                'deep_link' => '/employee/marketplace',
            ],
            'offer_expiring' => [
                'category' => 'deals',
                'title' => 'A saved deal is expiring soon',
                'body' => 'One of your saved deals expires in 48 hours. Don\'t miss it.',
                'icon' => 'clock',
                'deep_link' => '/employee/marketplace?view=saved',
            ],
            'partner_response' => [
                'category' => 'deals',
                'title' => 'A partner has responded to your enquiry',
                'body' => 'Check the response and next steps.',
                'icon' => 'message',
                'deep_link' => '/employee/marketplace/enquiries',
            ],
            'advance_cleared' => [
                'category' => 'advances',
                'title' => 'Your advance is fully repaid',
                'body' => 'Your advance is fully repaid. Ready to explore what\'s next?',
                'icon' => 'check-circle',
                'deep_link' => '/employee/marketplace?filter=auto-travel',
            ],
        ];

        $def = $defs[$type] ?? null;
        if (!$def) {
            return $this->sendResponse(['error' => 'Unknown trigger type'], 'Unknown trigger', false);
        }

        if ($type === 'advance_cleared') {
            $staff = User::find($staffId);
            $companyId = $staff->parent_id ?? null;

            $existing = SalaryAdvance::where('staff_id', $staffId)
                ->where('status', '!=', 'repaid')
                ->orderBy('id')
                ->first();

            if ($existing) {
                $existing->status = 'repaid';
                $existing->save();
            } else {
                $adv = SalaryAdvance::create([
                    'user_id' => $companyId,
                    'staff_id' => $staffId,
                    'amount' => 1000,
                    'status' => 'repaid',
                ]);
            }
        }

        // Notifications table staff_id doesn't exist — use user_id column pointing to staff user
        Notification::create([
            'user_id' => $staffId,
            'category' => $def['category'],
            'type' => $type,
            'title' => $def['title'],
            'body' => $def['body'],
            'icon' => $def['icon'],
            'deep_link' => $def['deep_link'],
            'metadata' => ['test' => true, 'fired_by' => 'admin'],
        ]);

        return $this->sendResponse(['type' => $type, 'staff_id' => $staffId], 'Trigger fired');
    }

    private function buildMetrics($partners, $offers, $enquiries)
    {
        $monthStart = Carbon::now()->startOfMonth();
        $activePartners = $partners->filter(function ($p) {
            $s = $p['status'] ?? 'pending';
            return $s === 'approved';
        })->count();

        $activeOffers = $offers->filter(fn ($o) => ($o['status'] ?? 'active') === 'active')->count();
        $enquiriesThisMonth = $enquiries->filter(function ($e) use ($monthStart) {
            try {
                return Carbon::parse($e['created_at']) >= $monthStart;
            } catch (\Throwable) {
                return false;
            }
        })->count();

        $conversions = $enquiries->filter(fn ($e) => ($e['status'] ?? 'open') === 'converted')->count();
        $revenue = $conversions * 5000;

        return [
            'active_partners' => $activePartners,
            'total_offers_live' => $activeOffers,
            'enquiries_this_month' => $enquiriesThisMonth,
            'revenue_earned' => $revenue,
        ];
    }
}
