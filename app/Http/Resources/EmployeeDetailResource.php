<?php

namespace App\Http\Resources;

use App\Actions\Employees\EmployeeCompleteness;
use App\Models\City;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeDocument;
use App\Models\EmployeeQualification;
use App\Models\EmployeeSpouse;
use App\Models\EmployeeWorkExperience;
use App\Models\State;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Every field of the employee form, for drafts and real employees. Keys mirror the PATCH payload, so the
 * frontend can load a form straight from it. National ids (CNIC, passport, license, spouse CNIC) are
 * masked here; the full values come only from the audited GET /employees/{id}/sensitive.
 *
 * @mixin Employee
 */
class EmployeeDetailResource extends JsonResource
{
    /**
     * @return array<int|string, string|\Closure>
     */
    public static function relations(): array
    {
        $person = 'id,first_name,middle_name,last_name,avatar_path,designation_id';

        return [
            'createdBy:id,name',
            'employmentStatus:id,name,can_login,is_notice_period',
            'country:id,name,iso2,phone_code',
            'state:id,name',
            'city:id,name',
            'nationality:id,nationality',
            'probationDuration:id,name,months',
            "reportsTo:{$person}",
            'reportsTo.designation:id,name',
            "indirectReportsTo:{$person}",
            'indirectReportsTo.designation:id,name',
            'spouses.children',
            'qualifications.degreeProgram:id,name',
            'workExperiences.country:id,name,iso2,phone_code',
            'workExperiences.state:id,name',
            'workExperiences.city:id,name',
            'documents',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $e */
        $e = $this->resource;
        $date = fn (?Carbon $d) => $d?->toDateString();
        $enum = fn (?BackedEnum $v) => $v?->value;

        return [
            'id' => $e->id,
            'code' => $e->code,
            'is_draft' => $e->is_draft,
            'created_by' => $e->createdBy ? ['id' => $e->createdBy->id, 'name' => $e->createdBy->name] : null,
            'created_at' => $e->created_at->toIso8601String(),
            'updated_at' => $e->updated_at->toIso8601String(),

            // Personal
            'avatar_url' => $e->avatar_url,
            'salutation' => $enum($e->salutation),
            'first_name' => $e->first_name,
            'middle_name' => $e->middle_name,
            'last_name' => $e->last_name,
            'name' => $e->full_name,
            'pseudo_name' => $e->pseudo_name,
            'father_husband_name' => $e->father_husband_name,
            'gender' => $enum($e->gender),
            'birth_date' => $date($e->birth_date),
            'cnic_masked' => self::maskCnic($e->cnic),
            'cnic_expiry' => $date($e->cnic_expiry),
            'personal_email' => $e->personal_email,
            'country_id' => $e->country_id,
            'country' => $e->country ? self::country($e->country) : null,
            'state_id' => $e->state_id,
            'state' => self::idName($e->state),
            'city_id' => $e->city_id,
            'city' => self::idName($e->city),
            'nationality_id' => $e->nationality_id,
            'nationality' => $e->nationality ? ['id' => $e->nationality->id, 'name' => $e->nationality->nationality] : null,
            'marital_status' => $enum($e->marital_status),
            'religion' => $enum($e->religion),
            'blood_group' => $enum($e->blood_group),

            // Positional
            'machine_uid' => $e->machine_uid,
            'email' => $e->email,
            'department_id' => $e->department_id,
            'designation_id' => $e->designation_id,
            'division_id' => $e->division_id,
            'campaign_id' => $e->campaign_id,
            'shift_id' => $e->shift_id,
            'location_id' => $e->location_id,
            'work_mode' => $enum($e->work_mode),
            'employment_status_id' => $e->employment_status_id,
            'employment_status' => $e->employmentStatus?->toRef(),
            'duty_type' => $enum($e->duty_type),
            'join_date' => $date($e->join_date),
            'probation_start_date' => $date($e->probation_start_date),
            'probation_duration_id' => $e->probation_duration_id,
            'probation_end_date' => $date($e->probationEndDate()),
            'confirmation_date' => $date($e->confirmation_date),
            'separation_date' => $date($e->separation_date),
            'reports_to_id' => $e->reports_to_id,
            'reports_to' => $e->reportsTo ? new EmployeeSummaryResource($e->reportsTo) : null,
            'indirect_reports_to_id' => $e->indirect_reports_to_id,
            'indirect_reports_to' => $e->indirectReportsTo ? new EmployeeSummaryResource($e->indirectReportsTo) : null,
            'has_team_authority' => $e->has_team_authority,
            'remote_access' => $e->remote_access,
            'permanent_remote_access' => $e->permanent_remote_access,
            'remote_access_from' => $date($e->remote_access_from),
            'remote_access_until' => $date($e->remote_access_until),

            // Contact
            'phone' => $e->phone,
            'secondary_phone' => $e->secondary_phone,
            'landline' => $e->landline,
            'permanent_address' => $e->permanent_address,
            'present_address' => $e->present_address,
            'emergency_contact_name' => $e->emergency_contact_name,
            'emergency_contact_phone' => $e->emergency_contact_phone,
            'emergency_contact_relation' => $enum($e->emergency_contact_relation),

            // Other
            'passport_no_masked' => self::maskTail($e->passport_no),
            'passport_expiry' => $date($e->passport_expiry),
            'driving_license_no_masked' => self::maskTail($e->driving_license_no),
            'driving_license_expiry' => $date($e->driving_license_expiry),

            'spouses' => $e->spouses->map(fn (EmployeeSpouse $s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'cnic_masked' => self::maskCnic($s->cnic),
                'birth_date' => $date($s->birth_date),
                'children' => $s->children->map(fn (EmployeeChild $c) => [
                    'id' => $c->id,
                    'full_name' => $c->full_name,
                    'birth_date' => $date($c->birth_date),
                ])->all(),
            ])->all(),

            'qualifications' => $e->qualifications->map(fn (EmployeeQualification $q) => [
                'id' => $q->id,
                'institution' => $q->institution,
                'degree_program_id' => $q->degree_program_id,
                'degree_program' => $q->degreeProgram ? ['id' => $q->degreeProgram->id, 'name' => $q->degreeProgram->name] : null,
                'start_date' => $date($q->start_date),
                'end_date' => $date($q->end_date),
                'year' => $q->year,
            ])->all(),

            'has_work_experience' => $e->has_work_experience,
            'work_experiences' => $e->workExperiences->map(fn (EmployeeWorkExperience $w) => [
                'id' => $w->id,
                'company_name' => $w->company_name,
                'designation' => $w->designation,
                // Decimal string to avoid float rounding on money.
                'last_salary' => $w->last_salary,
                'country_id' => $w->country_id,
                'country' => $w->country ? self::country($w->country) : null,
                'state_id' => $w->state_id,
                'state' => self::idName($w->state),
                'city_id' => $w->city_id,
                'city' => self::idName($w->city),
                'period_from' => $date($w->period_from),
                'period_to' => $date($w->period_to),
                'contact_person_name' => $w->contact_person_name,
                'contact_person_email' => $w->contact_person_email,
                'contact_person_phone' => $w->contact_person_phone,
                'experience_letter' => $w->experience_letter_path ? [
                    'name' => $w->experience_letter_name,
                    'mime_type' => $w->experience_letter_mime,
                    'size' => $w->experience_letter_size,
                    'download_url' => route('employees.work-experiences.letter', [$e->id, $w->id]),
                ] : null,
                'reason_for_leaving' => $w->reason_for_leaving,
                'description' => $w->description,
            ])->all(),

            'documents' => $e->documents->map(fn (EmployeeDocument $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size' => $d->size,
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'download_url' => route('employees.documents.download', [$e->id, $d->id]),
            ])->all(),

            // What still blocks submitting a draft (always complete for a real employee).
            'missing_fields' => $e->is_draft ? (new EmployeeCompleteness)->errors(EmployeeCompleteness::data($e)) : (object) [],
        ];
    }

    /** "35202-1234567-1" -> "35202-•••••••-1" */
    public static function maskCnic(?string $cnic): ?string
    {
        return $cnic === null ? null : preg_replace('/^(\d{5})-\d{7}-(\d)$/', '$1-•••••••-$2', $cnic);
    }

    /** "AB1234567" -> "••••••567" */
    public static function maskTail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 3)).mb_substr($value, -3);
    }

    /**
     * @return array{id: int, name: string, iso2: string, phone_code: string|null}
     */
    private static function country(Country $country): array
    {
        return ['id' => $country->id, 'name' => $country->name, 'iso2' => $country->iso2, 'phone_code' => $country->phone_code];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function idName(State|City|null $model): ?array
    {
        return $model ? ['id' => $model->id, 'name' => $model->name] : null;
    }
}
