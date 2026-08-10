<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class TeamController extends Controller
{
    public function index()
    {
        $admins = User::where('type', User::TYPE_ADMIN)->with('role')->get()->map(function ($admin) {
            return [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role->name ?? '—',
                'status' => $admin->is_active ? 'Active' : 'Inactive',
                'joined' => $admin->created_at->format('d M Y'),
            ];
        });
        return $this->sendResponse($admins, 'Admin users retrieved successfully');
    }

    public function roles()
    {
        $roles = Role::whereNull('employer_id')->orWhere('employer_id', null)->pluck('name')->values();
        return $this->sendResponse($roles, 'Admin roles retrieved successfully');
    }

    public function invite(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'role' => ['required', 'string', 'max:255'],
        ]);

        $role = Role::firstOrCreate(
            ['name' => $request->role, 'employer_id' => null],
            ['description' => 'Admin role: ' . $request->role, 'status' => 'active']
        );

        $tempPassword = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 12);

        $admin = User::create([
            'name' => explode('@', $request->email)[0],
            'email' => $request->email,
            'password' => Hash::make($tempPassword),
            'type' => User::TYPE_ADMIN,
            'role_id' => $role->id,
            'parent_id' => $request->user()->id,
        ]);

        return $this->sendResponse([
            'admin' => $admin,
            'temporary_password' => $tempPassword,
        ], 'Admin user invited successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $admin = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'type' => User::TYPE_ADMIN,
            'role_id' => $request->role_id,
            'parent_id' => $request->user()->id,
        ]);

        return $this->sendResponse($admin, 'Admin user created successfully');
    }

    public function deactivate(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $admin = User::findOrFail($request->user_id);
        $admin->update(['is_active' => false]);

        return $this->sendResponse(null, 'Admin user deactivated successfully');
    }

    public function updateRole(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'string', 'max:255'],
        ]);

        $admin = User::findOrFail($request->user_id);
        $role = Role::firstOrCreate(
            ['name' => $request->role, 'employer_id' => null],
            ['description' => 'Admin role: ' . $request->role, 'status' => 'active']
        );
        $admin->update(['role_id' => $role->id]);

        return $this->sendResponse($admin, 'Admin user role updated successfully');
    }

    public function update(Request $request, User $admin)
    {
        $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $admin->update(['role_id' => $request->role_id]);

        return $this->sendResponse($admin, 'Admin user updated successfully');
    }

    public function destroy(User $admin)
    {
        $admin->update(['is_active' => false]);

        return $this->sendResponse(null, 'Admin user deactivated successfully');
    }
}
