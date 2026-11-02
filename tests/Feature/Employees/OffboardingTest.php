<?php

use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actor = actingAsUser();
    $this->travelTo('2026-11-03 10:00:00');
});

function startOffboarding(Employee $employee, array $override = [])
{
    return test()->postJson('/api/v1/offboardings', array_merge([
        'employee_id' => $employee->id,
        'exit_type' => 'resignation',
        'resigned_date' => '2026-11-01',
        'last_working_day' => '2026-11-30',
        'reason_for_resignation' => 'Moving abroad',
    ], $override));
}

/** Ticks every checklist item of a case through the API. */
function tickAll(int $caseId): void
{
    foreach (Offboarding::findOrFail($caseId)->checklist as $item) {
        test()->patchJson("/api/v1/offboardings/{$caseId}/checklist/{$item->id}", ['done' => true])->assertOk();
    }
}

it('requires the exit details when the employee form puts someone in a notice-period status', function () {
    $employee = Employee::factory()->inStatus('Active')->create();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('Notice period')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resigned_date', 'last_working_day', 'reason_for_resignation'])
        ->assertJsonPath('errors.reason_for_resignation.0', 'The reason for resignation field is required.');
    expect($employee->fresh()->employment_status_id)->toBe(statusId('Active'));

    // Not required for other statuses.
    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('On leave')])->assertOk();
});

it('opens a case automatically when the form moves someone into a notice period', function () {
    $employee = Employee::factory()->inStatus('Active')->create();

    $this->patchJson("/api/v1/employees/{$employee->id}", [
        'employment_status_id' => statusId('Notice period'),
        'resigned_date' => '2026-11-01',
        'last_working_day' => '2026-11-30',
        'reason_for_resignation' => 'Higher studies',
    ])->assertOk()
        ->assertJsonPath('data.last_working_day', '2026-11-30')
        ->assertJsonPath('data.offboarding.stage', 'notice_period')
        ->assertJsonPath('data.offboarding.exit_type', 'resignation');

    $case = Offboarding::sole();
    expect($case->previous_employment_status_id)->toBe(statusId('Active'))
        ->and($case->final_employment_status_id)->toBe(statusId('Resigned'))
        ->and($case->checklist()->count())->toBe(count(Offboarding::DEFAULT_CHECKLIST));

    // Saving again doesn't open a second case.
    $this->patchJson("/api/v1/employees/{$employee->id}", ['reason_for_resignation' => 'Higher studies abroad'])->assertOk();
    expect(Offboarding::count())->toBe(1);
});

it('keeps the exit dates in order', function () {
    $draft = Employee::factory()->draft()->create(['join_date' => '2024-01-01']);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['resigned_date' => '2026-11-10', 'last_working_day' => '2026-11-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['last_working_day']);
    $this->patchJson("/api/v1/employees/{$draft->id}", ['resigned_date' => '2023-01-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['resigned_date']);
});

it('lists the exit details as missing on a notice-period draft', function () {
    $draft = Employee::factory()->draft()->create(['employment_status_id' => statusId('Notice period')]);

    $this->getJson("/api/v1/employees/{$draft->id}/details")
        ->assertJsonPath('data.missing_fields', fn ($m) => isset($m['resigned_date'], $m['last_working_day'], $m['reason_for_resignation']));
});

it('starts offboarding: notice status, exit details, case and checklist', function () {
    $employee = Employee::factory()->inStatus('Active')->create();

    $response = startOffboarding($employee, ['exit_type' => 'termination'])->assertCreated();

    $response->assertJsonPath('data.stage', 'resigned')
        ->assertJsonPath('data.exit_type', 'termination')
        ->assertJsonPath('data.employee.employment_status.name', 'Notice period')
        ->assertJsonPath('data.last_working_day', '2026-11-30')
        ->assertJsonPath('data.days_left', 27)
        ->assertJsonPath('data.final_employment_status.name', 'Terminated')
        ->assertJsonPath('data.checklist_progress', ['done' => 0, 'total' => count(Offboarding::DEFAULT_CHECKLIST)])
        ->assertJsonCount(count(Offboarding::DEFAULT_CHECKLIST), 'data.checklist');

    expect($employee->fresh()->reason_for_resignation)->toBe('Moving abroad');
    expect(Activity::where('event', 'offboarding_started')->count())->toBe(1);

    startOffboarding($employee)->assertStatus(409);
});

it('validates starting offboarding', function (Closure $payload, array $errors) {
    $this->postJson('/api/v1/offboardings', $payload())->assertStatus(422)->assertJsonValidationErrors($errors);
})->with([
    'empty' => [fn () => [], ['employee_id', 'exit_type', 'resigned_date', 'last_working_day', 'reason_for_resignation']],
    'draft employee' => [fn () => ['employee_id' => Employee::factory()->draft()->create()->id], ['employee_id']],
    'dates backwards' => [fn () => ['employee_id' => Employee::factory()->create()->id, 'exit_type' => 'resignation', 'resigned_date' => '2026-11-10', 'last_working_day' => '2026-11-01', 'reason_for_resignation' => 'x'], ['last_working_day']],
    'not a notice status' => [fn () => ['employee_id' => Employee::factory()->create()->id, 'exit_type' => 'resignation', 'resigned_date' => '2026-11-01', 'last_working_day' => '2026-11-30', 'reason_for_resignation' => 'x', 'notice_employment_status_id' => statusId('Active')], ['notice_employment_status_id']],
    'bad exit type' => [fn () => ['exit_type' => 'vanished'], ['exit_type']],
]);

it('lists cases with board counts, search and filters', function () {
    $a = Employee::factory()->inStatus('Active')->create(['first_name' => 'Zara']);
    $b = Employee::factory()->inStatus('Active')->create();
    startOffboarding($a, ['last_working_day' => '2026-11-20'])->assertCreated();
    $caseB = startOffboarding($b, ['last_working_day' => '2027-02-01', 'exit_type' => 'retirement'])->json('data.id');
    $this->patchJson("/api/v1/offboardings/{$caseB}", ['stage' => 'clearance'])->assertOk();

    $this->getJson('/api/v1/offboardings')
        ->assertOk()
        ->assertJsonPath('data.0.employee.id', $a->id) // soonest last working day first
        ->assertJsonPath('data.0.checklist_progress.total', count(Offboarding::DEFAULT_CHECKLIST))
        ->assertJsonMissingPath('data.0.checklist')
        ->assertJsonPath('summary.in_progress', 2)
        ->assertJsonPath('summary.leaving_in_30_days', 1)
        ->assertJsonPath('summary.pending_clearance', 1)
        ->assertJsonPath('summary.by_stage.resigned', 1)
        ->assertJsonPath('summary.stages.0', ['value' => 'resigned', 'label' => 'Resigned']);

    $this->getJson('/api/v1/offboardings?search=zara')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/offboardings?stage=clearance')->assertJsonPath('data.0.id', $caseB);
    $this->getJson('/api/v1/offboardings?exit_type=retirement')->assertJsonPath('meta.total', 1);
});

it('ticks, unticks, adds and removes checklist items', function () {
    $id = startOffboarding(Employee::factory()->create())->json('data.id');
    $item = Offboarding::findOrFail($id)->checklist()->first();

    $this->patchJson("/api/v1/offboardings/{$id}/checklist/{$item->id}", ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.checklist.0.done', true)
        ->assertJsonPath('data.checklist.0.done_by.id', $this->actor->id)
        ->assertJsonPath('data.checklist_progress.done', 1);
    $this->patchJson("/api/v1/offboardings/{$id}/checklist/{$item->id}", ['done' => false])->assertJsonPath('data.checklist.0.done_by', null);

    $this->postJson("/api/v1/offboardings/{$id}/checklist", ['label' => 'Return parking pass', 'owner' => 'Admin'])
        ->assertCreated()
        ->assertJsonPath('data.checklist_progress.total', count(Offboarding::DEFAULT_CHECKLIST) + 1);
    $this->deleteJson("/api/v1/offboardings/{$id}/checklist/{$item->id}")
        ->assertOk()
        ->assertJsonPath('data.checklist_progress.total', count(Offboarding::DEFAULT_CHECKLIST));

    // An item of another case is not reachable through this one.
    $other = startOffboarding(Employee::factory()->create())->json('data.id');
    $foreign = Offboarding::findOrFail($other)->checklist()->first();
    $this->patchJson("/api/v1/offboardings/{$id}/checklist/{$foreign->id}", ['done' => true])->assertNotFound();
});

it('completes a case only when the checklist is done', function () {
    $id = startOffboarding(Employee::factory()->create())->json('data.id');

    $this->postJson("/api/v1/offboardings/{$id}/complete")->assertStatus(422)->assertJsonValidationErrors(['checklist']);

    tickAll($id);
    $this->postJson("/api/v1/offboardings/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.stage', 'completed')
        ->assertJsonPath('data.exited_at', null); // last working day is still ahead

    $this->postJson("/api/v1/offboardings/{$id}/complete")->assertStatus(409);
    $this->getJson('/api/v1/offboardings')->assertJsonPath('summary.completed', 1)->assertJsonPath('summary.in_progress', 0);
});

it('moves people to their final status the day after their last working day and signs them out', function () {
    $leaving = Employee::factory()->inStatus('Active')->withAccount()->create();
    $leaving->user->createToken('phone');
    $staying = Employee::factory()->create();
    startOffboarding($leaving, ['last_working_day' => '2026-11-30'])->assertCreated();
    startOffboarding($staying, ['last_working_day' => '2026-12-31'])->assertCreated();

    $this->travelTo('2026-11-30 23:00:00');
    $this->artisan('offboarding:process-exits')->assertSuccessful();
    expect($leaving->fresh()->employment_status_id)->toBe(statusId('Notice period'));

    $this->travelTo('2026-12-01 00:10:00');
    $this->artisan('offboarding:process-exits')->assertSuccessful();

    $leaving->refresh();
    expect($leaving->employment_status_id)->toBe(statusId('Resigned'))
        ->and($leaving->separation_date->toDateString())->toBe('2026-11-30')
        ->and(User::find($leaving->user_id)->tokens()->count())->toBe(0)
        ->and($staying->fresh()->employment_status_id)->toBe(statusId('Notice period'))
        ->and(Offboarding::where('employee_id', $leaving->id)->value('exited_at'))->not->toBeNull();
    expect(Activity::where('event', 'employee_exited')->count())->toBe(1);

    // Running again changes nothing.
    $this->artisan('offboarding:process-exits')->expectsOutput('Processed 0 exit(s).');
});

it('applies the exit at once when a case is completed after the last working day', function () {
    $employee = Employee::factory()->create();
    $id = startOffboarding($employee, ['last_working_day' => '2026-11-02'])->json('data.id');
    tickAll($id);

    $this->postJson("/api/v1/offboardings/{$id}/complete")->assertOk()->assertJsonPath('data.exited_at', fn ($v) => $v !== null);
    expect($employee->fresh()->employment_status_id)->toBe(statusId('Resigned'));
});

it('edits a case and the exit details', function () {
    $employee = Employee::factory()->create();
    $id = startOffboarding($employee)->json('data.id');

    $this->patchJson("/api/v1/offboardings/{$id}", [
        'stage' => 'exit_interview', 'final_employment_status_id' => statusId('Inactive'), 'last_working_day' => '2026-12-15',
    ])->assertOk()
        ->assertJsonPath('data.stage', 'exit_interview')
        ->assertJsonPath('data.final_employment_status.name', 'Inactive')
        ->assertJsonPath('data.last_working_day', '2026-12-15');

    $this->patchJson("/api/v1/offboardings/{$id}", ['last_working_day' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors(['last_working_day']);
    $this->patchJson("/api/v1/offboardings/{$id}", ['stage' => 'completed'])->assertStatus(422)->assertJsonValidationErrors(['stage']);
});

it('cancels a case: previous status back, exit details cleared', function () {
    $employee = Employee::factory()->inStatus('Probation')->create();
    $id = startOffboarding($employee)->json('data.id');

    $this->deleteJson("/api/v1/offboardings/{$id}")->assertOk();

    $employee->refresh();
    expect(Offboarding::count())->toBe(0)
        ->and($employee->employment_status_id)->toBe(statusId('Probation'))
        ->and($employee->last_working_day)->toBeNull()
        ->and($employee->reason_for_resignation)->toBeNull();
});

it('refuses to cancel once the employee has left', function () {
    $id = startOffboarding(Employee::factory()->create(), ['last_working_day' => '2026-11-02'])->json('data.id');
    $this->artisan('offboarding:process-exits');

    $this->deleteJson("/api/v1/offboardings/{$id}")->assertStatus(409);
});

/* ---------- terminations ---------- */

it('terminates with immediate effect: no notice period, final status now, signed out', function () {
    $employee = Employee::factory()->inStatus('Active')->withAccount()->create();
    $employee->user->createToken('phone');

    startOffboarding($employee, [
        'exit_type' => 'termination', 'resigned_date' => '2026-11-03', 'last_working_day' => '2026-11-03',
        'reason_for_resignation' => 'Gross misconduct',
    ])->assertCreated()
        ->assertJsonPath('data.stage', 'clearance')
        ->assertJsonPath('data.employee.employment_status.name', 'Terminated')
        ->assertJsonPath('data.exited_at', fn ($v) => $v !== null)
        ->assertJsonPath('data.checklist_progress.done', 0);

    $employee->refresh();
    expect($employee->employment_status_id)->toBe(statusId('Terminated'))
        ->and($employee->separation_date->toDateString())->toBe('2026-11-03')
        ->and(User::find($employee->user_id)->tokens()->count())->toBe(0);

    // Already exited: the case is for the checklist now, and can't be cancelled.
    $this->deleteJson('/api/v1/offboardings/'.Offboarding::sole()->id)->assertStatus(409);
});

it('terminates with notice: serves notice in the terminated notice status, then Terminated', function () {
    $employee = Employee::factory()->inStatus('Active')->create();

    startOffboarding($employee, [
        'exit_type' => 'termination', 'last_working_day' => '2026-11-30',
        'notice_employment_status_id' => statusId('Serving notice (terminated)'),
    ])->assertCreated()
        ->assertJsonPath('data.employee.employment_status.name', 'Serving notice (terminated)')
        ->assertJsonPath('data.exited_at', null)
        ->assertJsonPath('data.final_employment_status.name', 'Terminated');

    $this->travelTo('2026-12-01 00:10:00');
    $this->artisan('offboarding:process-exits');

    expect($employee->fresh()->employment_status_id)->toBe(statusId('Terminated'));
});

it('treats setting an exit status in the employee form as an immediate exit', function () {
    $employee = Employee::factory()->inStatus('Active')->withAccount()->create();
    $employee->user->createToken('phone');

    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('Terminated')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resigned_date', 'last_working_day', 'reason_for_resignation']);

    $this->patchJson("/api/v1/employees/{$employee->id}", [
        'employment_status_id' => statusId('Terminated'),
        'resigned_date' => '2026-11-03', 'last_working_day' => '2026-11-03', 'reason_for_resignation' => 'Policy breach',
    ])->assertOk()->assertJsonPath('data.offboarding.stage', 'clearance');

    $case = Offboarding::sole();
    expect($case->exit_type->value)->toBe('termination')
        ->and($case->exited_at)->not->toBeNull()
        ->and($case->previous_employment_status_id)->toBe(statusId('Active'))
        ->and($employee->fresh()->separation_date->toDateString())->toBe('2026-11-03')
        ->and(User::find($employee->user_id)->tokens()->count())->toBe(0);
});

it('picks the exit type from the exit status chosen in the form', function () {
    $employee = Employee::factory()->inStatus('Active')->create();

    $this->patchJson("/api/v1/employees/{$employee->id}", [
        'employment_status_id' => statusId('Retired'),
        'resigned_date' => '2026-10-01', 'last_working_day' => '2026-10-31', 'reason_for_resignation' => 'Age of retirement',
    ])->assertOk();

    expect(Offboarding::sole()->exit_type->value)->toBe('retirement');
});

it('turns an employee serving notice into an immediate exit when HR sets Terminated directly', function () {
    $employee = Employee::factory()->inStatus('Active')->create();
    startOffboarding($employee, ['last_working_day' => '2026-12-31'])->assertCreated();

    $this->patchJson("/api/v1/employees/{$employee->id}", ['employment_status_id' => statusId('Terminated'), 'last_working_day' => '2026-11-03'])
        ->assertOk();

    $case = Offboarding::sole();
    expect($case->final_employment_status_id)->toBe(statusId('Terminated'))
        ->and($case->exited_at)->not->toBeNull()
        ->and($case->stage->value)->toBe('clearance');
});

it('does not ask for exit details for statuses that do not end employment, or for people already in one', function () {
    $active = Employee::factory()->inStatus('Active')->create();
    $this->patchJson("/api/v1/employees/{$active->id}", ['employment_status_id' => statusId('Suspended')])->assertOk();

    // Already Inactive before exit details existed: other edits still work.
    $old = Employee::factory()->inStatus('Inactive')->create();
    $this->patchJson("/api/v1/employees/{$old->id}", ['first_name' => 'Renamed'])->assertOk();

    expect(Offboarding::count())->toBe(0);
});
