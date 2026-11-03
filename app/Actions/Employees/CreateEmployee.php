<?php

namespace App\Actions\Employees;

use App\Actions\Offboarding\OffboardingWorkflow;
use App\Actions\Shifts\AssignShift;
use App\Jobs\SendEmployeeCredentials;
use App\Models\Employee;
use App\Models\User;
use App\Support\FileBatch;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEmployee
{
    public function __construct(
        private readonly SaveEmployee $save,
        private readonly EmployeeCompleteness $completeness,
        private readonly AssignShift $assignShift,
        private readonly OffboardingWorkflow $offboarding,
    ) {}

    /**
     * A draft (anything may be missing) or a complete hire (employee + linked user, all or nothing).
     *
     * @param  array<string, mixed>  $data  validated StoreEmployeeRequest data
     */
    public function handle(array $data, User $actor, bool $asDraft = false): Employee
    {
        [$employee, $user] = FileBatch::transaction(function (FileBatch $files) use ($data, $actor, $asDraft) {
            $employee = new Employee;
            $employee->is_draft = true;
            $employee->created_by_id = $actor->id;
            $this->save->handle($employee, $data, $files);

            $user = null;
            if (! $asDraft) {
                $this->completeness->assertComplete($employee, enteringExit: $employee->hasExited());
                $user = $this->finalize($employee);
            }

            return [$employee, $user];
        });

        // Only after commit: a rolled-back hire never gets an email.
        if ($user) {
            $this->sendCredentials($employee, $user);
        }

        return $employee;
    }

    /**
     * Writes one complete hire (bulk import). The caller owns the transaction and has already checked
     * completeness on the row.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $passwordHash  pre-hashed unusable password; bulk import shares one to avoid N bcrypt calls
     * @return array{0: Employee, 1: User}
     */
    public function persist(array $data, FileBatch $files, ?User $actor = null, ?string $passwordHash = null): array
    {
        $employee = new Employee;
        $employee->is_draft = true;
        $employee->created_by_id = $actor?->id;
        $this->save->handle($employee, $data, $files);

        return [$employee, $this->finalize($employee, $passwordHash)];
    }

    /**
     * Turns a complete draft into a real employee: login account, code, is_draft = false.
     */
    public function finalize(Employee $employee, ?string $passwordHash = null): User
    {
        if (User::query()->where('email', $employee->email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This business email is already used by a login account.']);
        }

        $user = User::create([
            'name' => $employee->full_name,
            'email' => $employee->email,
            // Unusable placeholder; SendEmployeeCredentials replaces it with the emailed password.
            'password' => $passwordHash ?? Hash::make(Str::random(64)),
        ]);

        $employee->user()->associate($user);
        $employee->is_draft = false;
        $employee->code ??= Employee::formatCode($employee->id);
        $employee->save();

        // Start of the shift history: the shift they were hired on, from their first day.
        if ($employee->shift_id) {
            $this->assignShift->record(
                $employee->id, $employee->shift_id, ($employee->join_date ?? today())->toDateString(),
                $employee->created_by_id, alreadyApplied: true,
            );
        }

        // Hired straight into a notice period or an exit status (e.g. back-filling records): open the case too.
        $this->offboarding->syncWithStatus($employee, null, null);

        return $user;
    }

    /**
     * Queues the welcome email with a random password (generated and hashed in the job). Skipped for a
     * status that may not sign in.
     */
    public function sendCredentials(Employee $employee, User $user): void
    {
        if (! $employee->loginAllowed()) {
            return;
        }

        SendEmployeeCredentials::dispatch($employee->id, $user->id);
    }
}
