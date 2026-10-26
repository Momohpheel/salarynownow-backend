<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;

/**
 * Self-service API key management for employers.
 *
 * Keys are bound to a single business and are used by external integrations
 * against the public `/api/external/*` endpoints. The plaintext token is
 * returned only once, at creation time — afterwards only the prefix is stored.
 */
class ApiKeyController extends Controller
{
    use ResolvesBusinessContext;

    public function index(Request $request)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        $keys = ApiKey::whereIn('user_id', $scope->business_ids)
            ->latest()
            ->get()
            ->map(fn (ApiKey $key) => $this->present($key))
            ->values();

        return $this->sendResponse($keys, 'API keys retrieved successfully');
    }

    public function store(Request $request)
    {
        $singleBusinessId = $this->requireSingleBusinessScope($request, $request->user());

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $business = User::find($singleBusinessId);
        if (! $business) {
            return $this->sendError('Business not found.', null, 404);
        }

        [$key, $plainToken] = ApiKey::generate($business, $data['name'] ?? null);

        return $this->sendResponse([
            'key' => $this->present($key),
            'token' => $plainToken,
        ], 'API key created. Copy it now — it will not be shown again.', true, 201);
    }

    public function destroy(Request $request, $id)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        $key = ApiKey::find($id);
        $allowedBusinessIds = array_map('intval', $scope->business_ids);

        if (! $key || ! in_array((int) $key->user_id, $allowedBusinessIds, true)) {
            return $this->sendError('API key not found.', null, 404);
        }

        if ($key->revoked_at !== null) {
            return $this->sendResponse($this->present($key), 'API key is already revoked.');
        }

        $key->forceFill(['revoked_at' => now()])->save();

        return $this->sendResponse($this->present($key), 'API key revoked.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $key): array
    {
        if ($key->revoked_at !== null) {
            $status = 'revoked';
        } elseif ($key->expires_at !== null && $key->expires_at->isPast()) {
            $status = 'expired';
        } else {
            $status = 'active';
        }

        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->key_prefix,
            'scopes' => $key->scopes ?? [],
            'status' => $status,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
        ];
    }
}
