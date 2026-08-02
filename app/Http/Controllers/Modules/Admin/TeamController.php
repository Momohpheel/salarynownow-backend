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
        $admins = User::where('type', User::TYPE_ADMIN)->get();
        return $this->sendResponse($admins, 'Admin users retrieved successfully');
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
