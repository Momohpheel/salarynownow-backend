<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;

class ForgotPasswordController extends Controller
{
    /**
     * Send a reset link/code to the user's email.
     * For this implementation, we'll simulate sending and just return a success message.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::employee()->where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'If this email exists in our system, you will receive a reset link.'], 200);
        }

        // In a real app, you'd use Password::sendResetLink()
        // For now, we simulate the success.
        return response()->json([
            'message' => 'Reset link sent successfully to your email.',
        ]);
    }

    /**
     * Reset the user's password.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'token' => 'required', // Simulated token
        ]);

        $user = User::employee()->where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
        ])->save();

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
