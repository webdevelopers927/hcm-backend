<?php

namespace App\Policies;

use App\Models\DegreeProgram;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may manage degree programs.
 * Later: view for everyone, changes behind `degree-programs.manage`.
 */
class DegreeProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DegreeProgram $degreeProgram): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DegreeProgram $degreeProgram): bool
    {
        return true;
    }

    public function delete(User $user, DegreeProgram $degreeProgram): bool
    {
        return true;
    }
}
