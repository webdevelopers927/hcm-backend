<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ResetTwoFactor
{
    public function handle(User $actor, Employee $employee, ?string $ip): void
    {
        $user = $employee->user;

        if (! $user) {
            throw ValidationException::withMessages(['employee' => 'This employee has no user account.']);
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('two_factor_reset')
            ->withProperties(['ip' => $ip])
            ->log('Two-factor authentication reset');
    }
}
