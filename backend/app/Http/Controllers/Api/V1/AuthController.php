<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        // A session is only started for stateful requests — those whose Referer
        // or Origin matches sanctum.stateful. Without one, logging in would
        // throw "Session store not set on request".
        //
        // This check runs before the credentials are examined. Otherwise the
        // status code would reveal whether the credentials were correct: a
        // wrong password would answer 401 and a correct one 403, which lets an
        // attacker confirm an email and password from an untrusted origin.
        if (! $request->hasSession()) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Permintaan login harus berasal dari origin yang diizinkan.',
                [],
                [],
                403,
            );
        }

        $credentials = $request->credentials();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->isActive() || ! Hash::check($credentials['password'], $user->password)) {
            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Email atau password salah.',
                ['email' => ['Email atau password salah.']],
                [],
                401,
            );
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }

    public function logout(Request $request): JsonResponse
    {
        // The session user lives on the `web` guard. The `auth:sanctum`
        // middleware switches the default guard to `sanctum`, whose RequestGuard
        // has no logout(), so the web guard must be targeted explicitly.
        Auth::guard('web')->logout();

        // A session is only present on a stateful request. Without this guard
        // the invalidate() call below would throw "Session store not set".
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $user->fill($validated)->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }
}
