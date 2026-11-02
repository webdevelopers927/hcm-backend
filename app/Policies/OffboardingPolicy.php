<?php

namespace App\Policies;

use App\Models\Offboarding;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may run offboarding.
 * Later: everything behind `employees.offboard`.
 */
class OffboardingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Offboarding $offboarding): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Offboarding $offboarding): bool
    {
        return true;
    }

    public function delete(User $user, Offboarding $offboarding): bool
    {
        return true;
    }
}
