<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmployeeCredentials;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeeCredentialsController extends Controller
{
    /**
     * Issues a new random password and emails it (e.g. the welcome email was lost). The old password
     * stops working when the email goes out, and existing sessions are signed out now.
     */
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('resendCredentials', $employee);

        $user = $employee->user_id ? User::query()->find($employee->user_id) : null;
        if ($employee->is_draft || ! $user) {
            throw ValidationException::withMessages(['employee' => 'This employee has no login account yet.']);
        }
        if (! $employee->loginAllowed()) {
            throw ValidationException::withMessages(['employee' => 'Their employment status does not allow signing in.']);
        }

        $user->tokens()->delete();
        SendEmployeeCredentials::dispatch($employee->id, $user->id, isReset: true);

        /** @var User $actor */
        $actor = $request->user();
        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('credentials_resent')
            ->withProperties(['ip' => $request->ip()])
            ->log('Sign-in details resent');

        return response()->json(['message' => "A new password is on its way to {$user->email}."]);
    }
}
