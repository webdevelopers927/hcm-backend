<?php

use App\Mail\EmployeeCredentials;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actor = actingAsUser();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');

    $designation = Designation::query()->whereHas('department', fn ($q) => $q->where('name', 'Engineering'))->firstOrFail();
    $this->core = [
        'first_name' => 'Hamza',
        'last_name' => 'Iqbal',
        'email' => 'hamza.iqbal@company.pk',
        'cnic' => '35202-7777777-7',
        'department_id' => $designation->department_id,
        'designation_id' => $designation->id,
        'employment_status_id' => statusId('Probation'),
        'duty_type_id' => dutyTypeId('Internship'),
        'join_date' => '2026-11-10',
    ];
});

it('saves an empty draft: no code, no login, no invite', function () {
    $response = $this->postJson('/api/v1/employees/drafts', [])->assertCreated();

    $draft = Employee::findOrFail($response->json('data.id'));
    expect($draft->is_draft)->toBeTrue()
        ->and($draft->code)->toBeNull()
        ->and($draft->user_id)->toBeNull()
        ->and($draft->created_by_id)->toBe($this->actor->id);

    $response->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.code', null)
        ->assertJsonPath('data.created_by.id', $this->actor->id)
        ->assertJsonStructure(['data' => ['missing_fields' => ['first_name', 'cnic', 'duty_type_id', 'join_date']]]);
    Mail::assertNothingSent();
});

it('keeps drafts out of the employee list, counts, export, profile and manager pickers', function () {
    Employee::factory()->create();
    $draft = Employee::factory()->draft()->create(['first_name' => 'Half', 'has_team_authority' => true]);

    $this->getJson('/api/v1/employees')->assertJsonPath('meta.total', 1)->assertJsonPath('status_counts.all', 1);
    $this->getJson("/api/v1/employees/{$draft->id}")->assertNotFound();
    expect($this->postJson('/api/v1/employees/export', ['format' => 'csv'])->streamedContent())->not->toContain('Half');

    $this->postJson('/api/v1/employees', $this->core + ['reports_to_id' => $draft->id])
        ->assertStatus(422)->assertJsonValidationErrors(['reports_to_id']);
});

it('lists drafts newest first and searches them', function () {
    Employee::factory()->create();
    $old = Employee::factory()->draft()->create(['first_name' => 'Old', 'updated_at' => now()->subDay()]);
    $new = Employee::factory()->draft()->create(['first_name' => 'Newer', 'email' => null]);

    $this->getJson('/api/v1/employees/drafts')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$new->id, $old->id])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.email', null);

    $this->getJson('/api/v1/employees/drafts?search=old')->assertJsonPath('data.*.id', [$old->id]);
});

it('saves a draft bit by bit, still checking the format of what is sent', function () {
    $id = $this->postJson('/api/v1/employees/drafts', ['first_name' => 'Hamza'])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/employees/{$id}", ['last_name' => 'Iqbal', 'cnic' => '35202-7777777-7'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Hamza')
        ->assertJsonPath('data.last_name', 'Iqbal')
        ->assertJsonPath('data.cnic_masked', '35202-•••••••-7');

    $this->patchJson("/api/v1/employees/{$id}", ['cnic' => '123'])->assertStatus(422)->assertJsonValidationErrors(['cnic']);
    $this->patchJson("/api/v1/employees/{$id}", ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);

    $this->getJson("/api/v1/employees/{$id}/details")
        ->assertOk()
        ->assertJsonPath('data.missing_fields', fn ($m) => ! isset($m['first_name']) && ! isset($m['cnic']) && isset($m['email']));
});

it('refuses to submit an incomplete draft and lists what is missing', function () {
    $draft = Employee::factory()->draft()->create(['first_name' => 'Hamza']);

    $this->postJson("/api/v1/employees/{$draft->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cnic', 'employment_status_id', 'duty_type_id', 'join_date'])
        ->assertJsonPath('errors.duty_type_id.0', 'The duty type field is required.');

    expect($draft->fresh()->is_draft)->toBeTrue()->and(User::count())->toBe(1);
    Mail::assertNothingSent();
});

it('submits a complete draft: code, login account, invite and audit entry', function () {
    $id = $this->postJson('/api/v1/employees/drafts', $this->core)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/employees/{$id}/submit")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.code', sprintf('EMP-%04d', $id))
        ->assertJsonPath('data.employment_status.name', 'Probation');

    $employee = Employee::findOrFail($id);
    expect($employee->is_draft)->toBeFalse()
        ->and($employee->user->email)->toBe('hamza.iqbal@company.pk');
    Mail::assertSent(EmployeeCredentials::class, fn (EmployeeCredentials $m) => $m->hasTo('hamza.iqbal@company.pk'));
    expect(Activity::where('event', 'draft_submitted')->sole()->subject_id)->toBe($id);

    $this->getJson('/api/v1/employees')->assertJsonPath('meta.total', 1);
    $this->postJson("/api/v1/employees/{$id}/submit")->assertStatus(409);
});

it('rejects a submit when a login account took the email in the meantime', function () {
    $draft = Employee::factory()->draft()->create(array_merge($this->core, ['cnic' => '35202-7777777-7']));
    User::factory()->create(['email' => 'hamza.iqbal@company.pk']);

    $this->postJson("/api/v1/employees/{$draft->id}/submit")->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($draft->fresh()->is_draft)->toBeTrue();
});

it('discards a draft for good, with its files', function () {
    $id = $this->postJson('/api/v1/employees/drafts', [
        'first_name' => 'Gone',
        'avatar_upload_id' => uploadFile('avatar'),
        'documents' => [['title' => 'CV', 'upload_id' => uploadFile('document')]],
    ])->assertCreated()->json('data.id');
    $draft = Employee::findOrFail($id);
    $docPath = $draft->documents()->sole()->path;

    $this->deleteJson("/api/v1/employees/{$id}")->assertOk()->assertJsonPath('message', 'Draft deleted.');

    expect(Employee::withTrashed()->find($id))->toBeNull();
    Storage::disk('public')->assertMissing($draft->avatar_path);
    Storage::disk('local')->assertMissing($docPath);
    expect(Activity::where('event', 'draft_deleted')->count())->toBe(1);
});

it('creates a complete employee in one call when everything required is sent', function () {
    $this->postJson('/api/v1/employees', $this->core)->assertCreated()->assertJsonPath('data.code', fn ($c) => str_starts_with($c, 'EMP-'));

    $this->postJson('/api/v1/employees', ['first_name' => 'Only'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['last_name', 'email', 'cnic']);
    expect(Employee::count())->toBe(1);
});
