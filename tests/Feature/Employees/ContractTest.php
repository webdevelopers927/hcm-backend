<?php

use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->actor = actingAsUser();
    $this->travelTo('2026-11-03 10:00:00');
});

/** A contractual employee (Contract duty type) with a login whose password is "secret-pass". */
function contractor(string $status = 'Active', string $dutyType = 'Contract'): Employee
{
    $employee = Employee::factory()->inStatus($status)->withAccount()->create(['duty_type_id' => dutyTypeId($dutyType)]);
    $employee->user->forceFill(['password' => Hash::make('secret-pass')])->save();

    return $employee->fresh();
}

function newContract(Employee $employee, string $start, string $end, array $extra = [])
{
    return test()->postJson('/api/v1/contracts', ['employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end, ...$extra]);
}

function signIn(Employee $employee)
{
    auth()->forgetGuards();

    return test()->postJson('/api/v1/auth/login', ['email' => $employee->email, 'password' => 'secret-pass']);
}

it('offers only contractual employees in the picker', function () {
    $contractor = contractor(dutyType: 'Internship');
    Employee::factory()->create(['duty_type_id' => dutyTypeId('Full-time')]);
    Employee::factory()->draft()->create(['duty_type_id' => dutyTypeId('Contract')]);
    newContract($contractor, '2026-01-01', '2026-12-31')->assertCreated();

    $this->getJson('/api/v1/contracts/eligible-employees')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $contractor->id)
        ->assertJsonPath('data.0.duty_type.name', 'Internship')
        ->assertJsonPath('data.0.has_current_contract', true);
});

it('creates a contract with status, days left and progress', function () {
    $employee = contractor();

    newContract($employee, '2026-01-01', '2027-06-30', ['leave_entitled' => false, 'notes' => 'Project Atlas'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.days_left', 239)
        ->assertJsonPath('data.progress', 56)
        ->assertJsonPath('data.renewable', false)
        ->assertJsonPath('data.leave_entitled', false)
        ->assertJsonPath('data.employee.duty_type.name', 'Contract')
        ->assertJsonPath('data.created_by.id', $this->actor->id);
});

it('validates a new contract', function () {
    $permanent = Employee::factory()->create(['duty_type_id' => dutyTypeId('Full-time')]);
    $employee = contractor();
    newContract($employee, '2026-01-01', '2026-12-31')->assertCreated();

    newContract($permanent, '2026-01-01', '2026-12-31')
        ->assertStatus(422)
        ->assertJsonPath('errors.employee_id.0', 'Contracts are only for employees with a contractual duty type.');
    newContract($employee, '2026-12-01', '2027-03-01')
        ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    newContract($employee, '2027-03-01', '2027-02-01')->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    $this->postJson('/api/v1/contracts', [])->assertStatus(422)->assertJsonValidationErrors(['employee_id', 'start_date', 'end_date']);
});

it('lists contracts with tab counts, the expiring banner and filters', function () {
    $a = contractor();
    $b = contractor();
    $c = contractor();
    $d = contractor();
    $b->forceFill(['first_name' => 'Bilal', 'last_name' => 'Hussain'])->save();
    newContract($a, '2026-01-01', '2027-06-30')->assertCreated();          // active
    newContract($b, '2026-02-01', '2026-11-13')->assertCreated();          // expiring (10 days)
    newContract($c, '2026-01-01', '2026-10-01')->assertCreated();          // expired
    newContract($d, '2026-12-01', '2027-11-30')->assertCreated();          // upcoming

    $this->getJson('/api/v1/contracts')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('summary.all', 4)
        ->assertJsonPath('summary.active', 1)
        ->assertJsonPath('summary.expiring', 1)
        ->assertJsonPath('summary.expired', 1)
        ->assertJsonPath('summary.upcoming', 1)
        ->assertJsonPath('summary.expiring_this_month', ['count' => 1, 'names' => ['Bilal Hussain']]);

    $this->getJson('/api/v1/contracts?status=expiring')->assertJsonPath('data.0.employee.id', $b->id)->assertJsonPath('data.0.days_left', 10);
    $this->getJson('/api/v1/contracts?status=expired')->assertJsonPath('data.0.days_left', -33);
    $this->getJson('/api/v1/contracts?search=bilal')->assertJsonPath('meta.total', 1);
});

it('locks the employee out once the contract has ended, and only then', function () {
    $employee = contractor('Probation');
    $employee->user->createToken('phone');
    newContract($employee, '2026-01-01', '2026-11-30')->assertCreated();

    $this->travelTo('2026-11-30 23:00:00');
    $this->artisan('contracts:sync')->expectsOutput('Contracts synced: 0 expired, 0 restored.');
    signIn($employee)->assertOk();

    $this->travelTo('2026-12-01 00:15:00');
    $this->artisan('contracts:sync')->expectsOutput('Contracts synced: 1 expired, 0 restored.');

    $employee->refresh();
    expect($employee->employment_status_id)->toBe(statusId('Contract expired'))
        ->and(User::find($employee->user_id)->tokens()->count())->toBe(0);
    signIn($employee)->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Your employment status does not allow signing in. Please contact HR.');

    $this->artisan('contracts:sync')->expectsOutput('Contracts synced: 0 expired, 0 restored.');
});

it('expires at once when a contract is entered with an end date already passed', function () {
    $employee = contractor();

    newContract($employee, '2026-01-01', '2026-10-31')->assertCreated()->assertJsonPath('data.status', 'expired');

    expect($employee->fresh()->employment_status_id)->toBe(statusId('Contract expired'));
});

it('renews an expired contract and restores the previous status', function () {
    $employee = contractor('Probation');
    $id = newContract($employee, '2026-01-01', '2026-10-31')->json('data.id');
    expect($employee->fresh()->employment_status_id)->toBe(statusId('Contract expired'));

    $this->postJson("/api/v1/contracts/{$id}/renew", ['end_date' => '2027-10-31'])
        ->assertCreated()
        ->assertJsonPath('data.start_date', '2026-11-01')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.renewed_from_id', $id);

    expect($employee->fresh()->employment_status_id)->toBe(statusId('Probation'))
        ->and(Contract::find($id)->renewed_at)->not->toBeNull();

    // The main list shows the current contract only; the employee's history has both.
    $this->getJson('/api/v1/contracts')->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/contracts?employee_id={$employee->id}")->assertJsonPath('meta.total', 2);

    signIn($employee)->assertOk();
});

it('renews early only when the contract is about to expire', function () {
    $farOff = contractor();
    $farId = newContract($farOff, '2026-01-01', '2027-06-30')->json('data.id');
    $this->postJson("/api/v1/contracts/{$farId}/renew", ['end_date' => '2028-06-30'])
        ->assertStatus(422)->assertJsonValidationErrors(['contract']);

    $soon = contractor();
    $soonId = newContract($soon, '2026-01-01', '2026-11-20')->json('data.id');
    $this->getJson("/api/v1/contracts/{$soonId}")->assertJsonPath('data.renewable', true)->assertJsonPath('data.status', 'expiring');
    $this->postJson("/api/v1/contracts/{$soonId}/renew", ['start_date' => '2026-11-15', 'end_date' => '2027-11-20'])
        ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    $this->postJson("/api/v1/contracts/{$soonId}/renew", ['end_date' => '2027-11-20'])
        ->assertCreated()->assertJsonPath('data.status', 'upcoming');

    // Renewed in time: no lockout when the old one ends.
    $this->travelTo('2026-11-21 00:15:00');
    $this->artisan('contracts:sync');
    expect($soon->fresh()->employment_status_id)->toBe(statusId('Active'));

    $this->postJson("/api/v1/contracts/{$soonId}/renew", ['end_date' => '2028-01-01'])->assertStatus(422);
});

it('restores access when an expired contract is extended by editing it', function () {
    $employee = contractor();
    $id = newContract($employee, '2026-01-01', '2026-10-31')->json('data.id');

    $this->patchJson("/api/v1/contracts/{$id}", ['end_date' => '2027-01-31'])->assertOk()->assertJsonPath('data.status', 'active');

    expect($employee->fresh()->employment_status_id)->toBe(statusId('Active'));
    $this->patchJson("/api/v1/contracts/{$id}", ['end_date' => '2025-01-01'])->assertStatus(422)->assertJsonValidationErrors(['end_date']);
});

it('deletes a contract; deleting a renewal makes the previous one current again', function () {
    $employee = contractor();
    $first = newContract($employee, '2026-01-01', '2026-11-20')->json('data.id');
    $renewal = $this->postJson("/api/v1/contracts/{$first}/renew", ['end_date' => '2027-11-20'])->json('data.id');

    $this->deleteJson("/api/v1/contracts/{$first}")->assertStatus(409);
    $this->patchJson("/api/v1/contracts/{$first}", ['notes' => 'x'])->assertStatus(409);
    $this->deleteJson("/api/v1/contracts/{$renewal}")->assertOk();

    expect(Contract::find($first)->renewed_at)->toBeNull();
    $this->deleteJson("/api/v1/contracts/{$first}")->assertOk();
    expect(Contract::count())->toBe(0);
});

it('leaves alone people who are no longer contractual, or who are leaving', function () {
    $nowPermanent = contractor();
    newContract($nowPermanent, '2026-01-01', '2026-12-31')->assertCreated();
    $nowPermanent->forceFill(['duty_type_id' => dutyTypeId('Full-time')])->save();

    $onNotice = contractor('Notice period');
    $onNotice->forceFill(['resigned_date' => '2026-10-01', 'last_working_day' => '2027-01-31', 'reason_for_resignation' => 'x'])->save();
    newContract($onNotice, '2026-01-01', '2026-12-31')->assertCreated();

    $this->travelTo('2027-01-02 00:15:00');
    $this->artisan('contracts:sync')->expectsOutput('Contracts synced: 0 expired, 0 restored.');
});

it('protects the Contract expired status from being renamed or deleted', function () {
    $id = statusId('Contract expired');

    $this->getJson('/api/v1/employment-statuses')
        ->assertJsonPath('data', fn ($rows) => collect($rows)->firstWhere('id', $id)['is_system'] === true);
    $this->patchJson("/api/v1/employment-statuses/{$id}", ['name' => 'Lapsed'])->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->deleteJson("/api/v1/employment-statuses/{$id}")->assertStatus(409);
});
