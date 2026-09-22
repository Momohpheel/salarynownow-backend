<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        Log::info('SuperAdmin Login Request:', $request->all());
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)
            ->where('type', User::TYPE_SUPERADMIN)
            ->first();

        if ($user && $user->isLockedOut()) {
            $minutes = $user->getLockoutRemainingMinutes();
            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Please try again in {$minutes} minute(s)."],
            ])->status(429);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
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
                    : 'The provided credentials are incorrect.';
                throw ValidationException::withMessages([
                    'email' => [$message],
                ]);
            }
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->resetLoginAttempts();

        $token = $user->createToken('superadmin_token')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
            'user' => $user,
        ], 'SuperAdmin login successful');
    }

    public function logout(Request $request)
    {
        try {
            $request->user()?->currentAccessToken()?->delete();
        } catch (\Throwable) {
        }

        return $this->sendResponse([
            'logged_out' => true,
        ], 'SuperAdmin logged out');
    }
}
