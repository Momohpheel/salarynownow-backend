<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class MarketplaceEnquiryController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'partner_id' => ['nullable', 'integer'],
            'partner_slug_or_email' => ['nullable', 'string', 'max:255'],
            'offer_id' => ['nullable', 'integer'],
            'offer_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $partnerId = $request->partner_id;
        if ($partnerId) {
            $partner = User::find($partnerId);
            if ($partner && $partner->type !== User::TYPE_PARTNER) {
                $partnerId = null;
            }
        }
        if (!$partnerId && ($request->partner_slug_or_email || $request->offer_name || $request->email)) {
            $lookup = User::where('type', User::TYPE_PARTNER);
            if ($request->partner_slug_or_email && filter_var($request->partner_slug_or_email, FILTER_VALIDATE_EMAIL)) {
                $lookup->where('email', $request->partner_slug_or_email);
            } elseif ($request->partner_slug_or_email) {
                $lookup->where(function ($q) use ($request) {
                    $q->where('company_name', 'like', '%' . $request->partner_slug_or_email . '%')
                      ->orWhere('name', 'like', '%' . $request->partner_slug_or_email . '%');
                });
            } elseif ($request->offer_name) {
                $lookup->where(function ($q) use ($request) {
                    $q->where('company_name', 'like', '%' . $request->offer_name . '%')
                      ->orWhere('name', 'like', '%' . $request->offer_name . '%');
                });
            }
            $matched = $lookup->first();
            if ($matched) {
                $partnerId = $matched->id;
            }
        }

        $enquiry = MarketplaceEnquiry::create([
            'partner_id' => $partnerId,
            'offer_id' => $request->offer_id,
            'offer_name' => $request->offer_name,
            'submitter_type' => User::class,
            'submitter_id' => $user->id,
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'email' => $request->email ?? $user->email,
            'company_name' => $request->company_name,
            'message' => $request->message ?: '',
            'status' => 'open',
            'metadata' => [
                'source' => 'staff_portal',
                'staff_name' => $user->name,
                'employer_id' => $user->parent_id ?? $user->employer_id,
                'partner_lookup_raw' => $request->partner_slug_or_email,
            ],
        ]);

        try {
            if ($partnerId) {
                Notification::notify((int)$partnerId, [
                    'category' => 'marketplace',
                    'type' => 'marketplace_enquiry',
                    'title' => 'New marketplace enquiry',
                    'body' => ($user->name ?? 'A staff member') . ' sent you an enquiry' . ($request->offer_name ? " about {$request->offer_name}" : '') . '.',
                    'icon' => 'message-square',
                    'deep_link' => '/partner/enquiries/' . $enquiry->id,
                    'metadata' => ['enquiry_id' => $enquiry->id, 'offer_id' => $request->offer_id, 'offer_name' => $request->offer_name],
                ]);
            }
            Notification::notify($user, [
                'category' => 'marketplace',
                'type' => 'marketplace_enquiry_sent',
                'title' => 'Enquiry sent',
                'body' => $request->offer_name ? "Your enquiry about {$request->offer_name} was delivered." : 'Your marketplace enquiry was sent.',
                'icon' => 'send',
                'deep_link' => '/my-pay/marketplace/enquiries',
                'metadata' => ['enquiry_id' => $enquiry->id],
            ]);
        } catch (\Throwable) {
        }

        return $this->sendResponse($enquiry, 'Enquiry submitted successfully', true, 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $rows = MarketplaceEnquiry::where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'offer_name' => $e->offer_name,
                    'message' => mb_substr($e->message, 0, 140),
                    'status' => $e->status,
                    'replied_at' => $e->replied_at?->toIso8601String(),
                    'created_at' => $e->created_at->toIso8601String(),
                ];
            });
        return $this->sendResponse($rows, 'My enquiries retrieved');
    }
}
