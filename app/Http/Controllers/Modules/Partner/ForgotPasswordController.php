<?php

namespace App\Http\Controllers\Modules\Partner;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
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
        $token = Password::createToken($user);
        Mail::to($user->email)->send(new ResetPasswordMail($token, $user->email));
        return $this->sendResponse(null, 'Reset link sent successfully to your email.');
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'token' => 'required',
        ]);
        
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->sendResponse(null, 'Password has been reset successfully.');
        }

        return $this->sendError('Failed to reset password.', null, 400);
    }
}
