<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrantAdminAccessController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $userId = (int) $validated['id'];

        $user = User::where('id', $userId)->first();
        if (!$user) {
            return $this->sendError('User not found', null, 404);
        }

        if ($user->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError(
                'User must be of type employee to grant admin access',
                ['received_type' => $user->type, 'expected_type' => User::TYPE_EMPLOYEE],
                422
            );
        }

        try {
            DB::beginTransaction();

            $previousType = $user->type;
            $previousRoleId = $user->role_id;
            $previousEmployerId = $user->employer_id;
            $previousParentId = $user->parent_id;

            // $user->type = User::TYPE_ADMIN;

            // $adminAccountContext = User::where('type', User::TYPE_ADMIN)
            //     ->where(function ($q) {
            //         $q->whereNull('parent_id')
            //             ->orWhereNull('employer_id')
            //             ->orWhereColumn('id', '=', 'employer_id');
            //     })
            //     ->orderBy('id')
            //     ->first();

            // $contextEmployerId = $adminAccountContext?->id;

            // if ($contextEmployerId !== null && (int) $user->id !== (int) $contextEmployerId) {
            //     $user->parent_id = $contextEmployerId;
            //     $user->employer_id = $contextEmployerId;
            // } elseif ((int) $user->id === (int) ($contextEmployerId->id ?? 0)) {
            //     $user->parent_id = null;
            //     $user->employer_id = $user->id;
            // }

            // $user->is_approved = true;
            // $user->is_active = true;
            // $user->status = 'approved';
            // $user->save();

           $employerIdForRole = (int) ($user->employer_id ?? $user->id);
            $allPermNames = Role::ownerPermissionNames();
            $definitions = Role::standardDefinitions();
            $adminDefinition = $definitions['admin'] ?? [
                'description' => 'Full access admin role provisioned during grant-admin-access.',
            ];

            $adminRole = Role::query()->updateOrCreate(
                [
                    'employer_id' => $employerIdForRole,
                    'name'        => 'admin',
                ],
                [
                    'description' => $adminDefinition['description'] ?? 'Full access admin role.',
                    'status'      => 'active',
                ]
            );

            if (!$adminRole instanceof Role) {
                DB::rollBack();
                return $this->sendError('Failed to create or load admin role row', null, 500);
            }

            $permIds = \App\Models\Permission::whereIn('name', $allPermNames)->pluck('id')->all();
            if (count($permIds) > 0) {
                $adminRole->permissions()->sync($permIds);
            }
            $adminRole->loadMissing('permissions');

            $user->role_id = $adminRole->id;
            $user->save();

            $user->unsetRelation('role');
            $user->loadMissing(['role.permissions']);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->sendError(
                'Failed to grant admin access: ' . $e->getMessage(),
                null,
                500
            );
        }

        return $this->sendResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => $user->type,
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
                'status' => $user->status,
                'employer_id' => $user->employer_id,
                'parent_id' => $user->parent_id,
                'owner_group_user_id' => $user->owner_group_user_id,
            ],
            'role' => [
                'id' => $adminRole->id,
                'name' => $adminRole->name,
                'description' => $adminRole->description,
                'permissions_count' => $adminRole->permissions->count(),
                'permissions' => $adminRole->permissions->pluck('name')->values(),
            ],
            'changes' => [
                'type' => [
                    'previous' => $previousType,
                    'new' => $user->type,
                ],
                'role_id' => [
                    'previous' => $previousRoleId,
                    'new' => $user->role_id,
                ],
                'employer_id' => [
                    'previous' => $previousEmployerId,
                    'new' => $user->employer_id,
                ],
                'parent_id' => [
                    'previous' => $previousParentId,
                    'new' => $user->parent_id,
                ],
            ],
        ], 'Admin role and full permissions granted successfully');
    }
}
