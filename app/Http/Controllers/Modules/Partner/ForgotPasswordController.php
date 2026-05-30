<?php

namespace App\Http\Controllers\Modules\Partner;

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
        $user = User::partner()->where('email', $request->email)->first();
        if (!$user) {
            return $this->sendResponse(null, 'If this email exists in our system, you will receive a reset link.');
        }
        return $this->sendResponse(null, 'Reset link sent successfully to your email.');
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'token' => 'required',
        ]);
        $user = User::partner()->where('email', $request->email)->first();
        if (!$user) return $this->sendError('User not found.', null, 404);
        $user->forceFill(['password' => Hash::make($request->password)])->save();
        return $this->sendResponse(null, 'Password has been reset successfully.');
    }
}
