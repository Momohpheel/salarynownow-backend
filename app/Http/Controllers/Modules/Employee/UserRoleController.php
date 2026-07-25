<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserRoleController extends Controller
{
    public function assignRole(Request $request, User $user)
    {
        $employerId = $request->user()->getEmployerId();

        $user = User::find($user->id);
       if ($user->employer_id !== $employerId) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        $request->validate([
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where(function ($query) use ($employerId) {
                    return $query->where('employer_id', $employerId);
                }),
            ],
        ]);

        $user->update(['role_id' => $request->role_id]);

        return $this->sendResponse($user->load('role'), 'Role assigned successfully.');
    }

    public function updateRole(Request $request, User $user)
    {
        $employerId = $request->user()->getEmployerId();

        if (! $this->belongsToEmployer($user, $employerId)) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        $request->validate([
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where(function ($query) use ($employerId) {
                    return $query->where('employer_id', $employerId);
                }),
            ],
        ]);

        $user->update(['role_id' => $request->role_id]);

        return $this->sendResponse($user->load('role'), 'Role updated successfully.');
    }

    public function getUserRole(Request $request, User $user)
    {
        $employerId = $request->user()->getEmployerId();

        if (! $this->belongsToEmployer($user, $employerId)) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        return $this->sendResponse(
            $user->load(['role' => function ($query) use ($employerId) {
                $query->where('employer_id', $employerId);
            }]),
            'User role retrieved successfully.'
        );
    }

    private function belongsToEmployer(User $user, int $employerId): bool
    {
        if ($user->id === $employerId) {
            return true;
        }

        
        return (int) $user->employer_id === $employerId
            || (int) $user->parent_id === $employerId;
    }
}
