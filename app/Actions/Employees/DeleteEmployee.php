<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteEmployee
{
    /**
     * Soft delete, revoke the linked user's API tokens, and write an audit entry.
     * Authorization (incl. "not yourself") is the caller's job via EmployeePolicy::delete.
     */
    public function handle(User $actor, Employee $employee, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $employee, $ip) {
            $employee->delete();
            $employee->user?->tokens()->delete();

            activity('employees')
                ->causedBy($actor)
                ->performedOn($employee)
                ->event('deleted')
                ->withProperties(['ip' => $ip])
                ->log('Employee deleted');
        });
    }
}
