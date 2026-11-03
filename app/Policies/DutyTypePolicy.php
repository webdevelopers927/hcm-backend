<?php

namespace App\Policies;

use App\Models\DutyType;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may manage duty types.
 * Later: view for everyone, changes behind `duty-types.manage`.
 */
class DutyTypePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DutyType $dutyType): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DutyType $dutyType): bool
    {
        return true;
    }

    public function delete(User $user, DutyType $dutyType): bool
    {
        return true;
    }
}
