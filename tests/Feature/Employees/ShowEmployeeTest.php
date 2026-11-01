<?php

use App\Models\Employee;

beforeEach(fn () => actingAsUser());

it('returns the full profile shape', function () {
    $manager = Employee::factory()->create();
    $employee = Employee::factory()->withAccount()->withDevice()->create([
        'reports_to_id' => $manager->id,
        'birth_date' => '1994-07-23',
        'phone' => '+92 321-5550000',
    ]);
    $reports = Employee::factory()->count(2)->create(['reports_to_id' => $employee->id]);

    $response = $this->getJson("/api/v1/employees/{$employee->id}")->assertOk();

    expect(array_keys($response->json('data')))->toEqualCanonicalizing([
        ...employeeListItemKeys(),
        'phone', 'gender', 'birthday', 'reports_to', 'direct_reports', 'employment', 'attendance_rate', 'leave_balances',
    ]);

    $response
        ->assertJsonPath('data.birthday', '07-23')
        ->assertJsonPath('data.phone', '+92 321-5550000')
        ->assertJsonPath('data.device_bound', true)
        ->assertJsonPath('data.reports_to.id', $manager->id)
        ->assertJsonStructure(['data' => ['reports_to' => ['id', 'name', 'designation' => ['id', 'name'], 'avatar_url']]])
        ->assertJsonCount(2, 'data.direct_reports')
        ->assertJsonPath('data.employment.join_date', $employee->join_date->toDateString())
        ->assertJsonPath('data.attendance_rate', null)
        ->assertJsonPath('data.leave_balances', null);

    expect(collect($response->json('data.direct_reports'))->pluck('id')->sort()->values()->all())
        ->toBe($reports->pluck('id')->sort()->values()->all());
});

it('never exposes the birth year or CNIC on the profile', function () {
    $employee = Employee::factory()->create(['birth_date' => '1991-02-03', 'cnic' => '35202-7654321-9']);

    $body = $this->getJson("/api/v1/employees/{$employee->id}")->assertOk()->getContent();

    expect($body)->not->toContain('1991')->not->toContain('35202-7654321-9');
});

it('returns null reports_to and empty direct_reports when there are none', function () {
    $employee = Employee::factory()->create();

    $this->getJson("/api/v1/employees/{$employee->id}")
        ->assertJsonPath('data.reports_to', null)
        ->assertJsonPath('data.direct_reports', []);
});

it('returns 404 for a soft-deleted employee', function () {
    $employee = Employee::factory()->create();
    $employee->delete();

    $this->getJson("/api/v1/employees/{$employee->id}")->assertNotFound();
});

it('returns every dropdown option from the filters endpoint', function () {
    $this->getJson('/api/v1/employees/filters')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'departments' => [['id', 'name']],
            'designations' => [['id', 'name', 'department_id']],
            'divisions' => [['id', 'name']],
            'campaigns' => [['id', 'name']],
            'shifts' => [['id', 'name', 'start', 'end']],
            'locations' => [['id', 'name']],
            'statuses' => [['value', 'label']],
            'work_modes' => [['value', 'label']],
        ]])
        ->assertJsonCount(8, 'data.departments')
        ->assertJsonCount(4, 'data.shifts')
        ->assertJsonCount(5, 'data.statuses')
        ->assertJsonCount(3, 'data.work_modes')
        ->assertJsonFragment(['name' => 'Morning', 'start' => '09:00', 'end' => '18:00']);
});
