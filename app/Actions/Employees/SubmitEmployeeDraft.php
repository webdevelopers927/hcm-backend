<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SubmitEmployeeDraft
{
    public function __construct(
        private readonly CreateEmployee $createEmployee,
        private readonly EmployeeCompleteness $completeness,
    ) {}

    /**
     * Turns a complete draft into a real employee: login account, code, invite email.
     * An incomplete draft gets a 422 listing every missing field and stays a draft.
     */
    public function handle(User $actor, Employee $draft, ?string $ip): Employee
    {
        $user = DB::transaction(function () use ($actor, $draft, $ip) {
            // Two submits racing on the same draft must not both create a login.
            $locked = Employee::query()->lockForUpdate()->findOrFail($draft->id);
            if (! $locked->is_draft) {
                throw new ConflictHttpException('This draft has already been submitted.');
            }

            $this->completeness->assertComplete($draft);
            $user = $this->createEmployee->finalize($draft);

            activity('employees')
                ->causedBy($actor)
                ->performedOn($draft)
                ->event('draft_submitted')
                ->withProperties(['ip' => $ip])
                ->log('Employee draft submitted');

            return $user;
        });

        $this->createEmployee->sendInvite($draft, $user);

        return $draft;
    }
}
