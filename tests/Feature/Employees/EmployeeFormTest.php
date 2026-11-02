<?php

use App\Models\DegreeProgram;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\ProbationDuration;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    actingAsUser();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');
    seedGeo();

    $designation = Designation::query()->whereHas('department', fn ($q) => $q->where('name', 'Engineering'))->firstOrFail();
    $this->core = [
        'first_name' => 'Ayesha',
        'last_name' => 'Khan',
        'email' => 'ayesha.khan@company.pk',
        'cnic' => '35202-1234567-2',
        'department_id' => $designation->department_id,
        'designation_id' => $designation->id,
        'employment_status_id' => statusId('Active'),
        'duty_type' => 'full_time',
        'join_date' => '2026-01-05',
    ];
});

/** A real employee created through the API. */
function hire(array $extra = []): Employee
{
    $id = test()->postJson('/api/v1/employees', array_merge(test()->core, $extra))->assertCreated()->json('data.id');

    return Employee::findOrFail($id);
}

function experience(array $override = []): array
{
    return array_merge([
        'company_name' => 'Systems Ltd',
        'designation' => 'Software Engineer',
        'last_salary' => 150000,
        'country_id' => 167,
        'state_id' => 3176,
        'city_id' => 85485,
        'period_from' => '2022-01-01',
        'period_to' => '2025-12-31',
        'contact_person_name' => 'Bilal Ahmed',
        'contact_person_email' => 'bilal@systems.pk',
        'contact_person_phone' => '0300 7654321',
        'experience_letter_upload_id' => uploadFile('experience_letter'),
        'reason_for_leaving' => 'Growth',
        'description' => null,
    ], $override);
}

it('saves every personal, positional, contact and other field and reads them back', function () {
    $manager = Employee::factory()->teamLead()->create();
    $dotted = Employee::factory()->teamLead()->create();
    $probation = ProbationDuration::where('months', 3)->firstOrFail();

    $id = $this->postJson('/api/v1/employees/drafts', [
        'avatar_upload_id' => uploadFile('avatar'),
        'salutation' => 'ms',
        'first_name' => 'Ayesha',
        'middle_name' => 'Noor',
        'last_name' => 'Khan',
        'father_husband_name' => 'Imran Khan',
        'gender' => 'female',
        'birth_date' => '1995-04-18',
        'cnic' => '35202-1234567-2',
        'cnic_expiry' => '2030-04-18',
        'personal_email' => 'Ayesha@Gmail.com',
        'country_id' => 167,
        'state_id' => 3176,
        'city_id' => 85485,
        'nationality_id' => 167,
        'marital_status' => 'married',
        'religion' => 'islam',
        'blood_group' => 'B+',
        'email' => 'ayesha.khan@company.pk',
        'department_id' => $this->core['department_id'],
        'designation_id' => $this->core['designation_id'],
        'employment_status_id' => statusId('Probation'),
        'duty_type' => 'contract',
        'join_date' => '2026-01-05',
        'probation_start_date' => '2026-01-05',
        'probation_duration_id' => $probation->id,
        'confirmation_date' => '2026-04-05',
        'reports_to_id' => $manager->id,
        'indirect_reports_to_id' => $dotted->id,
        'has_team_authority' => true,
        'remote_access' => true,
        'remote_access_from' => '2026-02-01',
        'remote_access_until' => '2026-03-01',
        'phone' => '0300 1234567',
        'secondary_phone' => '+92 321 7654321',
        'landline' => '042 35761234',
        'permanent_address' => 'House 1, Street 2, Lahore',
        'present_address' => 'Flat 3, Block B, Lahore',
        'emergency_contact_name' => 'Imran Khan',
        'emergency_contact_phone' => '0333 1112223',
        'emergency_contact_relation' => 'father',
        'passport_no' => 'AB1234567',
        'passport_expiry' => '2031-01-01',
        'driving_license_no' => 'LHR-12345',
        'driving_license_expiry' => '2029-01-01',
    ])->assertCreated()->json('data.id');

    $data = $this->getJson("/api/v1/employees/{$id}/details")->assertOk()->json('data');

    expect($data)->toMatchArray([
        'salutation' => 'ms',
        'name' => 'Ayesha Noor Khan',
        'cnic_masked' => '35202-•••••••-2',
        'personal_email' => 'ayesha@gmail.com',
        'country' => ['id' => 167, 'name' => 'Pakistan', 'iso2' => 'PK', 'phone_code' => '92'],
        'state' => ['id' => 3176, 'name' => 'Punjab'],
        'city' => ['id' => 85485, 'name' => 'Lahore'],
        'nationality' => ['id' => 167, 'name' => 'Pakistani'],
        'blood_group' => 'B+',
        'duty_type' => 'contract',
        'probation_end_date' => '2026-04-04',
        'reports_to_id' => $manager->id,
        'indirect_reports_to_id' => $dotted->id,
        'has_team_authority' => true,
        'remote_access' => true,
        'permanent_remote_access' => false,
        'remote_access_until' => '2026-03-01',
        // Stored in E.164, whatever format was typed.
        'phone' => '+923001234567',
        'secondary_phone' => '+923217654321',
        'landline' => '+924235761234',
        'emergency_contact_phone' => '+923331112223',
        'emergency_contact_relation' => 'father',
        'passport_no_masked' => '••••••567',
        'driving_license_no_masked' => '••••••345',
    ])
        ->and($data['avatar_url'])->toContain('avatars/'.$id.'/')
        ->and($data['missing_fields'])->toBe([]);

    $this->getJson("/api/v1/employees/{$id}/sensitive")
        ->assertJsonPath('data.passport_no', 'AB1234567')
        ->assertJsonPath('data.driving_license_no', 'LHR-12345');
});

it('validates phone numbers against the selected country', function () {
    $this->postJson('/api/v1/employees/drafts', ['country_id' => 167, 'phone' => '+1 213 555 0100'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', 'The primary contact number is not a valid Pakistan phone number.');

    $this->postJson('/api/v1/employees/drafts', ['country_id' => 233, 'phone' => '(213) 555-0100'])->assertCreated()
        ->assertJsonPath('data.phone', '+12135550100');

    // No country yet: international format only.
    $this->postJson('/api/v1/employees/drafts', ['secondary_phone' => '0300 1234567'])
        ->assertStatus(422)->assertJsonValidationErrors(['secondary_phone']);
});

it('checks that state and city belong to the chosen country and state', function () {
    $this->postJson('/api/v1/employees/drafts', ['country_id' => 233, 'state_id' => 3176])
        ->assertStatus(422)->assertJsonValidationErrors(['state_id']);
    $this->postJson('/api/v1/employees/drafts', ['country_id' => 167, 'state_id' => 3176, 'city_id' => 85410])
        ->assertStatus(422)->assertJsonValidationErrors(['city_id']);

    // A partial update is checked against the stored country.
    $id = $this->postJson('/api/v1/employees/drafts', ['country_id' => 167])->json('data.id');
    $this->patchJson("/api/v1/employees/{$id}", ['state_id' => 1416])->assertStatus(422)->assertJsonValidationErrors(['state_id']);
});

it('requires remote access dates only while remote access is on', function () {
    $employee = hire();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['remote_access' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['remote_access_from', 'remote_access_until']);
    expect($employee->fresh()->remote_access)->toBeFalse();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['remote_access' => true, 'permanent_remote_access' => true, 'remote_access_from' => '2026-02-01', 'remote_access_until' => '2026-03-01'])
        ->assertOk()
        ->assertJsonPath('data.remote_access_until', null);

    $this->patchJson("/api/v1/employees/{$employee->id}", ['remote_access' => false])
        ->assertOk()
        ->assertJsonPath('data.permanent_remote_access', false)
        ->assertJsonPath('data.remote_access_from', null);

    $this->patchJson("/api/v1/employees/{$employee->id}", ['remote_access' => true, 'remote_access_from' => '2026-03-01', 'remote_access_until' => '2026-02-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['remote_access_until']);
});

it('keeps date fields in order', function () {
    $this->postJson('/api/v1/employees/drafts', ['join_date' => '2026-01-05', 'confirmation_date' => '2025-12-01', 'separation_date' => '2025-12-01'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirmation_date', 'separation_date']);
});

it('only lets people with team authority have a team', function () {
    $plain = Employee::factory()->create();
    $lead = Employee::factory()->teamLead()->create();

    $this->postJson('/api/v1/employees/drafts', ['reports_to_id' => $plain->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.reports_to_id.0', 'The selected person does not have team authority.');
    $this->postJson('/api/v1/employees/drafts', ['reports_to_id' => $lead->id, 'indirect_reports_to_id' => $lead->id])
        ->assertStatus(422)->assertJsonValidationErrors(['indirect_reports_to_id']);

    hire(['reports_to_id' => $lead->id]);
    $this->patchJson("/api/v1/employees/{$lead->id}", ['has_team_authority' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['has_team_authority']);
});

it('keeps a real employee complete: clearing a required field saves nothing', function () {
    $employee = hire();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['cnic' => null, 'middle_name' => 'X'])
        ->assertStatus(422)->assertJsonValidationErrors(['cnic']);

    expect($employee->fresh()->cnic)->toBe('35202-1234567-2')->and($employee->fresh()->middle_name)->toBeNull();
});

it('mirrors name and email changes to the login account', function () {
    $employee = hire();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['middle_name' => 'Noor', 'email' => 'ayesha.noor@company.pk'])->assertOk();

    expect(User::findOrFail($employee->user_id)->only(['name', 'email']))
        ->toBe(['name' => 'Ayesha Noor Khan', 'email' => 'ayesha.noor@company.pk']);
});

it('syncs several spouses, each with children, by id', function () {
    $id = $this->postJson('/api/v1/employees/drafts', ['spouses' => [
        ['full_name' => 'Sara', 'cnic' => '35202-1111111-2', 'birth_date' => '1996-01-01', 'children' => [
            ['full_name' => 'Ali', 'birth_date' => '2020-05-05'],
            ['full_name' => 'Zara', 'birth_date' => '2022-06-06'],
        ]],
        ['full_name' => 'Hina', 'children' => []],
    ]])->assertCreated()->json('data.id');

    $spouses = $this->getJson("/api/v1/employees/{$id}/details")->json('data.spouses');
    expect($spouses)->toHaveCount(2)
        ->and($spouses[0]['cnic_masked'])->toBe('35202-•••••••-2')
        ->and(array_column($spouses[0]['children'], 'full_name'))->toBe(['Ali', 'Zara']);

    $ali = $spouses[0]['children'][0];

    // Keep Sara (no cnic sent, so it stays), rename Ali, drop Zara and Hina, add a child.
    $this->patchJson("/api/v1/employees/{$id}", ['spouses' => [
        ['id' => $spouses[0]['id'], 'full_name' => 'Sara B', 'children' => [
            ['id' => $ali['id'], 'full_name' => 'Ali Khan'],
            ['full_name' => 'Musa', 'birth_date' => '2025-01-01'],
        ]],
    ]])->assertOk()
        ->assertJsonCount(1, 'data.spouses')
        ->assertJsonPath('data.spouses.0.id', $spouses[0]['id'])
        ->assertJsonPath('data.spouses.0.cnic_masked', '35202-•••••••-2')
        ->assertJsonPath('data.spouses.0.children.0.id', $ali['id'])
        ->assertJsonPath('data.spouses.0.children.*.full_name', ['Ali Khan', 'Musa']);

    $this->getJson("/api/v1/employees/{$id}/sensitive")->assertJsonPath('data.spouses.0.cnic', '35202-1111111-2');

    // Ids from another employee are rejected.
    $other = Employee::factory()->draft()->create();
    $this->patchJson("/api/v1/employees/{$other->id}", ['spouses' => [['id' => $spouses[0]['id'], 'full_name' => 'X']]])
        ->assertStatus(422)->assertJsonValidationErrors(['spouses.0.id']);
});

it('saves qualifications and requires institution and degree before submit', function () {
    $mba = DegreeProgram::where('name', 'MBA')->firstOrFail();
    $draft = Employee::factory()->draft()->create($this->core);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['qualifications' => [
        ['institution' => 'LUMS', 'degree_program_id' => $mba->id, 'start_date' => '2016-09-01', 'end_date' => '2018-06-30', 'year' => 2018],
        ['institution' => null],
    ]])->assertOk()
        ->assertJsonPath('data.qualifications.0.degree_program', ['id' => $mba->id, 'name' => 'MBA'])
        ->assertJsonPath('data.missing_fields', fn ($m) => isset($m['qualifications.1.institution'], $m['qualifications.1.degree_program_id']));

    $this->postJson("/api/v1/employees/{$draft->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['qualifications.1.institution', 'qualifications.1.degree_program_id']);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['qualifications' => [['institution' => 'X', 'start_date' => '2020-01-01', 'end_date' => '2019-01-01']]])
        ->assertStatus(422)->assertJsonValidationErrors(['qualifications.0.end_date']);
});

it('handles work experience behind its toggle, with the letter as a private file', function () {
    $draft = Employee::factory()->draft()->create($this->core);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['work_experiences' => [experience()]])
        ->assertStatus(422)->assertJsonValidationErrors(['work_experiences']);

    $response = $this->patchJson("/api/v1/employees/{$draft->id}", [
        'has_work_experience' => true,
        'work_experiences' => [experience(), ['company_name' => 'Half done']],
    ])->assertOk();

    $first = $response->json('data.work_experiences.0');
    expect($first['contact_person_phone'])->toBe('+923007654321')
        ->and($first['last_salary'])->toBe('150000.00')
        ->and($first['experience_letter']['name'])->toBe('letter.pdf')
        ->and($response->json('data.missing_fields'))->toHaveKeys(['work_experiences.1.designation', 'work_experiences.1.experience_letter']);

    $this->get($first['experience_letter']['download_url'])->assertOk()->assertDownload('letter.pdf');

    // Turning the toggle off removes the experiences and their letters.
    $letterPath = $draft->workExperiences()->first()->experience_letter_path;
    $this->patchJson("/api/v1/employees/{$draft->id}", ['has_work_experience' => false])->assertOk()->assertJsonCount(0, 'data.work_experiences');
    Storage::disk('local')->assertMissing($letterPath);

    // On but empty blocks submit.
    $this->patchJson("/api/v1/employees/{$draft->id}", ['has_work_experience' => true])->assertOk();
    $this->postJson("/api/v1/employees/{$draft->id}/submit")->assertStatus(422)->assertJsonValidationErrors(['work_experiences']);
});

it('validates each experience place and contact number against its own country', function () {
    $draft = Employee::factory()->draft()->create(['has_work_experience' => true]);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['work_experiences' => [
        experience(['country_id' => 233, 'state_id' => 1416, 'city_id' => 113277, 'contact_person_phone' => '0300 7654321']),
        experience(['state_id' => 1416]),
    ]])->assertStatus(422)
        ->assertJsonValidationErrors(['work_experiences.0.contact_person_phone', 'work_experiences.1.state_id']);
});

it('adds, renames, replaces and removes documents, cleaning up files', function () {
    $draft = Employee::factory()->draft()->create();

    $docs = $this->patchJson("/api/v1/employees/{$draft->id}", ['documents' => [
        ['title' => 'CNIC copy', 'upload_id' => uploadFile('document')],
        ['upload_id' => uploadFile('document')],
    ]])->assertOk()->json('data.documents');
    expect($docs)->toHaveCount(2)->and(Upload::count())->toBe(0);

    $this->get($docs[0]['download_url'])->assertOk();
    $oldPath = $draft->documents()->find($docs[0]['id'])->path;
    $removedPath = $draft->documents()->find($docs[1]['id'])->path;

    $this->patchJson("/api/v1/employees/{$draft->id}", ['documents' => [
        ['id' => $docs[0]['id'], 'title' => 'CNIC (both sides)', 'upload_id' => uploadFile('document')],
    ]])->assertOk()
        ->assertJsonCount(1, 'data.documents')
        ->assertJsonPath('data.documents.0.title', 'CNIC (both sides)');

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertMissing($removedPath);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['documents' => [['title' => 'No file']]])
        ->assertStatus(422)->assertJsonValidationErrors(['documents.0.upload_id']);
});

it("only attaches the current user's uploads, of the right kind", function () {
    $draft = Employee::factory()->draft()->create();
    $mine = uploadFile('document');

    $this->patchJson("/api/v1/employees/{$draft->id}", ['avatar_upload_id' => $mine])
        ->assertStatus(422)->assertJsonValidationErrors(['avatar_upload_id']);

    actingAsUser();
    $this->patchJson("/api/v1/employees/{$draft->id}", ['documents' => [['upload_id' => $mine]]])
        ->assertStatus(422)->assertJsonValidationErrors(['documents.0.upload_id']);
});

it('replaces and removes the profile picture', function () {
    $draft = Employee::factory()->draft()->create();

    $this->patchJson("/api/v1/employees/{$draft->id}", ['avatar_upload_id' => uploadFile('avatar')])->assertOk();
    $first = $draft->fresh()->avatar_path;

    $this->patchJson("/api/v1/employees/{$draft->id}", ['avatar_upload_id' => uploadFile('avatar')])->assertOk();
    Storage::disk('public')->assertMissing($first);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['avatar_upload_id' => null])->assertOk()->assertJsonPath('data.avatar_url', null);
});

it('offers every fixed dropdown in one call', function () {
    $this->getJson('/api/v1/employees/form-options')
        ->assertOk()
        ->assertJsonPath('data.blood_groups.0', ['value' => 'A+', 'label' => 'A+'])
        ->assertJsonPath('data.duty_types.0', ['value' => 'full_time', 'label' => 'Full-time'])
        ->assertJsonPath('data.probation_durations.0.months', 1)
        ->assertJsonStructure(['data' => [
            'salutations', 'genders', 'marital_statuses', 'religions', 'employment_statuses', 'work_modes',
            'emergency_contact_relations', 'degree_programs', 'departments', 'designations', 'divisions', 'campaigns',
            'shifts', 'locations',
        ]]);
});
