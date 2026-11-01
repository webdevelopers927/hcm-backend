<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Support\FileBatch;

class DeleteEmployee
{
    public function __construct(private readonly SaveEmployee $save) {}

    /**
     * A real employee is soft deleted and the linked user's API tokens are revoked. A draft is discarded
     * for good, files included. Either way an audit entry is written.
     * Authorization (incl. "not yourself") is the caller's job via EmployeePolicy::delete.
     */
    public function handle(User $actor, Employee $employee, ?string $ip): void
    {
        FileBatch::transaction(function (FileBatch $files) use ($actor, $employee, $ip) {
            $draft = $employee->is_draft;

            activity('employees')
                ->causedBy($actor)
                ->performedOn($employee)
                ->event($draft ? 'draft_deleted' : 'deleted')
                ->withProperties(['ip' => $ip])
                ->log($draft ? 'Employee draft deleted' : 'Employee deleted');

            if ($draft) {
                $this->save->deleteAllFiles($employee, $files);
                $employee->forceDelete();

                return;
            }

            $employee->delete();
            $employee->user?->tokens()->delete();
        });
    }
}
