<?php

namespace App\Http\Controllers\Modules\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VerifyOtpController extends Controller
{

    public function verify(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        // $user = User::staff()->where('email', $request->email)->first();

         $user = User::where('type', User::TYPE_STAFF)
            ->where('email', $request->email)
            ->first();
            
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->otp_attempts >= 5) {
            throw ValidationException::withMessages([
                'otp' => ['Too many invalid OTP attempts. Please try again later.'],
            ]);
        }

        if (! $user->otp || $user->otp !== $request->otp || $user->otp_expires_at < now()) {
            $user->increment('otp_attempts');
            Log::warning('Invalid OTP attempt for staff user: ' . $user->email);

            throw ValidationException::withMessages([
                'otp' => [__('auth.invalid_otp')],
            ]);
        }

        Log::info('OTP verified successfully for staff user: ' . $user->email);

        $user->update([
            'otp' => null,
            'otp_expires_at' => null,
            'otp_attempts' => null,
        ]);

        $token = $user->createToken('staff-token')->plainTextToken;

        $user->load('role');


        return $this->sendResponse([
            'user' => $user,
            'token' => $token,
        ], 'OTP verified successfully. Logged in.');
    }
}
