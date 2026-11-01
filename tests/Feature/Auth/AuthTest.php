<?php

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->password = 'correct-horse-battery-staple';
    $this->user = User::factory()->create(['email' => 'hr@company.pk', 'password' => $this->password]);
});

it('logs in and returns a bearer token and the user', function () {
    $response = $this->postJson('/api/v1/auth/login', ['email' => 'hr@company.pk', 'password' => $this->password]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'roles', 'permissions']])
        ->assertJsonPath('user.email', 'hr@company.pk');

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk();
});

it('rejects a wrong password with 422 and the standard error shape', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'hr@company.pk', 'password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);
});

it('validates login input', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects users whose employee record was deleted', function () {
    $employee = Employee::factory()->create(['email' => 'hr@company.pk']);
    $employee->user()->associate($this->user)->save();
    $employee->delete();

    $this->postJson('/api/v1/auth/login', ['email' => 'hr@company.pk', 'password' => $this->password])
        ->assertStatus(422);
});

it('rate limits login to 5 attempts per minute per email and ip', function () {
    RateLimiter::clear('hr@company.pk|127.0.0.1');

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'hr@company.pk', 'password' => 'wrong'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'hr@company.pk', 'password' => $this->password])
        ->assertStatus(429)
        ->assertJsonStructure(['message']);
});

it('returns me with roles and a flat permissions array', function () {
    actingAsUser();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', Permission::values());
});

it('logs out by revoking only the current token', function () {
    $token = $this->user->createToken('a')->plainTextToken;
    $this->user->createToken('b');

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($this->user->tokens()->count())->toBe(1);
});

it('returns 401 json for unauthenticated requests', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/logout'],
    ['GET', '/api/v1/employees'],
    ['GET', '/api/v1/employees/filters'],
    ['GET', '/api/v1/employees/1'],
    ['GET', '/api/v1/employees/1/sensitive'],
    ['POST', '/api/v1/employees'],
    ['PATCH', '/api/v1/employees/1'],
    ['DELETE', '/api/v1/employees/1'],
    ['POST', '/api/v1/employees/bulk-delete'],
    ['POST', '/api/v1/employees/1/two-factor/reset'],
    ['GET', '/api/v1/employees/1/device'],
    ['POST', '/api/v1/employees/1/device/unbind'],
    ['POST', '/api/v1/employees/export'],
]);

it('returns json 404 without leaking model names', function () {
    actingAsUser();

    $this->getJson('/api/v1/employees/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});
