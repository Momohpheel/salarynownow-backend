<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class ForgotPasswordController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::staff()->where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'If this email exists in our system, you will receive a reset link.'], 200);
        }
        return response()->json(['message' => 'Reset link sent successfully to your email.']);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'token' => 'required',
        ]);
        $user = User::staff()->where('email', $request->email)->first();
        if (!$user) return response()->json(['message' => 'User not found.'], 404);
        $user->forceFill(['password' => Hash::make($request->password)])->save();
        return response()->json(['message' => 'Password has been reset successfully.']);
    }
}
