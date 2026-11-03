<?php

use App\Models\DutyType;
use App\Models\Employee;
use Database\Seeders\LookupSeeder;

beforeEach(fn () => actingAsUser());

it('lists the seeded duty types in order with the contractual flag and headcount', function () {
    Employee::factory()->count(2)->create(['duty_type_id' => dutyTypeId('Internship')]);
    Employee::factory()->draft()->create(['duty_type_id' => dutyTypeId('Internship')]);

    $rows = collect($this->getJson('/api/v1/duty-types')->assertOk()->json('data'));

    expect($rows->pluck('name')->all())->toBe(array_keys(LookupSeeder::DUTY_TYPES))
        ->and($rows->where('is_contractual', true)->pluck('name')->all())->toBe(['Contract', 'Freelance', 'Internship', 'Temporary', 'Consultant'])
        ->and($rows->firstWhere('name', 'Internship')['employees_count'])->toBe(2);
});

it('creates, shows and updates a duty type', function () {
    $id = $this->postJson('/api/v1/duty-types', ['name' => '  Remote contractor ', 'is_contractual' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Remote contractor')
        ->assertJsonPath('data.is_contractual', true)
        ->assertJsonPath('data.sort_order', count(LookupSeeder::DUTY_TYPES))
        ->json('data.id');

    $this->getJson("/api/v1/duty-types/{$id}")->assertOk()->assertJsonPath('data.employees_count', 0);
    $this->patchJson("/api/v1/duty-types/{$id}", ['is_contractual' => false, 'sort_order' => 1])
        ->assertOk()
        ->assertJsonPath('data', fn ($d) => $d['is_contractual'] === false && $d['sort_order'] === 1 && $d['name'] === 'Remote contractor');
});

it('validates a duty type', function () {
    $this->postJson('/api/v1/duty-types', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'is_contractual']);
    $this->postJson('/api/v1/duty-types', ['name' => 'full-time', 'is_contractual' => false])->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/duty-types', ['name' => 'X', 'is_contractual' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['is_contractual']);
});

it('refuses to delete a duty type in use, and deletes an unused one', function () {
    Employee::factory()->create(['duty_type_id' => dutyTypeId('Consultant')])->delete(); // soft-deleted still counts

    $this->deleteJson('/api/v1/duty-types/'.dutyTypeId('Consultant'))->assertStatus(409);

    $unused = DutyType::create(['name' => 'Seasonal', 'is_contractual' => true]);
    $this->deleteJson("/api/v1/duty-types/{$unused->id}")->assertOk();
    expect(DutyType::find($unused->id))->toBeNull();
});

it('serves duty types in the employee form options and the form record', function () {
    $this->getJson('/api/v1/employees/form-options')
        ->assertJsonPath('data.duty_types.2', ['id' => dutyTypeId('Contract'), 'name' => 'Contract', 'is_contractual' => true]);

    $draft = Employee::factory()->draft()->create(['duty_type_id' => dutyTypeId('Freelance')]);
    $this->getJson("/api/v1/employees/{$draft->id}/details")
        ->assertJsonPath('data.duty_type_id', dutyTypeId('Freelance'))
        ->assertJsonPath('data.duty_type.is_contractual', true);

    $this->patchJson("/api/v1/employees/{$draft->id}", ['duty_type_id' => 999999])
        ->assertStatus(422)->assertJsonValidationErrors(['duty_type_id']);
});
