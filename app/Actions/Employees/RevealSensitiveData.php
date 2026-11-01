<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;

class RevealSensitiveData
{
    /**
     * Loads PII for one employee and records who viewed it. The log holds ids and IP only.
     */
    public function handle(User $actor, Employee $employee, ?string $ip): Employee
    {
        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('sensitive_viewed')
            ->withProperties(['ip' => $ip])
            ->log('Sensitive employee data viewed');

        return $employee;
    }
}
