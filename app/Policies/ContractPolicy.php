<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/**
 * Roles/permissions are deferred (see EmployeePolicy): any authenticated user may manage contracts.
 * Later: everything behind `contracts.manage`.
 */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contract $contract): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contract $contract): bool
    {
        return true;
    }

    public function delete(User $user, Contract $contract): bool
    {
        return true;
    }
}
