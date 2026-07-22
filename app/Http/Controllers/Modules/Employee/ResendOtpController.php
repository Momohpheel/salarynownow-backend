<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ResendOtpController extends Controller
{
    public function resend(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::employee()->where('email', $request->email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $otp = random_int(100000, 999999);

        $user->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(5),
            'otp_attempts' => 0,
        ]);

        Mail::to($user->email)->send(new OtpMail($otp));

        return $this->sendResponse(null, 'A new OTP has been sent to your email.');
    }
}
