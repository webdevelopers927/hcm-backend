<?php

use App\Models\Employee;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => actingAsUser());

it('resets two-factor authentication and logs it', function () {
    $employee = Employee::factory()->withAccount(twoFactor: true)->create();
    $employee->user->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();

    $this->postJson("/api/v1/employees/{$employee->id}/two-factor/reset")
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    $user = $employee->user->fresh();
    expect($user->two_factor_enabled)->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'two_factor_reset')->count())->toBe(1);
});

it('returns 422 when resetting 2FA for an employee without an account', function () {
    $employee = Employee::factory()->create();

    $this->postJson("/api/v1/employees/{$employee->id}/two-factor/reset")
        ->assertStatus(422)->assertJsonValidationErrors(['employee']);
});

it('shows the bound device', function () {
    $employee = Employee::factory()->withDevice()->create();
    $device = $employee->devices()->first();

    $this->getJson("/api/v1/employees/{$employee->id}/device")
        ->assertOk()
        ->assertJsonPath('data.device_id', $device->device_id)
        ->assertJsonPath('data.platform', $device->platform)
        ->assertJsonPath('data.bound_at', $device->bound_at->toIso8601String());
});

it('returns null data when no device is bound', function () {
    $employee = Employee::factory()->create();

    $this->getJson("/api/v1/employees/{$employee->id}/device")->assertOk()->assertExactJson(['data' => null]);
});

it('unbinds the device, keeps history and logs it', function () {
    $employee = Employee::factory()->withDevice()->create();

    $this->postJson("/api/v1/employees/{$employee->id}/device/unbind")
        ->assertOk()
        ->assertJsonPath('device_bound', false);

    expect($employee->devices()->count())->toBe(1)
        ->and($employee->devices()->first()->unbound_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'device_unbound')->count())->toBe(1);

    $this->getJson("/api/v1/employees/{$employee->id}/device")->assertExactJson(['data' => null]);
    $this->getJson("/api/v1/employees/{$employee->id}")->assertJsonPath('data.device_bound', false);
});

it('returns 422 when unbinding with no device bound', function () {
    $employee = Employee::factory()->create();

    $this->postJson("/api/v1/employees/{$employee->id}/device/unbind")
        ->assertStatus(422)->assertJsonValidationErrors(['device']);
});
