<?php

namespace App\Http\Controllers\Modules\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::partner()->where('email', $request->email)->first();

        if ($user && $user->isLockedOut()) {
            $minutes = $user->getLockoutRemainingMinutes();
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please try again in {$minutes} minute(s)."],
            ])->status(429);
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->incrementLoginAttempts();
                if ($user->isLockedOut()) {
                    $minutes = $user->getLockoutRemainingMinutes();
                    throw ValidationException::withMessages([
                        'email' => ["Too many failed login attempts. Your account has been locked for {$minutes} minute(s)."],
                    ])->status(429);
                }
                $remaining = User::MAX_LOGIN_ATTEMPTS - $user->login_attempts;
                $message = $remaining > 0
                    ? "The provided credentials are incorrect. {$remaining} attempt(s) remaining before lockout."
                    : __('auth.failed');
                throw ValidationException::withMessages([
                    'email' => [$message],
                ]);
            }
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user->resetLoginAttempts();

        $token = $user->createToken('partner-token')->plainTextToken;

        return $this->sendResponse([
            'user' => $user,
            'token' => $token,
        ], 'Partner logged in successfully');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->sendResponse(null, 'Logged out successfully');
    }
}
