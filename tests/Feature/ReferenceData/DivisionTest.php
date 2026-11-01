<?php

use App\Models\Division;
use App\Models\Employee;

beforeEach(fn () => actingAsUser());

it('lists divisions with how many employees use each', function () {
    $tech = Division::where('name', 'Technology')->firstOrFail();
    Employee::factory()->count(2)->create(['division_id' => $tech->id]);

    $this->getJson('/api/v1/divisions')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Commercial', 'Corporate', 'Technology'])
        ->assertJsonPath('data.2', ['id' => $tech->id, 'name' => 'Technology', 'employees_count' => 2]);
});

it('creates, shows and renames a division', function () {
    $id = $this->postJson('/api/v1/divisions', ['name' => '  Research  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Research')
        ->json('data.id');

    $this->getJson("/api/v1/divisions/{$id}")->assertOk()->assertJsonPath('data.employees_count', 0);
    $this->patchJson("/api/v1/divisions/{$id}", ['name' => 'R&D'])->assertOk()->assertJsonPath('data.name', 'R&D');
});

it('validates the name', function () {
    $this->postJson('/api/v1/divisions', [])->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/divisions', ['name' => 'technology'])->assertStatus(422)->assertJsonValidationErrors(['name']);

    $tech = Division::where('name', 'Technology')->firstOrFail();
    $this->patchJson("/api/v1/divisions/{$tech->id}", ['name' => 'Technology'])->assertOk();
});

it('refuses to delete a division that is in use, and deletes an unused one', function () {
    $tech = Division::where('name', 'Technology')->firstOrFail();
    Employee::factory()->draft()->create(['division_id' => $tech->id]);

    $this->deleteJson("/api/v1/divisions/{$tech->id}")->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, '1 employee'));
    expect(Division::find($tech->id))->not->toBeNull();

    $unused = Division::create(['name' => 'Temporary']);
    $this->deleteJson("/api/v1/divisions/{$unused->id}")->assertOk();
    expect(Division::find($unused->id))->toBeNull();
});
