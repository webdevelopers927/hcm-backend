<?php

use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actor = actingAsUser();
    $this->travelTo('2026-11-03 10:00:00');
    $this->morning = Shift::where('name', 'Morning')->firstOrFail();
    $this->evening = Shift::where('name', 'Evening')->firstOrFail();
    $this->night = Shift::where('name', 'Night')->firstOrFail();
});

function assignShift(array $ids, Shift $shift, string $from)
{
    return test()->postJson('/api/v1/employee-shifts/assign', ['employee_ids' => $ids, 'shift_id' => $shift->id, 'effective_from' => $from]);
}

it('lists employees with their shift, the shift before it and when it started', function () {
    $e = Employee::factory()->create(['first_name' => 'Fatima', 'last_name' => 'Javed', 'shift_id' => $this->morning->id]);
    assignShift([$e->id], $this->morning, '2025-01-01')->assertOk();
    assignShift([$e->id], $this->evening, '2026-01-01')->assertOk();

    $this->getJson('/api/v1/employee-shifts')
        ->assertOk()
        ->assertJsonPath('data.0.employee', ['id' => $e->id, 'code' => $e->fresh()->code, 'name' => 'Fatima Javed', 'avatar_url' => null])
        ->assertJsonPath('data.0.shift', ['id' => $this->evening->id, 'name' => 'Evening', 'start' => '14:00', 'end' => '23:00', 'break_minutes' => 60])
        ->assertJsonPath('data.0.previous_shift.name', 'Morning')
        ->assertJsonPath('data.0.effective_from', '2026-01-01')
        ->assertJsonPath('data.0.upcoming', null)
        ->assertJsonStructure(['data' => [['department' => ['id', 'name'], 'division']], 'meta' => ['total'], 'shifts', 'counts']);
});

it('counts every shift for the cards and tabs, honouring search but not the shift filter', function () {
    Employee::factory()->count(2)->create(['shift_id' => $this->morning->id]);
    Employee::factory()->create(['shift_id' => $this->night->id, 'first_name' => 'Zara']);
    Employee::factory()->create(['shift_id' => null]);
    Employee::factory()->draft()->create(['shift_id' => $this->morning->id]);

    $response = $this->getJson("/api/v1/employee-shifts?shift_id={$this->morning->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('counts', ['all' => 4, 'unassigned' => 1]);
    expect(collect($response->json('shifts'))->pluck('employees_count', 'name')->all())
        ->toMatchArray(['Morning' => 2, 'Night' => 1, 'Evening' => 0])
        ->and($response->json('shifts.0'))->toHaveKeys(['id', 'name', 'start', 'end', 'break_minutes', 'employees_count']);

    $this->getJson('/api/v1/employee-shifts?shift_id=unassigned')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/employee-shifts?search=zara')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('counts.all', 1);
});

it('searches by department and division', function () {
    $sales = Designation::whereHas('department', fn ($q) => $q->where('name', 'Sales'))->firstOrFail();
    Employee::factory()->create(['department_id' => $sales->department_id, 'designation_id' => $sales->id]);
    Employee::factory()->create(['department_id' => Designation::whereHas('department', fn ($q) => $q->where('name', 'Design'))->value('department_id')]);

    $this->getJson('/api/v1/employee-shifts?search=sales')->assertJsonPath('meta.total', 1);
});

it('moves employees today, keeps history and logs it', function () {
    [$a, $b] = Employee::factory()->count(2)->create(['shift_id' => $this->morning->id]);

    assignShift([$a->id, $b->id], $this->night, '2026-11-03')
        ->assertOk()
        ->assertJson(['assigned' => 2, 'scheduled' => false]);

    expect($a->fresh()->shift_id)->toBe($this->night->id)->and($b->fresh()->shift_id)->toBe($this->night->id);
    expect(Activity::where('event', 'shift_assigned')->sole()->properties->get('employee_ids'))->toBe([$a->id, $b->id]);
});

it('schedules a future change, shows it as upcoming and applies it on the day', function () {
    $e = Employee::factory()->create(['shift_id' => $this->morning->id]);

    assignShift([$e->id], $this->evening, '2026-11-10')->assertOk()->assertJsonPath('scheduled', true);

    expect($e->fresh()->shift_id)->toBe($this->morning->id);
    $this->getJson('/api/v1/employee-shifts')
        ->assertJsonPath('data.0.shift.name', 'Morning')
        ->assertJsonPath('data.0.upcoming.shift.name', 'Evening')
        ->assertJsonPath('data.0.upcoming.effective_from', '2026-11-10');

    $this->travelTo('2026-11-10 00:05:00');
    $this->artisan('shifts:apply-scheduled')->assertSuccessful();

    expect($e->fresh()->shift_id)->toBe($this->evening->id);
    $this->getJson('/api/v1/employee-shifts')
        ->assertJsonPath('data.0.upcoming', null)
        ->assertJsonPath('data.0.effective_from', '2026-11-10');
});

it('cancels a scheduled change but not one already in effect', function () {
    $e = Employee::factory()->create(['shift_id' => $this->morning->id]);
    assignShift([$e->id], $this->evening, '2026-11-10')->assertOk();
    assignShift([$e->id], $this->night, '2026-11-01')->assertOk();

    $upcoming = EmployeeShiftAssignment::whereNull('applied_at')->sole();
    $applied = EmployeeShiftAssignment::whereNotNull('applied_at')->sole();

    $this->deleteJson("/api/v1/employee-shifts/assignments/{$applied->id}")->assertStatus(409);
    $this->deleteJson("/api/v1/employee-shifts/assignments/{$upcoming->id}")->assertOk();

    expect(EmployeeShiftAssignment::count())->toBe(1);
});

it('does not let a back-dated change undo a newer one', function () {
    $e = Employee::factory()->create(['shift_id' => $this->morning->id]);
    assignShift([$e->id], $this->night, '2026-10-01')->assertOk();
    assignShift([$e->id], $this->evening, '2026-01-01')->assertOk();

    expect($e->fresh()->shift_id)->toBe($this->night->id);
});

it('replaces a change made for the same day', function () {
    $e = Employee::factory()->create(['shift_id' => $this->morning->id]);
    assignShift([$e->id], $this->night, '2026-11-20')->assertOk();
    assignShift([$e->id], $this->evening, '2026-11-20')->assertOk();

    expect(EmployeeShiftAssignment::sole()->shift_id)->toBe($this->evening->id);
});

it('validates a shift change', function (Closure $payload, array $errors) {
    $this->postJson('/api/v1/employee-shifts/assign', $payload())->assertStatus(422)->assertJsonValidationErrors($errors);
})->with([
    'empty' => [fn () => [], ['employee_ids', 'shift_id', 'effective_from']],
    'unknown employee and shift' => [fn () => ['employee_ids' => [999999], 'shift_id' => 999, 'effective_from' => '2026-11-03'], ['employee_ids.0', 'shift_id']],
    'draft employee' => [fn () => ['employee_ids' => [Employee::factory()->draft()->create()->id], 'shift_id' => Shift::value('id'), 'effective_from' => '2026-11-03'], ['employee_ids.0']],
    'bad date' => [fn () => ['employee_ids' => [Employee::factory()->create()->id], 'shift_id' => Shift::value('id'), 'effective_from' => '03/11/2026'], ['effective_from']],
]);

it('records shift history from the employee form: the starting shift and later changes', function () {
    Mail::fake();
    $designation = Designation::whereNotNull('department_id')->firstOrFail();
    $id = $this->postJson('/api/v1/employees', [
        'first_name' => 'New', 'last_name' => 'Hire', 'email' => 'new.hire@company.pk', 'cnic' => '35202-3333333-3',
        'department_id' => $designation->department_id, 'designation_id' => $designation->id,
        'employment_status_id' => statusId('Active'), 'duty_type_id' => dutyTypeId('Full-time'), 'join_date' => '2026-10-01', 'shift_id' => $this->morning->id,
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/employees/{$id}", ['shift_id' => $this->night->id])->assertOk();
    $this->patchJson("/api/v1/employees/{$id}", ['first_name' => 'Renamed'])->assertOk();

    $this->getJson("/api/v1/employees/{$id}/shift-history")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.shift.name', 'Night')
        ->assertJsonPath('data.0.effective_from', '2026-11-03')
        ->assertJsonPath('data.0.status', 'current')
        ->assertJsonPath('data.0.assigned_by.id', $this->actor->id)
        ->assertJsonPath('data.1.shift.name', 'Morning')
        ->assertJsonPath('data.1.effective_from', '2026-10-01')
        ->assertJsonPath('data.1.status', 'past');
});

it('marks scheduled changes as upcoming in the history', function () {
    $e = Employee::factory()->create(['shift_id' => $this->morning->id]);
    assignShift([$e->id], $this->evening, '2026-12-01')->assertOk();

    $this->getJson("/api/v1/employees/{$e->id}/shift-history")->assertJsonPath('data.0.status', 'upcoming');
});
