<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may manage divisions.
 * Later: view for everyone, changes behind `divisions.manage`.
 */
class DivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Division $division): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Division $division): bool
    {
        return true;
    }

    public function delete(User $user, Division $division): bool
    {
        return true;
    }
}
