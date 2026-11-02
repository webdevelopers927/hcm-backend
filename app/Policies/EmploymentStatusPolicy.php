<?php

namespace App\Policies;

use App\Models\EmploymentStatus;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may manage employment
 * statuses. Later: view for everyone, changes behind `employment-statuses.manage`.
 */
class EmploymentStatusPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmploymentStatus $employmentStatus): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EmploymentStatus $employmentStatus): bool
    {
        return true;
    }

    public function delete(User $user, EmploymentStatus $employmentStatus): bool
    {
        return true;
    }
}
