<?php

namespace App\Http\Controllers\Modules\Partner;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceEnquiry;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceEnquiryController extends Controller
{
    public function index(Request $request)
    {
        $partner = $request->user();
        $status = $request->query('status');

        $rows = MarketplaceEnquiry::with('submitter')
            ->where('partner_id', $partner->id)
            ->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'partner_id' => $e->partner_id,
                    'offer_id' => $e->offer_id,
                    'offer_name' => $e->offer_name,
                    'submitter_id' => $e->submitter_id,
                    'submitter_type' => $e->submitter_type,
                    'submitter' => $e->submitter ? $e->submitter->only(['id', 'name', 'email', 'phone_number', 'type', 'company_name', 'department', 'parent_id', 'employer_id']) : null,
                    'name' => $e->name,
                    'phone_number' => $e->phone_number,
                    'email' => $e->email,
                    'company_name' => $e->company_name,
                    'message' => $e->message,
                    'status' => $e->status,
                    'metadata' => $e->metadata,
                    'replied_at' => $e->replied_at?->toIso8601String(),
                    'reply_note' => is_array($e->metadata) ? ($e->metadata['reply_note'] ?? null) : null,
                    'created_at' => $e->created_at->toIso8601String(),
                    'updated_at' => $e->updated_at->toIso8601String(),
                ];
            });

        $counts = MarketplaceEnquiry::where('partner_id', $partner->id)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        return $this->sendResponse([
            'enquiries' => $rows,
            'counts' => [
                'all' => $rows->count(),
                'open' => (int) ($counts['open'] ?? 0),
                'replied' => (int) ($counts['replied'] ?? 0),
                'converted' => (int) ($counts['converted'] ?? 0),
                'closed' => (int) ($counts['closed'] ?? 0),
                'pending_settlement' => (int) ($counts['pending_settlement'] ?? 0),
            ],
        ], 'Partner enquiries');
    }

    public function show(Request $request, $id)
    {
        $partner = $request->user();
        $e = MarketplaceEnquiry::with('submitter')->where('partner_id', $partner->id)->findOrFail($id);

        return $this->sendResponse([
            'id' => $e->id,
            'partner_id' => $e->partner_id,
            'offer_id' => $e->offer_id,
            'offer_name' => $e->offer_name,
            'submitter_id' => $e->submitter_id,
            'submitter_type' => $e->submitter_type,
            'submitter' => $e->submitter ? $e->submitter->only(['id', 'name', 'email', 'phone_number', 'type', 'company_name', 'department', 'parent_id', 'employer_id']) : null,
            'name' => $e->name,
            'phone_number' => $e->phone_number,
            'email' => $e->email,
            'company_name' => $e->company_name,
            'message' => $e->message,
            'status' => $e->status,
            'metadata' => $e->metadata,
            'reply_note' => is_array($e->metadata) ? ($e->metadata['reply_note'] ?? null) : null,
            'replied_at' => $e->replied_at?->toIso8601String(),
            'created_at' => $e->created_at->toIso8601String(),
        ], 'Enquiry detail');
    }

    public function reply(Request $request, $id)
    {
        $partner = $request->user();
        $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'in:open,replied,converted,closed,pending_settlement'],
        ]);

        $e = MarketplaceEnquiry::where('partner_id', $partner->id)->findOrFail($id);

        $meta = is_array($e->metadata) ? $e->metadata : [];
        if ($request->filled('note')) {
            $meta['reply_note'] = $request->input('note');
            $meta['replied_by'] = $partner->name;
            $meta['replied_at_iso'] = now()->toIso8601String();
        }
        $e->metadata = $meta;

        if ($request->filled('status')) {
            $e->status = $request->input('status');
        } elseif ($request->filled('note') && $e->status === 'open') {
            $e->status = 'replied';
        }

        if ($request->filled('note') || $request->input('status') === 'replied') {
            $e->replied_at = now();
        }
        $e->save();

        try {
            if ($e->submitter_id && $e->submitter_type === User::class) {
                Notification::notify((int) $e->submitter_id, [
                    'category' => 'marketplace',
                    'type' => 'marketplace_enquiry_replied',
                    'title' => 'Enquiry reply',
                    'body' => ($partner->company_name ?? $partner->name ?? 'A partner') . ' replied to your enquiry' . ($e->offer_name ? " about {$e->offer_name}" : '') . '.',
                    'icon' => 'message-circle',
                    'deep_link' => '/my-pay/marketplace/enquiries',
                    'metadata' => ['enquiry_id' => $e->id, 'offer_id' => $e->offer_id, 'offer_name' => $e->offer_name],
                ]);
            }
        } catch (\Throwable) {
        }

        return $this->sendResponse([
            'id' => $e->id,
            'status' => $e->status,
            'reply_note' => $request->input('note'),
            'replied_at' => $e->replied_at?->toIso8601String(),
        ], 'Reply saved');
    }
}
