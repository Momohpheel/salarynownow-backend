<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class LendersController extends Controller
{
    private const KEY = 'superadmin_lenders';

    private function load(): array
    {
        try {
            $row = Setting::where('key', self::KEY)->first();
            if (!$row || empty($row->value)) return [];
            $decoded = json_decode($row->value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function persist(array $lenders): void
    {
        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => json_encode(array_values($lenders))]
        );
    }

    public function index(Request $request)
    {
        $lenders = $this->load();
        usort($lenders, fn ($a, $b) => ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));
        return $this->sendResponse($lenders, 'Lenders list retrieved');
    }

    public function toggle(Request $request, string $id)
    {
        $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        $lenders = $this->load();
        $found = null;
        foreach ($lenders as &$l) {
            if ((string) ($l['id'] ?? '') === (string) $id) {
                $l['active'] = (bool) $request->active;
                $found = &$l;
                break;
            }
        }
        unset($l);
        if (!$found) {
            return $this->sendResponse(['error' => 'Lender not found'], 'Lender not found', false);
        }
        $this->persist($lenders);
        return $this->sendResponse($found, 'Lender status updated');
    }

    public function updatePriority(Request $request, string $id)
    {
        $request->validate([
            'priority' => ['required', 'integer', 'min:1'],
        ]);
        $lenders = $this->load();
        $found = null;
        foreach ($lenders as &$l) {
            if ((string) ($l['id'] ?? '') === (string) $id) {
                $l['priority'] = (int) $request->priority;
                $found = &$l;
                break;
            }
        }
        unset($l);
        if (!$found) {
            return $this->sendResponse(['error' => 'Lender not found'], 'Lender not found', false);
        }
        $this->persist($lenders);
        return $this->sendResponse($found, 'Lender priority updated');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['required', 'numeric', 'min:0'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_tenor_days' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'min:1'],
        ]);

        $lenders = $this->load();
        $priority = $request->filled('priority') ? (int) $request->priority : (count($lenders) + 1);
        $id = 'lnd_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 12);

        $lender = [
            'id' => $id,
            'name' => trim($request->name),
            'min_amount' => (float) $request->min_amount,
            'max_amount' => (float) $request->max_amount,
            'interest_rate' => (float) $request->interest_rate,
            'max_tenor_days' => (int) $request->max_tenor_days,
            'priority' => $priority,
            'active' => true,
        ];
        $lenders[] = $lender;
        $this->persist($lenders);
        return $this->sendResponse($lender, 'Lender added');
    }
}
