<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\TeamMemberAdded;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        
        $team = User::where('employer_id', $employerId)
            ->where('type', User::TYPE_EMPLOYEE)
            ->with('role')
            ->get();

        // Include the actual owner (employer) in the list
        $owner = User::with('role')->find($employerId);

        $data = collect([$owner])->concat($team)->map(function($m) {
            $role = $m?->getRelation('role');
            $legacyRole = $m?->getAttribute('role');

            return [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'status' => $role->status,
                ] : ($legacyRole ? [
                    'id' => null,
                    'name' => $legacyRole,
                    'description' => null,
                    'status' => null,
                ] : null),
                'is_active' => $m->is_active,
            ];
        });

        return $this->sendResponse($data, 'Team members retrieved successfully');
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $employer = User::find($employerId);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where(function ($query) use ($employerId) {
                    return $query->where('employer_id', $employerId);
                }),
            ],
        ]);

        $password = Str::random(12);
        $member = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role_id' => $request->role_id,
            'type' => User::TYPE_EMPLOYEE,
            'employer_id' => $employerId,
            'password' => Hash::make($password),
            'is_approved' => true,
            'is_active' => true,
        ]);

        Mail::to($member->email)->send(new TeamMemberAdded($member, $employer, $password));

        return $this->sendResponse($member->load('role'), 'Team member added successfully', true, 201);
    }

    public function updateRole(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->employer_id !== $employerId) {
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

        $member->update(['role_id' => $request->role_id]);

        return $this->sendResponse($member->load('role'), 'Role updated successfully');
    }

    public function toggleStatus(Request $request, User $member)
    {
        $employerId = $request->user()->getEmployerId();

        if ($member->employer_id !== $employerId) {
            return $this->sendError('Unauthorized.', null, 403);
        }

        $member->update(['is_active' => !$member->is_active]);

        return $this->sendResponse(['is_active' => $member->is_active], 'Team member status updated successfully');
    }
}
