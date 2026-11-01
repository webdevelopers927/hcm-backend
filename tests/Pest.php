<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(LookupSeeder::class))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Authenticate as a fresh user (optionally linked to an employee record). */
function actingAsUser(?Employee $employee = null): User
{
    $user = $employee?->user ?? User::factory()->create();

    if ($employee && ! $employee->user_id) {
        $employee->user()->associate($user)->saveQuietly();
    }

    Sanctum::actingAs($user);

    return $user;
}

/** Shape of an EmployeeListItem; used to assert the list contract. */
function employeeListItemKeys(): array
{
    return [
        'id', 'code', 'machine_uid', 'name', 'pseudo_name', 'email', 'avatar_url', 'designation', 'department',
        'status', 'work_mode', 'shift', 'campaign', 'location', 'join_date', 'two_factor_enabled', 'device_bound', 'can',
    ];
}
