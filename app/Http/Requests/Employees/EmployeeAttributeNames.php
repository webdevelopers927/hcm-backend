<?php

namespace App\Http\Requests\Employees;

/**
 * Human field names for validation messages ("The business email field is required.").
 */
final class EmployeeAttributeNames
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'email' => 'business email',
            'personal_email' => 'personal email',
            'cnic' => 'CNIC',
            'cnic_expiry' => 'CNIC expiry',
            'father_husband_name' => 'father/husband name',
            'birth_date' => 'date of birth',
            'country_id' => 'country',
            'state_id' => 'state',
            'city_id' => 'city',
            'nationality_id' => 'nationality',
            'department_id' => 'department',
            'designation_id' => 'designation',
            'division_id' => 'division',
            'campaign_id' => 'campaign',
            'shift_id' => 'shift',
            'location_id' => 'location',
            'reports_to_id' => 'reporting authority',
            'indirect_reports_to_id' => 'indirect reporting authority',
            'status' => 'employment status',
            'duty_type' => 'duty type',
            'join_date' => 'date of joining',
            'probation_duration_id' => 'probation duration',
            'confirmation_date' => 'date of confirmation',
            'separation_date' => 'date of separation',
            'remote_access_from' => 'remote access allowed from',
            'remote_access_until' => 'remote access allowed till',
            'phone' => 'primary contact number',
            'secondary_phone' => 'secondary contact number',
            'landline' => 'landline number',
            'emergency_contact_phone' => 'emergency contact number',
            'emergency_contact_relation' => 'emergency contact relation',
            'passport_no' => 'passport number',
            'driving_license_no' => 'driving license number',
            'avatar_upload_id' => 'profile picture',
            'spouses.*.full_name' => 'spouse full name',
            'spouses.*.cnic' => 'spouse CNIC',
            'spouses.*.birth_date' => 'spouse date of birth',
            'spouses.*.children.*.full_name' => 'child full name',
            'spouses.*.children.*.birth_date' => 'child date of birth',
            'qualifications.*.institution' => 'university/college name',
            'qualifications.*.degree_program_id' => 'degree program',
            'qualifications.*.start_date' => 'degree start date',
            'qualifications.*.end_date' => 'degree end date',
            'qualifications.*.year' => 'degree year',
            'work_experiences.*.company_name' => 'company name',
            'work_experiences.*.designation' => 'designation',
            'work_experiences.*.last_salary' => 'last salary',
            'work_experiences.*.country_id' => 'country',
            'work_experiences.*.state_id' => 'state',
            'work_experiences.*.city_id' => 'city',
            'work_experiences.*.period_from' => 'period from',
            'work_experiences.*.period_to' => 'period to',
            'work_experiences.*.contact_person_name' => 'contact person name',
            'work_experiences.*.contact_person_email' => 'contact person email',
            'work_experiences.*.contact_person_phone' => 'contact person number',
            'work_experiences.*.experience_letter' => 'experience letter',
            'work_experiences.*.experience_letter_upload_id' => 'experience letter',
            'work_experiences.*.reason_for_leaving' => 'reason for leaving',
            'documents.*.upload_id' => 'file',
        ];
    }
}
