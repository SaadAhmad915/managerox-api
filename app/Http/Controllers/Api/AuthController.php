<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-based session auth for the CRM at app.managerox.com.
 *
 * app. and api. are different origins but the same site, so the session cookie
 * (scoped to .managerox.com, SameSite=Lax) is sent on these requests without
 * needing SameSite=None. The SPA must call /sanctum/csrf-cookie once before
 * the first stateful POST, and send credentials on every request.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Sanctum only gives a request a session when its Origin matches
        // SANCTUM_STATEFUL_DOMAINS. Without that, session() throws and the
        // caller gets a 500; a clear 419 says what is actually misconfigured.
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'This origin is not configured for session auth. '
                    .'Add it to SANCTUM_STATEFUL_DOMAINS and send credentials with the request.',
            ], 419);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One generic message: never reveal whether the address exists.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json($this->profile($request));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request));
    }

    /** @return array<string, mixed> */
    private function profile(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => (string) $user->id,
            'firstName' => explode(' ', $user->name)[0],
            'fullName' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'initials' => $user->initials(),
        ];
    }
}
