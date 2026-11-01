<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\FileBatch;

class UpdateEmployee
{
    public function __construct(
        private readonly SaveEmployee $save,
        private readonly EmployeeCompleteness $completeness,
    ) {}

    /**
     * Partial update of a draft or a real employee. A real employee must still be complete afterwards
     * (otherwise nothing is saved), and name or email changes are mirrored to the linked login account.
     *
     * @param  array<string, mixed>  $data  validated UpdateEmployeeRequest data
     */
    public function handle(Employee $employee, array $data): Employee
    {
        FileBatch::transaction(function (FileBatch $files) use ($employee, $data) {
            $this->save->handle($employee, $data, $files);

            if ($employee->is_draft) {
                return;
            }

            $this->completeness->assertComplete($employee);

            $user = $employee->user;
            if ($user && ($user->name !== $employee->full_name || $user->email !== $employee->email)) {
                $user->forceFill([
                    'name' => $employee->full_name,
                    'email' => $employee->email,
                ])->save();
            }
        });

        return $employee;
    }
}
