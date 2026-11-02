<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', strtolower((string) $request->validated('email')))->first();

        // Same error for unknown email, wrong password and deleted employee: no account enumeration.
        if (! $user || ! Hash::check((string) $request->validated('password'), $user->password) || $this->employeeDeleted($user)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Only reached with the right password, so this reveals nothing to a stranger.
        $employee = Employee::query()->where('user_id', $user->id)->first(['id', 'employment_status_id']);
        if ($employee && ! $employee->loginAllowed()) {
            throw ValidationException::withMessages(['email' => 'Your employment status does not allow signing in. Please contact HR.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken((string) ($request->validated('device_name') ?? 'api'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('employee:id,user_id')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Bearer-token API only (no cookie SPA auth), so this is always a PersonalAccessToken.
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('employee:id,user_id'));
    }

    private function employeeDeleted(User $user): bool
    {
        return Employee::onlyTrashed()->where('user_id', $user->id)->exists();
    }
}
