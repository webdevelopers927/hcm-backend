<?php

use App\Models\DegreeProgram;
use App\Models\Employee;

beforeEach(fn () => actingAsUser());

it('lists the seeded degree programs and searches them', function () {
    $this->getJson('/api/v1/degree-programs')->assertOk()->assertJsonCount(DegreeProgram::count(), 'data');

    $this->getJson('/api/v1/degree-programs?search=computer')
        ->assertJsonPath('data.*.name', ['BS Computer Science'])
        ->assertJsonPath('data.0.qualifications_count', 0);
});

it('creates, shows, renames and deletes a degree program', function () {
    $id = $this->postJson('/api/v1/degree-programs', ['name' => 'BS Data Science'])
        ->assertCreated()
        ->assertJsonPath('data', fn ($d) => $d['name'] === 'BS Data Science' && $d['qualifications_count'] === 0)
        ->json('data.id');

    $this->getJson("/api/v1/degree-programs/{$id}")->assertOk()->assertJsonPath('data.name', 'BS Data Science');
    $this->patchJson("/api/v1/degree-programs/{$id}", ['name' => 'BS Artificial Intelligence'])->assertOk();
    $this->deleteJson("/api/v1/degree-programs/{$id}")->assertOk();

    expect(DegreeProgram::find($id))->toBeNull();
});

it('rejects duplicate names', function () {
    $this->postJson('/api/v1/degree-programs', ['name' => 'mba'])->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('refuses to delete a program used by a qualification', function () {
    $mba = DegreeProgram::where('name', 'MBA')->firstOrFail();
    Employee::factory()->draft()->create()->qualifications()->create(['institution' => 'LUMS', 'degree_program_id' => $mba->id]);

    $this->deleteJson("/api/v1/degree-programs/{$mba->id}")->assertStatus(409);
    expect(DegreeProgram::find($mba->id))->not->toBeNull();
});
