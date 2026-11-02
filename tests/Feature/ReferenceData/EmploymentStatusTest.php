<?php

use App\Mail\EmployeeInvitation;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->admin = actingAsUser());

/** A real employee with a login (password "secret-pass") in the given status. */
function employeeWithLogin(string $status): Employee
{
    $employee = Employee::factory()->inStatus($status)->withAccount()->create();
    $employee->user->forceFill(['password' => Hash::make('secret-pass')])->save();

    return $employee->fresh();
}

it('lists the seeded statuses in order with their flags and headcount', function () {
    Employee::factory()->count(2)->inStatus('Notice period')->create();
    Employee::factory()->draft()->create(['employment_status_id' => statusId('Notice period')]);

    $response = $this->getJson('/api/v1/employment-statuses')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(array_keys(LookupSeeder::EMPLOYMENT_STATUSES));
    expect(collect($response->json('data'))->firstWhere('name', 'Notice period'))->toMatchArray([
        'can_login' => true, 'is_notice_period' => true, 'employees_count' => 2,
    ]);
    expect(collect($response->json('data'))->firstWhere('name', 'Terminated'))->toMatchArray(['can_login' => false, 'is_notice_period' => false]);
});

it('creates, shows and updates a status', function () {
    $id = $this->postJson('/api/v1/employment-statuses', ['name' => '  Garden leave ', 'can_login' => false, 'is_notice_period' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Garden leave')
        ->assertJsonPath('data.sort_order', count(LookupSeeder::EMPLOYMENT_STATUSES))
        ->json('data.id');

    $this->getJson("/api/v1/employment-statuses/{$id}")->assertOk()->assertJsonPath('data.is_notice_period', true);
    $this->patchJson("/api/v1/employment-statuses/{$id}", ['name' => 'Gardening leave', 'sort_order' => 2])
        ->assertOk()
        ->assertJsonPath('data', fn ($d) => $d['name'] === 'Gardening leave' && $d['sort_order'] === 2 && $d['can_login'] === false);
});

it('validates a status', function () {
    $this->postJson('/api/v1/employment-statuses', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'can_login', 'is_notice_period']);
    $this->postJson('/api/v1/employment-statuses', ['name' => 'active', 'can_login' => true, 'is_notice_period' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/employment-statuses', ['name' => 'X', 'can_login' => 'perhaps', 'is_notice_period' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['can_login']);
});

it('refuses to delete a status that is in use, and deletes an unused one', function () {
    Employee::factory()->inStatus('Retired')->create()->delete(); // even soft-deleted employees keep it

    $this->deleteJson('/api/v1/employment-statuses/'.statusId('Retired'))->assertStatus(409);

    $unused = EmploymentStatus::create(['name' => 'Sabbatical', 'can_login' => true, 'is_notice_period' => false]);
    $this->deleteJson("/api/v1/employment-statuses/{$unused->id}")->assertOk();
    expect(EmploymentStatus::find($unused->id))->toBeNull();
});

it('blocks sign-in for a status that may not log in', function () {
    $allowed = employeeWithLogin('Notice period');
    $blocked = employeeWithLogin('Suspended');

    $this->postJson('/api/v1/auth/login', ['email' => $allowed->email, 'password' => 'secret-pass'])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => $blocked->email, 'password' => 'secret-pass'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Your employment status does not allow signing in. Please contact HR.');
});

it('signs an employee out when they move to a status that may not log in', function () {
    $employee = employeeWithLogin('Active');
    $employee->user->createToken('phone');

    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('Probation')])->assertOk();
    expect($employee->user->tokens()->count())->toBe(1);

    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('Terminated')])
        ->assertOk()
        ->assertJsonPath('data.employment_status.name', 'Terminated');
    expect($employee->user->tokens()->count())->toBe(0);
});

it('signs everyone in a status out when its login is switched off', function () {
    $inStatus = employeeWithLogin('On leave');
    $other = employeeWithLogin('Active');
    $inStatus->user->createToken('a');
    $other->user->createToken('b');

    $this->patchJson('/api/v1/employment-statuses/'.statusId('On leave'), ['can_login' => false])->assertOk();

    expect($inStatus->user->tokens()->count())->toBe(0)
        ->and($other->user->tokens()->count())->toBe(1)
        ->and(User::find($this->admin->id)->tokens()->count())->toBe(0);
});

it('skips the invite email for a hire whose status may not log in', function () {
    Mail::fake();
    $designation = Designation::whereNotNull('department_id')->firstOrFail();

    $this->postJson('/api/v1/employees', [
        'first_name' => 'No', 'last_name' => 'Login', 'email' => 'no.login@company.pk', 'cnic' => '35202-4444444-4',
        'department_id' => $designation->department_id, 'designation_id' => $designation->id,
        'employment_status_id' => statusId('Inactive'), 'duty_type' => 'full_time', 'join_date' => '2026-10-01',
    ])->assertCreated();

    Mail::assertNotQueued(EmployeeInvitation::class);
});

it('requires an existing status on the employee form', function () {
    $draft = Employee::factory()->draft()->create();

    $this->patchJson("/api/v1/employees/{$draft->id}", ['employment_status_id' => 999999])
        ->assertStatus(422)->assertJsonValidationErrors(['employment_status_id']);
});
