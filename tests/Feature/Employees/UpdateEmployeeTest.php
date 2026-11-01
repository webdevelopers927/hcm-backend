<?php

use App\Models\Employee;

beforeEach(fn () => actingAsUser());

it('partially updates an employee and syncs the linked user', function () {
    $employee = Employee::factory()->withAccount()->create(['first_name' => 'Old', 'work_mode' => 'onsite']);

    $this->patchJson("/api/v1/employees/{$employee->id}", ['first_name' => 'New', 'work_mode' => 'remote', 'email' => 'new.mail@company.pk'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New '.$employee->last_name)
        ->assertJsonPath('data.work_mode', 'remote');

    expect($employee->fresh()->user->only(['name', 'email']))->toBe(['name' => 'New '.$employee->last_name, 'email' => 'new.mail@company.pk']);
});

it('ignores its own email and CNIC on the unique checks', function () {
    $employee = Employee::factory()->withAccount()->create(['cnic' => '35202-5555555-5']);

    $this->patchJson("/api/v1/employees/{$employee->id}", ['email' => $employee->email, 'cnic' => '35202-5555555-5'])
        ->assertOk();
});

it('validates partial updates', function (array $payload, string $field) {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create(['email' => 'taken@company.pk']);

    $this->patchJson("/api/v1/employees/{$employee->id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'blank required field' => [['first_name' => ''], 'first_name'],
    'taken email' => [['email' => 'taken@company.pk'], 'email'],
    'bad cnic' => [['cnic' => '123'], 'cnic'],
]);

it('prevents reporting cycles', function () {
    $top = Employee::factory()->create();
    $middle = Employee::factory()->create(['reports_to_id' => $top->id]);
    $bottom = Employee::factory()->create(['reports_to_id' => $middle->id]);

    $this->patchJson("/api/v1/employees/{$top->id}", ['reports_to_id' => $bottom->id])
        ->assertStatus(422)->assertJsonValidationErrors(['reports_to_id']);
    $this->patchJson("/api/v1/employees/{$top->id}", ['reports_to_id' => $top->id])
        ->assertStatus(422)->assertJsonValidationErrors(['reports_to_id']);
});

it('returns 404 when updating a missing employee', function () {
    $this->patchJson('/api/v1/employees/987654', ['first_name' => 'X'])->assertNotFound();
});
