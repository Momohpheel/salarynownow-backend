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

        return response()->json([
            'team_members' => collect([$owner])->concat($team)->map(function($m) {
                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'role' => $m->role,
                    'is_active' => $m->is_active,
                ];
            }),
        ]);
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'role' => ['required', 'string', 'in:Owner,Finance,Hr,Viewer'],
        ]);

        $member = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $employerId,
            'password' => Hash::make(Str::random(12)),
            'is_approved' => true,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Team member added successfully',
            'member' => $member,
        ], 201);
    }

    public function updateRole(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->parent_id !== $employerId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'role' => ['required', 'string', 'in:Owner,Finance,Hr,Viewer'],
        ]);

        $member->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Role updated successfully',
            'member' => $member,
        ]);
    }

    public function toggleStatus(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->parent_id !== $employerId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $member->update(['is_active' => !$member->is_active]);

        return response()->json([
            'message' => 'Team member status updated successfully',
            'is_active' => $member->is_active,
        ]);
    }
}
