<?php

namespace App\Actions\Employees;

use App\Actions\Shifts\AssignShift;
use App\Models\Employee;
use App\Models\User;
use App\Support\FileBatch;

class UpdateEmployee
{
    public function __construct(
        private readonly SaveEmployee $save,
        private readonly EmployeeCompleteness $completeness,
        private readonly AssignShift $assignShift,
    ) {}

    /**
     * Partial update of a draft or a real employee. A real employee must still be complete afterwards
     * (otherwise nothing is saved), name or email changes are mirrored to the linked login account,
     * a shift change is added to the shift history as of today, and moving to an employment status that
     * may not sign in revokes the employee's tokens.
     *
     * @param  array<string, mixed>  $data  validated UpdateEmployeeRequest data
     */
    public function handle(Employee $employee, array $data, ?User $actor = null): Employee
    {
        FileBatch::transaction(function (FileBatch $files) use ($employee, $data, $actor) {
            $shiftBefore = $employee->shift_id;
            $statusBefore = $employee->employment_status_id;
            $this->save->handle($employee, $data, $files);

            if ($employee->is_draft) {
                return;
            }

            $this->completeness->assertComplete($employee);

            if ($employee->shift_id !== null && (int) $employee->shift_id !== (int) $shiftBefore) {
                $this->assignShift->record($employee->id, (int) $employee->shift_id, today()->toDateString(), $actor?->id, alreadyApplied: true);
            }

            $user = $employee->user;
            if ($user && ($user->name !== $employee->full_name || $user->email !== $employee->email)) {
                $user->forceFill([
                    'name' => $employee->full_name,
                    'email' => $employee->email,
                ])->save();
            }

            // Moved to a status that may not sign in: end their sessions now.
            if ($user && (int) $employee->employment_status_id !== (int) $statusBefore && ! $employee->loginAllowed()) {
                $user->tokens()->delete();
            }
        });

        return $employee;
    }
}
