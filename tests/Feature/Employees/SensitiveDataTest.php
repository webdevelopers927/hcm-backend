<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('returns PII and writes an activity log entry with who, whose record and ip', function () {
    $viewer = actingAsUser();
    $employee = Employee::factory()->create(['cnic' => '35202-1111111-1']);

    $this->getJson("/api/v1/employees/{$employee->id}/sensitive")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [
            'employee_id' => $employee->id, 'cnic' => '35202-1111111-1', 'passport_no' => null, 'driving_license_no' => null, 'spouses' => [],
        ]]);

    $log = Activity::query()->where('event', 'sensitive_viewed')->sole();
    expect($log->causer_id)->toBe($viewer->id)
        ->and($log->subject_id)->toBe($employee->id)
        ->and($log->subject_type)->toBe(Employee::class)
        ->and($log->properties->get('ip'))->toBe('127.0.0.1')
        ->and($log->created_at)->not->toBeNull()
        // The audit trail itself must not contain the PII.
        ->and(json_encode($log->properties))->not->toContain('35202');
});

it('logs every reveal, not just the first', function () {
    actingAsUser();
    $employee = Employee::factory()->create();

    $this->getJson("/api/v1/employees/{$employee->id}/sensitive")->assertOk();
    $this->getJson("/api/v1/employees/{$employee->id}/sensitive")->assertOk();

    expect(Activity::query()->where('event', 'sensitive_viewed')->count())->toBe(2);
});

it('stores CNIC encrypted at rest', function () {
    $employee = Employee::factory()->create(['cnic' => '35202-2222222-2']);

    $rawCnic = DB::table('employees')->where('id', $employee->id)->value('cnic');

    expect($rawCnic)->not->toContain('35202')
        ->and(DB::table('employees')->where('id', $employee->id)->value('cnic_hash'))->toHaveLength(64);
});

it('returns 404 for an unknown employee', function () {
    actingAsUser();

    $this->getJson('/api/v1/employees/424242/sensitive')->assertNotFound();
});
