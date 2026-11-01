<?php

use App\Mail\EmployeeInvitation;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    actingAsUser();
    Mail::fake();

    $designation = Designation::query()->whereHas('department', fn ($q) => $q->where('name', 'People'))->first();

    $this->payload = [
        'first_name' => 'Fatima',
        'last_name' => 'Javed',
        'email' => 'Fatima.Javed@company.pk',
        'phone' => '+92 300 1234567',
        'cnic' => '35202-1234567-1',
        'gender' => 'female',
        'designation_id' => $designation->id,
        'department_id' => $designation->department_id,
        'work_mode' => 'hybrid',
        'shift_id' => Shift::first()->id,
        'location_id' => Location::first()->id,
        'join_date' => '2024-03-12',
    ];
});

it('creates the employee and user in one go and returns 201 profile', function () {
    $response = $this->postJson('/api/v1/employees', $this->payload);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Fatima Javed')
        ->assertJsonPath('data.email', 'fatima.javed@company.pk')
        ->assertJsonPath('data.work_mode', 'hybrid')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.join_date', '2024-03-12')
        ->assertJsonStructure(['data' => ['reports_to', 'direct_reports', 'employment', 'attendance_rate', 'leave_balances']]);

    $employee = Employee::findOrFail($response->json('data.id'));
    expect($employee->code)->toBe(sprintf('EMP-%04d', $employee->id))
        ->and($response->json('data.code'))->toBe($employee->code)
        ->and($employee->cnic)->toBe('35202-1234567-1')
        ->and($employee->user)->not->toBeNull()
        ->and($employee->user->email)->toBe('fatima.javed@company.pk');

    Mail::assertQueued(EmployeeInvitation::class, fn (EmployeeInvitation $mail) => $mail->hasTo('fatima.javed@company.pk')
        && $mail->employeeId === $employee->id);
});

it('ignores salary and bank fields, which belong to payroll', function () {
    $id = $this->postJson('/api/v1/employees', $this->payload + [
        'gross_salary' => 0, 'bank_name' => 'Meezan Bank', 'iban' => 'NOT-AN-IBAN',
    ])->assertCreated()->json('data.id');

    expect(Employee::find($id))->not->toBeNull();
});

it('rolls everything back when a step fails halfway', function () {
    Employee::created(fn () => throw new RuntimeException('Simulated failure'));

    $this->postJson('/api/v1/employees', $this->payload)->assertStatus(500)->assertJsonStructure(['message']);

    expect(DB::table('employees')->count())->toBe(0)
        ->and(User::where('email', 'fatima.javed@company.pk')->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('requires the mandatory fields', function () {
    $this->postJson('/api/v1/employees', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'designation_id', 'department_id', 'join_date'])
        ->assertJsonMissingValidationErrors(['gross_salary']);
});

it('validates field formats', function (array $override, string $field) {
    $this->postJson('/api/v1/employees', array_merge($this->payload, $override))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'cnic format' => [['cnic' => '3520212345671'], 'cnic'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'bad gender' => [['gender' => 'robot'], 'gender'],
    'bad work mode' => [['work_mode' => 'space'], 'work_mode'],
    'bad date' => [['join_date' => '12/03/2024'], 'join_date'],
    'unknown designation' => [['designation_id' => 999999], 'designation_id'],
    'unknown manager' => [['reports_to_id' => 999999], 'reports_to_id'],
]);

it('rejects a duplicate email (also against soft-deleted employees and users)', function () {
    Employee::factory()->create(['email' => 'fatima.javed@company.pk'])->delete();

    $this->postJson('/api/v1/employees', $this->payload)->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects an email already used by a login account', function () {
    User::factory()->create(['email' => 'fatima.javed@company.pk']);

    $this->postJson('/api/v1/employees', $this->payload)->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects a duplicate CNIC via the blind index', function () {
    Employee::factory()->create(['cnic' => '35202-1234567-1']);

    $this->postJson('/api/v1/employees', $this->payload)->assertStatus(422)->assertJsonValidationErrors(['cnic']);
});

it('accepts a department and designation from the lookup tables only', function () {
    $this->payload['department_id'] = Department::max('id') + 1;

    $this->postJson('/api/v1/employees', $this->payload)->assertStatus(422)->assertJsonValidationErrors(['department_id']);
});
