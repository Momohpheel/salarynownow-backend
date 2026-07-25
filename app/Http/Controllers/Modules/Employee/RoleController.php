<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $roles = Role::where('employer_id', $employerId)->get();

        return $this->sendResponse($roles, 'Roles retrieved successfully.');
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $request->validate([
            'name' => [
                'required',
                'string',
                Rule::unique('roles', 'name')->where(function ($query) use ($employerId) {
                    return $query->where('employer_id', $employerId);
                }),
            ],
            'description' => ['nullable', 'string'],
        ]);

        $role = Role::create([
            'employer_id' => $employerId,
            ...$request->only('name', 'description'),
        ]);

        return $this->sendResponse($role, 'Role created successfully.');
    }

    public function show(Request $request, $role)
    {
        $employerId = $request->user()->getEmployerId();
        $role = Role::where('employer_id', $employerId)->findOrFail($role);
        $role->load('permissions');

        return $this->sendResponse($role, 'Role retrieved successfully.');
    }

    public function update(Request $request, $role)
    {
        $employerId = $request->user()->getEmployerId();
        $role = Role::where('employer_id', $employerId)->findOrFail($role);

        $request->validate([
            'name' => [
                'sometimes',
                'string',
                Rule::unique('roles', 'name')
                    ->ignore($role->id)
                    ->where(function ($query) use ($employerId) {
                        return $query->where('employer_id', $employerId);
                    }),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $role->update($request->only('name', 'description', 'status'));

        return $this->sendResponse($role, 'Role updated successfully.');
    }

    public function destroy(Request $request, $role)
    {
        $employerId = $request->user()->getEmployerId();
        $role = Role::where('employer_id', $employerId)->findOrFail($role);

        if ($role->users()->exists()) {
            return $this->sendError('Role is assigned to users and cannot be deleted.', [], 400);
        }

        $role->delete();

        return $this->sendResponse(null, 'Role deleted successfully.');
    }

    public function assignPermissions(Request $request, $role)
    {
        $employerId = $request->user()->getEmployerId();
        $role = Role::where('employer_id', $employerId)->findOrFail($role);

        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role->permissions()->sync($request->permissions);

        return $this->sendResponse($role->load('permissions'), 'Permissions assigned successfully.');
    }

    public function updatePermissions(Request $request, $role)
    {
        $employerId = $request->user()->getEmployerId();
        $role = Role::where('employer_id', $employerId)->findOrFail($role);

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
