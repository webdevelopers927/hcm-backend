<?php

namespace App\Actions\Employees;

use App\Enums\EmployeeStatus;
use App\Mail\EmployeeInvitation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateEmployee
{
    public const EMPLOYEE_FIELDS = [
        'machine_uid', 'first_name', 'last_name', 'pseudo_name', 'email', 'phone', 'gender', 'birth_date', 'cnic',
        'designation_id', 'department_id', 'division_id', 'campaign_id', 'shift_id', 'location_id', 'reports_to_id',
        'work_mode', 'status', 'join_date',
    ];

    /**
     * Employee + linked user, all or nothing.
     *
     * @param  array<string, mixed>  $data  validated StoreEmployeeRequest data
     */
    public function handle(array $data): Employee
    {
        [$employee, $user] = DB::transaction(fn () => $this->persist($data));

        // Only after commit: a rolled-back hire never gets an email.
        $this->sendInvite($employee, $user);

        return $employee;
    }

    /**
     * Writes one hire. Callers own the transaction (single create or bulk import).
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $passwordHash  pre-hashed unusable password; bulk import shares one to avoid N bcrypt calls
     * @return array{0: Employee, 1: User}
     */
    public function persist(array $data, ?string $passwordHash = null): array
    {
        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            // Unusable until the invitee sets their own password via the invite link.
            'password' => $passwordHash ?? Hash::make(Str::random(64)),
        ]);

        $employee = new Employee(Arr::only($data, self::EMPLOYEE_FIELDS));
        $employee->status ??= EmployeeStatus::Active;
        $employee->user()->associate($user);
        $employee->save();

        return [$employee, $user];
    }

    public function sendInvite(Employee $employee, User $user): void
    {
        $token = Password::broker('invites')->createToken($user);
        Mail::to($user->email)->queue(new EmployeeInvitation($employee->id, $user->name, $user->email, $token));
    }
}
