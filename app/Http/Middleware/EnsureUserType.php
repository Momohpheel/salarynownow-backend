<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    /**
     * Handle an incoming request.
     *
     * Accepts a list of user types and rejects the request if the
     * authenticated user does not match any of them.
     *
     * Example: `EnsureUserType:admin,super_admin` allows only users whose
     * type is either TYPE_ADMIN or TYPE_SUPER_ADMIN.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'getAttribute')) {
            abort(401, __('Unauthenticated.'));
        }

        $allowed = collect($types)->map(function (string $type): string {
            return match ($type) {
                'admin' => User::TYPE_ADMIN,
                'super_admin' => User::TYPE_SUPERADMIN,
                'employee', 'employer', 'merchant' => User::TYPE_EMPLOYEE,
                'staff' => User::TYPE_STAFF,
                'partner' => User::TYPE_PARTNER,
                default => $type,
            };
        })->values()->all();

        $userType = (string) $user->getAttribute('type');

        if (! in_array($userType, $allowed, true)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'message' => __('Forbidden. Your user type is not allowed to perform this action.'),
                ], 403);
            }
            abort(403, __('Forbidden.'));
        }

        return $next($request);
    }
}
