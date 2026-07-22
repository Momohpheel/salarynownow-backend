<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::all();
        return $this->sendResponse($roles, 'Roles retrieved successfully.');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'unique:roles'],
            'description' => ['nullable', 'string'],
        ]);

        $role = Role::create($request->only('name', 'description'));

        return $this->sendResponse($role, 'Role created successfully.');
    }

    public function show(Role $role)
    {
        $role->load('permissions');
        return $this->sendResponse($role, 'Role retrieved successfully.');
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'unique:roles,name,' . $role->id],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $role->update($request->only('name', 'description', 'status'));

        return $this->sendResponse($role, 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->exists()) {
            return $this->sendError('Role is assigned to users and cannot be deleted.', [], 400);
        }

        $role->delete();

        return $this->sendResponse(null, 'Role deleted successfully.');
    }

    public function assignPermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role->permissions()->sync($request->permissions);

        return $this->sendResponse($role->load('permissions'), 'Permissions assigned successfully.');
    }

    public function updatePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role->permissions()->sync($request->permissions);

        return $this->sendResponse($role->load('permissions'), 'Permissions updated successfully.');
    }

    public function permissions()
    {
        $permissions = Permission::all()->groupBy('group');
        return $this->sendResponse($permissions, 'Permissions retrieved successfully.');
    }
}
