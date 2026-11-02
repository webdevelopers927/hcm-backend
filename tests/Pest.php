<?php

use App\Models\Employee;
use App\Models\User;
use Database\Factories\EmployeeFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

/**
 * A tiny slice of the geo data (the full GeoSeeder is too slow per test). Country and state ids match the
 * dataset; city ids are made up.
 */
function seedGeo(): void
{
    DB::table('countries')->insert([
        ['id' => 167, 'name' => 'Pakistan', 'iso2' => 'PK', 'iso3' => 'PAK', 'phone_code' => '92', 'nationality' => 'Pakistani', 'emoji' => '🇵🇰'],
        ['id' => 233, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'phone_code' => '1', 'nationality' => 'American', 'emoji' => '🇺🇸'],
        ['id' => 232, 'name' => 'United States Minor Outlying Islands', 'iso2' => 'UM', 'iso3' => 'UMI', 'phone_code' => '1', 'nationality' => 'American', 'emoji' => '🇺🇲'],
    ]);
    DB::table('states')->insert([
        ['id' => 3176, 'country_id' => 167, 'name' => 'Punjab', 'code' => 'PB'],
        ['id' => 3175, 'country_id' => 167, 'name' => 'Sindh', 'code' => 'SD'],
        ['id' => 1416, 'country_id' => 233, 'name' => 'California', 'code' => 'CA'],
    ]);
    DB::table('cities')->insert([
        ['id' => 85485, 'state_id' => 3176, 'country_id' => 167, 'name' => 'Lahore'],
        ['id' => 85421, 'state_id' => 3176, 'country_id' => 167, 'name' => 'Faisalabad'],
        ['id' => 85410, 'state_id' => 3175, 'country_id' => 167, 'name' => 'Karachi'],
        ['id' => 113277, 'state_id' => 1416, 'country_id' => 233, 'name' => 'Los Angeles'],
    ]);
}

/** Uploads a file through POST /uploads and returns its id. */
function uploadFile(string $purpose, ?UploadedFile $file = null): string
{
    $file ??= $purpose === 'avatar'
        ? UploadedFile::fake()->image('me.jpg', 200, 200)
        : UploadedFile::fake()->create('letter.pdf', 120, 'application/pdf');

    return test()->post('/api/v1/uploads', ['purpose' => $purpose, 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.id');
}

/** Id of a seeded employment status, by name ("Active", "Notice period"…). */
function statusId(string $name): int
{
    return (int) EmployeeFactory::statusId($name);
}

/** Shape of an EmployeeListItem; used to assert the list contract. */
function employeeListItemKeys(): array
{
    return [
        'id', 'code', 'machine_uid', 'name', 'pseudo_name', 'email', 'avatar_url', 'designation', 'department',
        'employment_status', 'work_mode', 'shift', 'campaign', 'location', 'join_date', 'two_factor_enabled', 'device_bound', 'can',
    ];
}
