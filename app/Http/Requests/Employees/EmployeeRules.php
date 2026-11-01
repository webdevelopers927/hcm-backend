<?php

namespace App\Http\Requests\Employees;

use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\WorkMode;
use App\Models\Employee;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by StoreEmployeeRequest and UpdateEmployeeRequest.
 */
trait EmployeeRules
{
    protected function normaliseEmployeeInput(): void
    {
        $merge = [];
        if (is_string($this->input('email'))) {
            $merge['email'] = strtolower(trim($this->input('email')));
        }
        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeRules(?Employee $employee): array
    {
        $activeEmployee = Rule::exists('employees', 'id')->whereNull('deleted_at');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'pseudo_name' => ['nullable', 'string', 'max:100'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('employees', 'email')->ignore($employee?->id),
                Rule::unique('users', 'email')->ignore($employee?->user_id),
            ],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'cnic' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d$/', $this->uniqueCnic($employee)],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'machine_uid' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'machine_uid')->ignore($employee?->id)],
            'designation_id' => ['required', 'integer', Rule::exists('designations', 'id')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'reports_to_id' => ['nullable', 'integer', $activeEmployee, $this->noReportingCycle($employee)],
            'work_mode' => ['nullable', Rule::enum(WorkMode::class)],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'join_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    private function uniqueCnic(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            $taken = Employee::withTrashed()
                ->where('cnic_hash', Employee::cnicBlindIndex((string) $value))
                ->when($employee, fn ($q) => $q->whereKeyNot($employee->id))
                ->exists();

            if ($taken) {
                $fail('This CNIC is already registered to another employee.');
            }
        };
    }

    /** An employee cannot report to themselves or to anyone below them in the tree. */
    private function noReportingCycle(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            if (! $employee || $value === null) {
                return;
            }

            $managerId = (int) $value;
            $seen = [];
            while ($managerId && ! isset($seen[$managerId])) {
                if ($managerId === $employee->id) {
                    $fail('An employee cannot report to themselves or to one of their reports.');

                    return;
                }
                $seen[$managerId] = true;
                $managerId = (int) Employee::query()->whereKey($managerId)->value('reports_to_id');
            }
        };
    }
}
