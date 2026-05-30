<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class StaffController extends Controller
{
    public function store(Request $request)
    {
        // For now, I'll assume the authenticated user is an employee.
        // In a real app, I'd use middleware to ensure this.
        
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
        ]);

        $staff = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'type' => User::TYPE_STAFF,
            'parent_id' => $request->user()?->id, // Set the current employee as parent
        ]);

        return response()->json([
            'message' => 'Staff added successfully',
            'staff' => $staff,
        ], 201);
    }

    public function index(Request $request)
    {
        $staff = $request->user()->children()->staff()->get();

        return response()->json([
            'staff' => $staff,
        ]);
    }
}
