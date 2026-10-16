<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Support\SessionAudit;
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
        //$user = User::staff()->where('email', $request->email)->first();
         $user = User::where('type', User::TYPE_STAFF)
            ->where('email', $request->email)
            ->first();
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
        
        $resetUser = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) use (&$resetUser) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $resetUser = $user;
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            // Changing the password invalidates every existing session.
            if ($resetUser) {
                SessionAudit::revokeAll($resetUser, 'password_reset');
            }

            return $this->sendResponse(null, 'Password has been reset successfully.');
        }

        return $this->sendError('Failed to reset password.', null, 400);
    }
}
