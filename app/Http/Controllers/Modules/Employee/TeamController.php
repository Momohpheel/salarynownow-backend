<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        
        $team = User::where('parent_id', $employerId)
            ->where('type', User::TYPE_EMPLOYEE)
            ->get();

        // Include the actual owner (employer) in the list
        $owner = User::find($employerId);

        $data = collect([$owner])->concat($team)->map(function($m) {
            return [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->role,
                'is_active' => $m->is_active,
            ];
        });

        return $this->sendResponse($data, 'Team members retrieved successfully');
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'role' => ['required', 'string', 'in:Owner,Finance,Hr,Viewer'],
        ]);

          $password = '123456';
        $member = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $employerId,
            'password' => Hash::make($password),
            'is_approved' => true,
            'is_active' => true,
        ]);

        return $this->sendResponse($member, 'Team member added successfully', true, 201);
    }

    public function updateRole(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->parent_id !== $employerId) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        $request->validate([
            'role' => ['required', 'string', 'in:Owner,Finance,Hr,Viewer'],
        ]);

        $member->update(['role' => $request->role]);

        return $this->sendResponse($member, 'Role updated successfully');
    }

    public function toggleStatus(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->parent_id !== $employerId) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        $member->update(['is_active' => !$member->is_active]);

        return $this->sendResponse(['is_active' => $member->is_active], 'Team member status updated successfully');
    }
}
