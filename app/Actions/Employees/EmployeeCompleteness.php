<?php

namespace App\Actions\Employees;

use App\Http\Requests\Employees\EmployeeAttributeNames;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeQualification;
use App\Models\EmployeeSpouse;
use App\Models\EmployeeWorkExperience;
use App\Models\EmploymentStatus;
use BackedEnum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a record needs before it can be a real employee. Drafts may skip all of it; creating an employee
 * directly, submitting a draft, editing a real employee and importing all go through here.
 *
 * Runs on form-shaped data (same keys as the API payload), so error keys match the form fields.
 */
class EmployeeCompleteness
{
    /** Relations data() reads; load them before calling it. */
    public const RELATIONS = ['spouses.children', 'qualifications', 'workExperiences'];

    /**
     * @param  array<string, mixed>  $data  the record being checked (some rules depend on it)
     * @return array<string, mixed>
     */
    public static function rules(array $data): array
    {
        $whenExperienced = 'required_if_accepted:has_work_experience';
        $flag = fn (string $key) => filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOL);
        $statusId = $data['employment_status_id'] ?? null;
        // Exit details are needed while serving notice, and when someone is moved into a status that
        // ends employment (`entering_exit`; not for people who were already there before these fields existed).
        $needsExitDetails = $flag('entering_exit') || (is_numeric($statusId)
            && (bool) EmploymentStatus::query()->whereKey((int) $statusId)->value('is_notice_period'));

        return [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'email' => ['required'],
            'cnic' => ['required'],
            'department_id' => ['required'],
            'designation_id' => ['required'],
            'employment_status_id' => ['required'],
            'duty_type_id' => ['required'],
            'join_date' => ['required'],

            'resigned_date' => [Rule::requiredIf($needsExitDetails)],
            'last_working_day' => [Rule::requiredIf($needsExitDetails)],
            'reason_for_resignation' => [Rule::requiredIf($needsExitDetails)],

            'remote_access_from' => ['required_if_accepted:remote_access'],
            // Permanent remote access has no end date.
            'remote_access_until' => [Rule::requiredIf($flag('remote_access') && ! $flag('permanent_remote_access'))],

            'spouses.*.full_name' => ['required'],
            'spouses.*.children.*.full_name' => ['required'],

            'qualifications.*.institution' => ['required'],
            'qualifications.*.degree_program_id' => ['required'],

            'work_experiences' => [$whenExperienced, 'array'],
            'work_experiences.*.company_name' => [$whenExperienced],
            'work_experiences.*.designation' => [$whenExperienced],
            'work_experiences.*.last_salary' => [$whenExperienced],
            'work_experiences.*.country_id' => [$whenExperienced],
            'work_experiences.*.state_id' => [$whenExperienced],
            'work_experiences.*.city_id' => [$whenExperienced],
            'work_experiences.*.period_from' => [$whenExperienced],
            'work_experiences.*.period_to' => [$whenExperienced],
            'work_experiences.*.contact_person_name' => [$whenExperienced],
            'work_experiences.*.contact_person_email' => [$whenExperienced],
            'work_experiences.*.contact_person_phone' => [$whenExperienced],
            'work_experiences.*.experience_letter' => [$whenExperienced],
            'work_experiences.*.reason_for_leaving' => [$whenExperienced],
        ];
    }

    /**
     * Missing fields, keyed like the form ("cnic", "work_experiences.0.company_name"). Empty when complete.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function errors(array $data): array
    {
        $validator = Validator::make($data, self::rules($data), self::messages(), EmployeeAttributeNames::all());

        /** @var array<string, list<string>> $messages */
        $messages = $validator->errors()->messages();

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'required_if_accepted' => 'The :attribute field is required.',
            'work_experiences.required_if_accepted' => 'Add at least one work experience, or turn off "has work experience".',
        ];
    }

    /**
     * @param  bool  $enteringExit  the employee is being moved into (or created in) a status that ends employment
     *
     * @throws ValidationException listing every missing field
     */
    public function assertComplete(Employee $employee, bool $enteringExit = false): void
    {
        $employee->load(self::RELATIONS);
        $errors = $this->errors([...self::data($employee), 'entering_exit' => $enteringExit]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The stored record in payload shape (only the keys the rules look at).
     *
     * @return array<string, mixed>
     */
    public static function data(Employee $employee): array
    {
        $data = [];
        foreach (['first_name', 'last_name', 'email', 'cnic', 'department_id', 'designation_id', 'employment_status_id', 'duty_type_id',
            'join_date', 'remote_access', 'permanent_remote_access', 'remote_access_from', 'remote_access_until',
            'has_work_experience', 'resigned_date', 'last_working_day', 'reason_for_resignation'] as $key) {
            $value = $employee->getAttribute($key);
            $data[$key] = $value instanceof BackedEnum ? $value->value : $value;
        }

        $data['spouses'] = $employee->spouses->map(fn (EmployeeSpouse $s) => [
            'full_name' => $s->full_name,
            'children' => $s->children->map(fn (EmployeeChild $c) => ['full_name' => $c->full_name])->all(),
        ])->all();

        $data['qualifications'] = $employee->qualifications->map(fn (EmployeeQualification $q) => [
            'institution' => $q->institution,
            'degree_program_id' => $q->degree_program_id,
        ])->all();

        $data['work_experiences'] = $employee->workExperiences->map(fn (EmployeeWorkExperience $w) => [
            'company_name' => $w->company_name,
            'designation' => $w->designation,
            'last_salary' => $w->last_salary,
            'country_id' => $w->country_id,
            'state_id' => $w->state_id,
            'city_id' => $w->city_id,
            'period_from' => $w->period_from,
            'period_to' => $w->period_to,
            'contact_person_name' => $w->contact_person_name,
            'contact_person_email' => $w->contact_person_email,
            'contact_person_phone' => $w->contact_person_phone,
            'experience_letter' => $w->experience_letter_path,
            'reason_for_leaving' => $w->reason_for_leaving,
        ])->all();

        return $data;
    }
}
