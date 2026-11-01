<?php

namespace App\Http\Requests\Employees;

use App\Enums\BloodGroup;
use App\Enums\DutyType;
use App\Enums\EmergencyContactRelation;
use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\Religion;
use App\Enums\Salutation;
use App\Enums\UploadPurpose;
use App\Enums\WorkMode;
use App\Models\City;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\State;
use App\Rules\PhoneNumber;
use BackedEnum;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Field rules shared by StoreEmployeeRequest and UpdateEmployeeRequest (and the bulk import).
 *
 * These check format, existence and uniqueness only; every field may be empty so a draft can be saved
 * at any point. Whether a record is complete enough to be a real employee is EmployeeCompleteness's job.
 *
 * Arrays (spouses, qualifications, work_experiences, documents) replace the whole list when sent: items
 * with an `id` update that row, items without one are created, and rows left out are deleted.
 */
trait EmployeeRules
{
    protected function normaliseEmployeeInput(): void
    {
        $merge = [];
        foreach (['email', 'personal_email'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = strtolower(trim($this->input($key)));
            }
        }
        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeRules(?Employee $employee): array
    {
        $date = ['nullable', 'date_format:Y-m-d'];
        $userId = $this->user()?->getAuthIdentifier();
        $countryId = $this->effective('country_id', $employee);
        $stateId = $this->effective('state_id', $employee);
        $employeePhone = ['nullable', PhoneNumber::forCountryId($countryId)];

        return [
            // Personal
            'avatar_upload_id' => ['nullable', 'uuid', $this->ownedUpload($userId, UploadPurpose::Avatar)],
            'salutation' => ['nullable', Rule::enum(Salutation::class)],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'pseudo_name' => ['nullable', 'string', 'max:100'],
            'father_husband_name' => ['nullable', 'string', 'max:200'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => [...$date, 'before:today'],
            'cnic' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d$/', $this->uniqueCnic($employee)],
            'cnic_expiry' => $date,
            'personal_email' => ['nullable', 'string', 'email', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'state_id' => ['nullable', 'integer', Rule::exists('states', 'id')->where('country_id', $countryId ?? 0)],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('state_id', $stateId ?? 0)],
            'nationality_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'religion' => ['nullable', Rule::enum(Religion::class)],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],

            // Positional
            'machine_uid' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'machine_uid')->ignore($employee?->id)],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('employees', 'email')->ignore($employee?->id),
                Rule::unique('users', 'email')->ignore($employee?->user_id),
            ],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'reports_to_id' => ['nullable', 'integer', $this->manager($employee), $this->noReportingCycle($employee)],
            'indirect_reports_to_id' => ['nullable', 'integer', $this->manager($employee), 'different:reports_to_id'],
            'work_mode' => ['nullable', Rule::enum(WorkMode::class)],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'duty_type' => ['nullable', Rule::enum(DutyType::class)],
            'join_date' => $date,
            'probation_start_date' => $date,
            'probation_duration_id' => ['nullable', 'integer', Rule::exists('probation_durations', 'id')],
            'confirmation_date' => $date,
            'separation_date' => $date,
            'has_team_authority' => ['nullable', 'boolean'],
            'remote_access' => ['nullable', 'boolean'],
            'permanent_remote_access' => ['nullable', 'boolean'],
            'remote_access_from' => $date,
            'remote_access_until' => $date,

            // Contact (`phone` is the primary contact number)
            'phone' => $employeePhone,
            'secondary_phone' => $employeePhone,
            'landline' => $employeePhone,
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'present_address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:200'],
            'emergency_contact_phone' => $employeePhone,
            'emergency_contact_relation' => ['nullable', Rule::enum(EmergencyContactRelation::class)],

            // Other
            'passport_no' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'passport_expiry' => $date,
            'driving_license_no' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'driving_license_expiry' => $date,

            // Family: several spouses, each with several children
            'spouses' => ['sometimes', 'array', 'max:4'],
            'spouses.*' => ['array'],
            'spouses.*.id' => ['nullable', 'integer', Rule::exists('employee_spouses', 'id')->where('employee_id', $employee->id ?? 0)],
            'spouses.*.full_name' => ['nullable', 'string', 'max:200'],
            'spouses.*.cnic' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d$/'],
            'spouses.*.birth_date' => [...$date, 'before:today'],
            'spouses.*.children' => ['sometimes', 'array', 'max:20'],
            'spouses.*.children.*' => ['array'],
            'spouses.*.children.*.id' => ['nullable', 'integer', $this->ownChild($employee)],
            'spouses.*.children.*.full_name' => ['nullable', 'string', 'max:200'],
            'spouses.*.children.*.birth_date' => [...$date, 'before_or_equal:today'],

            // Qualifications
            'qualifications' => ['sometimes', 'array', 'max:20'],
            'qualifications.*' => ['array'],
            'qualifications.*.id' => ['nullable', 'integer', Rule::exists('employee_qualifications', 'id')->where('employee_id', $employee->id ?? 0)],
            'qualifications.*.institution' => ['nullable', 'string', 'max:200'],
            'qualifications.*.degree_program_id' => ['nullable', 'integer', Rule::exists('degree_programs', 'id')],
            'qualifications.*.start_date' => $date,
            'qualifications.*.end_date' => [...$date, 'after_or_equal:qualifications.*.start_date'],
            'qualifications.*.year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 10)],

            // Work experience (the list is only allowed while has_work_experience is on)
            'has_work_experience' => ['nullable', 'boolean'],
            'work_experiences' => ['sometimes', 'array', 'max:20'],
            'work_experiences.*' => ['array'],
            'work_experiences.*.id' => ['nullable', 'integer', Rule::exists('employee_work_experiences', 'id')->where('employee_id', $employee->id ?? 0)],
            'work_experiences.*.company_name' => ['nullable', 'string', 'max:200'],
            'work_experiences.*.designation' => ['nullable', 'string', 'max:150'],
            'work_experiences.*.last_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'work_experiences.*.country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'work_experiences.*.state_id' => ['nullable', 'integer', Rule::exists('states', 'id')],
            'work_experiences.*.city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'work_experiences.*.period_from' => [...$date, 'before_or_equal:today'],
            'work_experiences.*.period_to' => [...$date, 'after_or_equal:work_experiences.*.period_from'],
            'work_experiences.*.contact_person_name' => ['nullable', 'string', 'max:200'],
            'work_experiences.*.contact_person_email' => ['nullable', 'string', 'email', 'max:255'],
            'work_experiences.*.contact_person_phone' => ['nullable', 'string', 'max:30'],
            'work_experiences.*.experience_letter_upload_id' => ['nullable', 'uuid', $this->ownedUpload($userId, UploadPurpose::ExperienceLetter)],
            'work_experiences.*.reason_for_leaving' => ['nullable', 'string', 'max:1000'],
            'work_experiences.*.description' => ['nullable', 'string', 'max:2000'],

            // Documents: a new item needs an upload; an existing one may change its title or file
            'documents' => ['sometimes', 'array', 'max:50'],
            'documents.*' => ['array'],
            'documents.*.id' => ['nullable', 'integer', Rule::exists('employee_documents', 'id')->where('employee_id', $employee->id ?? 0)],
            'documents.*.title' => ['nullable', 'string', 'max:150'],
            'documents.*.upload_id' => ['required_without:documents.*.id', 'nullable', 'uuid', $this->ownedUpload($userId, UploadPurpose::Document)],
        ];
    }

    /**
     * Checks that span fields, using the stored value when a partial update doesn't send the field.
     */
    protected function employeeAfterValidation(Validator $validator, ?Employee $employee): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $value = fn (string $key) => $this->effective($key, $employee);
        $flag = fn (string $key) => $this->exists($key) ? $this->boolean($key) : (bool) $employee?->getAttribute($key);
        $errors = $validator->errors();

        $this->assertDateOrder($errors, $value('cnic_expiry'), $value('birth_date'), 'cnic_expiry', 'The CNIC expiry must be after the date of birth.');
        $this->assertDateOrder($errors, $value('confirmation_date'), $value('join_date'), 'confirmation_date', 'The date of confirmation cannot be before the date of joining.');
        $this->assertDateOrder($errors, $value('separation_date'), $value('join_date'), 'separation_date', 'The date of separation cannot be before the date of joining.');
        $this->assertDateOrder($errors, $value('probation_start_date'), $value('join_date'), 'probation_start_date', 'Probation cannot start before the date of joining.');

        if ($flag('remote_access') && ! $flag('permanent_remote_access')) {
            $this->assertDateOrder($errors, $value('remote_access_until'), $value('remote_access_from'), 'remote_access_until', 'Remote access must end on or after the day it starts.');
        }

        $designationId = $value('designation_id');
        $departmentId = $value('department_id');
        if ($designationId && $departmentId && ($this->has('designation_id') || $this->has('department_id'))) {
            $designationDepartment = Designation::query()->whereKey($designationId)->value('department_id');
            if ($designationDepartment !== null && (int) $designationDepartment !== (int) $departmentId) {
                $errors->add('designation_id', 'The designation does not belong to the selected department.');
            }
        }

        if ($employee && $this->has('has_team_authority') && ! $this->boolean('has_team_authority') && $employee->has_team_authority) {
            $reports = Employee::query()->finalized()
                ->where(fn ($q) => $q->where('reports_to_id', $employee->id)->orWhere('indirect_reports_to_id', $employee->id))
                ->count();
            if ($reports > 0) {
                $errors->add('has_team_authority', "Team authority can't be removed while {$reports} employee(s) report to this person.");
            }
        }

        $experiences = $this->input('work_experiences');
        if (! $flag('has_work_experience') && is_array($experiences) && $experiences !== []) {
            $errors->add('work_experiences', 'Turn on "has work experience" before adding work experience.');
        }

        foreach (is_array($experiences) ? $experiences : [] as $i => $experience) {
            $this->validateExperiencePlace($errors, (int) $i, (array) $experience);
        }
    }

    /**
     * The request value when the field is sent, otherwise the stored one (partial updates).
     */
    protected function effective(string $key, ?Employee $employee): mixed
    {
        if ($this->exists($key)) {
            return $this->input($key);
        }

        $stored = $employee?->getAttribute($key);

        return $stored instanceof BackedEnum ? $stored->value : $stored;
    }

    /**
     * @param  array<string, mixed>  $experience
     */
    private function validateExperiencePlace(MessageBag $errors, int $i, array $experience): void
    {
        $prefix = "work_experiences.{$i}";
        $countryId = $experience['country_id'] ?? null;
        $stateId = $experience['state_id'] ?? null;

        if ($stateId && (int) State::query()->whereKey($stateId)->value('country_id') !== (int) $countryId) {
            $errors->add("{$prefix}.state_id", 'The state does not belong to the selected country.');
        }
        if (($cityId = $experience['city_id'] ?? null) && (int) City::query()->whereKey($cityId)->value('state_id') !== (int) $stateId) {
            $errors->add("{$prefix}.city_id", 'The city does not belong to the selected state.');
        }

        // Checked here rather than as a rule because the country differs per item.
        $phone = $experience['contact_person_phone'] ?? null;
        $phoneError = $phone !== null && $phone !== '' ? PhoneNumber::forCountryId($countryId)->error($phone) : null;
        if ($phoneError !== null) {
            $errors->add("{$prefix}.contact_person_phone", str_replace(':attribute', 'contact person number', $phoneError));
        }
    }

    private function assertDateOrder(MessageBag $errors, mixed $later, mixed $earlier, string $field, string $message): void
    {
        if (! $later || ! $earlier) {
            return;
        }

        if (Carbon::parse((string) $later)->lt(Carbon::parse((string) $earlier))) {
            $errors->add($field, $message);
        }
    }

    private function ownedUpload(mixed $userId, UploadPurpose $purpose): Exists
    {
        return Rule::exists('uploads', 'id')->where('user_id', $userId ?? 0)->where('purpose', $purpose->value);
    }

    /** A child row of one of this employee's spouses. */
    private function ownChild(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            $owned = $employee && EmployeeChild::query()
                ->whereKey((int) $value)
                ->whereIn('employee_spouse_id', $employee->spouses()->select('id'))
                ->exists();

            if (! $owned) {
                $fail('The selected child is invalid.');
            }
        };
    }

    /** A real (non-draft, not deleted) employee with team authority, other than this one. */
    private function manager(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            if ($employee && (int) $value === $employee->id) {
                $fail('An employee cannot report to themselves.');

                return;
            }

            $manager = Employee::query()->finalized()->whereKey((int) $value)->first(['id', 'has_team_authority']);
            if (! $manager) {
                $fail('The selected :attribute is invalid.');
            } elseif (! $manager->has_team_authority) {
                $fail('The selected person does not have team authority.');
            }
        };
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return EmployeeAttributeNames::all();
    }
}
