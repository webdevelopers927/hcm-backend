<?php

use App\Models\Employee;
use Spatie\Activitylog\Models\Activity;

it('soft deletes, revokes the linked user tokens and logs the delete', function () {
    $actor = actingAsUser();
    $employee = Employee::factory()->withAccount()->create();
    $employee->user->createToken('mobile');

    $this->deleteJson("/api/v1/employees/{$employee->id}")->assertOk();

    expect(Employee::find($employee->id))->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id)->trashed())->toBeTrue()
        ->and($employee->user->tokens()->count())->toBe(0);

    $log = Activity::query()->where('event', 'deleted')->sole();
    expect($log->subject_id)->toBe($employee->id)->and($log->causer_id)->toBe($actor->id);
});

it('forbids deleting your own employee record with 403', function () {
    $me = Employee::factory()->create();
    actingAsUser($me);

    $this->deleteJson("/api/v1/employees/{$me->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot delete your own employee record.');

    expect($me->fresh()->trashed())->toBeFalse();
});

it('returns 404 when deleting a missing or already deleted employee', function () {
    actingAsUser();
    $employee = Employee::factory()->create();
    $employee->delete();

    $this->deleteJson("/api/v1/employees/{$employee->id}")->assertNotFound();
});

it('bulk deletes and reports partial failures per id', function () {
    $me = Employee::factory()->create();
    actingAsUser($me);
    [$a, $b] = Employee::factory()->count(2)->create();
    $gone = Employee::factory()->create();
    $gone->delete();

    $this->postJson('/api/v1/employees/bulk-delete', ['ids' => [$a->id, $me->id, 999999, $b->id, $gone->id]])
        ->assertOk()
        ->assertExactJson([
            'deleted' => [$a->id, $b->id],
            'failed' => [
                ['id' => $me->id, 'reason' => 'cannot_delete_self'],
                ['id' => 999999, 'reason' => 'not_found'],
                ['id' => $gone->id, 'reason' => 'not_found'],
            ],
        ]);

    expect($me->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->where('event', 'deleted')->count())->toBe(2);
});

it('validates bulk delete input', function (array $payload, string $field) {
    actingAsUser();

    $this->postJson('/api/v1/employees/bulk-delete', $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    [[], 'ids'],
    [['ids' => []], 'ids'],
    [['ids' => ['abc']], 'ids.0'],
    [['ids' => [1, 1]], 'ids.0'],
]);
