<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateEmployee
{
    /**
     * Partial update. Name or email changes are mirrored to the linked login account.
     *
     * @param  array<string, mixed>  $data  validated UpdateEmployeeRequest data
     */
    public function handle(Employee $employee, array $data): Employee
    {
        DB::transaction(function () use ($employee, $data) {
            $employee->fill(Arr::only($data, CreateEmployee::EMPLOYEE_FIELDS));
            $identityChanged = $employee->isDirty(['first_name', 'last_name', 'email']);
            $employee->save();

            if ($identityChanged && $employee->user) {
                $employee->user->forceFill([
                    'name' => $employee->full_name,
                    'email' => $employee->email,
                ])->save();
            }
        });

        return $employee;
    }
}
