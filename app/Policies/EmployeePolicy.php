<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * All employee authorization goes through here.
 *
 * Roles/permissions are deferred, so any authenticated user is allowed everything except
 * deleting their own record. When spatie/laravel-permission is added, each method becomes
 * `$user->can('employees.xxx')` (plus manager tree scoping) without touching controllers.
 * Keep these methods free of DB queries: `can` flags are computed per list row.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return true;
    }

    public function viewSensitive(User $user, Employee $employee): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function import(User $user): bool
    {
        return true;
    }

    public function update(User $user, Employee $employee): bool
    {
        return true;
    }

    public function delete(User $user, Employee $employee): Response
    {
        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return Response::deny('You cannot delete your own employee record.', 'cannot_delete_self');
        }

        return Response::allow();
    }

    public function export(User $user): bool
    {
        return true;
    }

    public function resetTwoFactor(User $user, Employee $employee): bool
    {
        return true;
    }

    public function unbindDevice(User $user, Employee $employee): bool
    {
        return true;
    }

    /** Email the employee a new random password. */
    public function resendCredentials(User $user, Employee $employee): bool
    {
        return true;
    }

    /** Change or schedule shifts (Shift records page). */
    public function assignShift(User $user): bool
    {
        return true;
    }
}
