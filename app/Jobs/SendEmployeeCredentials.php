<?php

namespace App\Jobs;

use App\Mail\EmployeeCredentials;
use App\Models\Employee;
use App\Models\User;
use App\Support\PasswordGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Gives an employee a fresh random password and emails it to them.
 *
 * The password exists in plain text only inside this job: the job payload carries ids, the user gets the
 * hash, and the mail is sent synchronously from here (not queued), so it never lands in the jobs table.
 * Dispatched after commit, so a rolled-back hire never gets an email. A retry issues a new password, which
 * is fine: only the last email sent is valid.
 */
class SendEmployeeCredentials implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $employeeId,
        public readonly int $userId,
        public readonly bool $isReset = false,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        $employee = Employee::query()->find($this->employeeId, ['id', 'code', 'first_name', 'middle_name', 'last_name', 'employment_status_id']);

        // Gone, or in a status that may not sign in: a password would be useless.
        if (! $user || ! $employee || ! $employee->loginAllowed()) {
            return;
        }

        $password = PasswordGenerator::generate();
        $user->forceFill(['password' => Hash::make($password)])->save();

        Mail::to($user->email)->send(new EmployeeCredentials(
            name: $employee->full_name !== '' ? $employee->full_name : $user->name,
            email: $user->email,
            password: $password,
            employeeCode: $employee->code,
            isReset: $this->isReset,
        ));
    }
}
