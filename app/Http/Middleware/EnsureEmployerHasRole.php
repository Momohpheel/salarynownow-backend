<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployerHasRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $next($request);
        }

        $type = (string) ($user->type ?? '');
        $needsRole =
            $type === User::TYPE_EMPLOYEE ||
            $type === User::TYPE_ADMIN ||
            $type === User::TYPE_MERCHANT;

        if (!$needsRole) {
            return $next($request);
        }

        if (!empty($user->role_id)) {
            return $next($request);
        }

        try {
            $employerId = (int) $user->getEmployerId();
            $targetUserId = (int) $user->id;

            $roles = Role::ensureStandardRolesForEmployer($employerId);
            $adminRole = $roles['admin'] ?? null;

            if (!$adminRole) {
                $adminRole = \App\Models\Role::query()
                    ->where('employer_id', $employerId)
                    ->whereIn('name', ['admin', 'owner'])
                    ->orderBy('id')
                    ->first();
            }

            if ($adminRole) {
                $user->forceFill([
                    'role_id' => $adminRole->id,
                ])->save();

                if ($user->relationLoaded('role')) {
                    $user->unsetRelation('role');
                }
                $user->loadMissing('role.permissions');
            }
        } catch (\Throwable $e) {
            // Never block a request because of role-seeding failures; the
            // frontend fallback will still grant "owner" to role-less
            // employer-type users and backend permission checks degrade
            // gracefully via hasPermissionTo's owner bypass.
            report($e);
        }

        return $next($request);
    }
}
