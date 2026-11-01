<?php

beforeEach(function () {
    actingAsUser();
    seedGeo();
});

it('lists countries alphabetically with dial codes, and searches by name or ISO code', function () {
    $this->getJson('/api/v1/countries')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', ['id' => 167, 'name' => 'Pakistan', 'iso2' => 'PK', 'iso3' => 'PAK', 'phone_code' => '92', 'emoji' => '🇵🇰']);

    $this->getJson('/api/v1/countries?search=pk')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 167);
    $this->getJson('/api/v1/countries?search=united')->assertJsonCount(2, 'data');
});

it('lists the states of a country', function () {
    $this->getJson('/api/v1/countries/167/states')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Punjab', 'Sindh']);

    $this->getJson('/api/v1/countries/999/states')->assertNotFound();
});

it('pages and searches the cities of a state', function () {
    $this->getJson('/api/v1/states/3176/cities?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Faisalabad')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/v1/states/3176/cities?search=lah')->assertJsonPath('data.*.id', [85485]);
});

it('lists each nationality once, keyed by a country id', function () {
    $this->getJson('/api/v1/nationalities')
        ->assertOk()
        ->assertExactJson(['data' => [['id' => 232, 'name' => 'American'], ['id' => 167, 'name' => 'Pakistani']]]);
});
