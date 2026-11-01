<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\ResetTwoFactor;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeTwoFactorController extends Controller
{
    public function __invoke(Request $request, Employee $employee, ResetTwoFactor $resetTwoFactor): JsonResponse
    {
        Gate::authorize('resetTwoFactor', $employee);

        /** @var User $actor */
        $actor = $request->user();
        $resetTwoFactor->handle($actor, $employee, $request->ip());

        return response()->json(['message' => 'Two-factor authentication reset.', 'two_factor_enabled' => false]);
    }
}
