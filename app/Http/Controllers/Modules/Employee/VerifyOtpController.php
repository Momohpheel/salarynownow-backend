<?php

namespace App\Http\Controllers\Modules\Employee;

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
            'otp'   => ['required', 'string', 'digits:6'],
        ]);

        $email = mb_strtolower(trim($request->email));
        $user = User::whereIn('type', [User::TYPE_EMPLOYEE, User::TYPE_ADMIN])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ((int) ($user->otp_attempts ?? 0) >= 5) {
            throw ValidationException::withMessages([
                'otp' => ['Too many invalid OTP attempts. Please try again later.'],
            ]);
        }

        $incoming = str_pad(trim($request->otp), 6, '0', STR_PAD_LEFT);
        $stored   = str_pad((string) ((int) ($user->otp ?? 0)), 6, '0', STR_PAD_LEFT);
        $expired  = $user->otp_expires_at && $user->otp_expires_at < now();

        if (! $user->otp || $stored !== $incoming || $expired) {
            try { $user->increment('otp_attempts'); } catch (\Throwable) { }
            Log::warning('Invalid OTP attempt for user: ' . $user->email . ' stored=' . ($user->otp ?? 'NULL') . ' incoming=' . $request->otp . ' expired=' . ($expired ? 'YES' : 'no'));

            throw ValidationException::withMessages([
                'otp' => [__('auth.invalid_otp')],
            ]);
        }

        Log::info('OTP verified successfully for user: ' . $user->email);

        $user->forceFill([
            'otp' => null,
            'otp_expires_at' => null,
            'otp_attempts' => null,
        ])->save();

        $token = $user->createToken('employee-token')->plainTextToken;

        $user->append(['cac_certificate_url', 'director_id_url', 'utility_bill_url']);

        $user->load('role');

        return $this->sendResponse([
            'user' => $user,
            'token' => $token,
        ], 'OTP verified successfully. Logged in.');
    }
}
