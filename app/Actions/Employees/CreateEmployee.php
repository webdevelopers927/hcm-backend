<?php

namespace App\Actions\Employees;

use App\Mail\EmployeeInvitation;
use App\Models\Employee;
use App\Models\User;
use App\Support\FileBatch;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEmployee
{
    public function __construct(
        private readonly SaveEmployee $save,
        private readonly EmployeeCompleteness $completeness,
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
                $this->completeness->assertComplete($employee);
                $user = $this->finalize($employee);
            }

            return [$employee, $user];
        });

        // Only after commit: a rolled-back hire never gets an email.
        if ($user) {
            $this->sendInvite($employee, $user);
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
            // Unusable until the invitee sets their own password via the invite link.
            'password' => $passwordHash ?? Hash::make(Str::random(64)),
        ]);

        $employee->user()->associate($user);
        $employee->is_draft = false;
        $employee->code ??= Employee::formatCode($employee->id);
        $employee->save();

        return $user;
    }

    public function sendInvite(Employee $employee, User $user): void
    {
        $token = Password::broker('invites')->createToken($user);
        Mail::to($user->email)->queue(new EmployeeInvitation($employee->id, $user->name, $user->email, $token));
    }
}
