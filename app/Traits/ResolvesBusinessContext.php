<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesBusinessContext
{
    /**
     * Resolve the business scope for an employee/team-member request.
     *
     * @return object{mode: string, business_ids: int[], owner_user_id: int}
     */
    public function resolveBusinessScope(Request $request, User $actingUser): object
    {
        $allowedTypes = [User::TYPE_EMPLOYEE, User::TYPE_STAFF];
        if (!in_array($actingUser->type, $allowedTypes, true)) {
            throw new HttpException(403, 'User type not authorized for business context resolution.');
        }

        $ogOwner = $actingUser->resolveSharedWalletOwner();
        $ownerUserId = (int) $ogOwner->id;

        $ownedBusinesses = $ogOwner->ownedBusinesses();
        $allBusinessIds = $ownedBusinesses->pluck('id')->map(fn($id) => (int) $id)->all();

        $requested = $request->input('business_id');

        if ($requested !== null && $requested !== '') {
            if (is_string($requested) && strcasecmp(trim($requested), 'all') === 0) {
                return (object) [
                    'mode' => 'all',
                    'business_ids' => $allBusinessIds,
                    'owner_user_id' => $ownerUserId,
                ];
            }

            $id = is_numeric($requested) ? (int) $requested : null;
            if ($id !== null && in_array($id, $allBusinessIds, true)) {
                return (object) [
                    'mode' => 'single',
                    'business_ids' => [$id],
                    'owner_user_id' => $ownerUserId,
                ];
            }

            throw new HttpException(403, 'Requested business_id is not within your authorized owner group.');
        }

        if (count($allBusinessIds) >= 2) {
            return (object) [
                'mode' => 'all',
                'business_ids' => $allBusinessIds,
                'owner_user_id' => $ownerUserId,
            ];
        }

        $singleId = count($allBusinessIds) === 1 ? $allBusinessIds[0] : $ownerUserId;
        return (object) [
            'mode' => 'single',
            'business_ids' => [$singleId],
            'owner_user_id' => $ownerUserId,
        ];
    }

    /**
     * Enforce that the request targets a single specific business (write gate).
     *
     * @throws HttpException 422 if scope mode is not 'single'.
     */
    public function requireSingleBusinessScope(Request $request, User $actingUser): int
    {
        $scope = $this->resolveBusinessScope($request, $actingUser);

        if ($scope->mode !== 'single' || count($scope->business_ids) !== 1) {
            throw new HttpException(422, 'Select a specific business first');
        }

        return $scope->business_ids[0];
    }
}
