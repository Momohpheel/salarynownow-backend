<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    public function assignRole(Request $request, User $user)
    {
        $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user->update(['role_id' => $request->role_id]);

        return $this->sendResponse($user->load('role'), 'Role assigned successfully.');
    }

    public function updateRole(Request $request, User $user)
    {
        $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user->update(['role_id' => $request->role_id]);

        return $this->sendResponse($user->load('role'), 'Role updated successfully.');
    }

    public function getUserRole(User $user)
    {
        return $this->sendResponse($user->load('role'), 'User role retrieved successfully.');
    }
}
