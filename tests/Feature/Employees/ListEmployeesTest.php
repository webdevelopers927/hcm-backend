<?php

use App\Enums\WorkMode;
use App\Models\Campaign;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => actingAsUser());

it('returns the list contract with meta and status counts', function () {
    Employee::factory()->count(3)->withAccount()->create();

    $this->getJson('/api/v1/employees')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['shift' => ['id', 'name', 'start', 'end'], 'can' => ['update', 'delete', 'reset_2fa', 'unbind_device']]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'status_counts' => ['all', 'on_notice', 'by_status'],
        ])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 12)
        ->assertJsonMissingPath('links')
        ->assertJsonCount(count(employeeListItemKeys()), 'data.0');

    expect(array_keys($this->getJson('/api/v1/employees')->json('data.0')))->toEqualCanonicalizing(employeeListItemKeys());
});

it('never includes CNIC or phone in the list', function () {
    Employee::factory()->create(['cnic' => '35202-1234567-1', 'phone' => '+92 300-1112223']);

    $body = $this->getJson('/api/v1/employees')->assertOk()->getContent();

    expect($body)
        ->not->toContain('35202-1234567-1')
        ->not->toContain('+92 300-1112223')
        ->not->toContain('"cnic"')
        ->not->toContain('"phone"');
});

it('searches by name, email, code, machine uid, pseudo name and designation', function (string $field) {
    // Create the others first: the factory picks a random existing designation.
    Employee::factory()->count(3)->create(['pseudo_name' => null]);
    $designation = Designation::create(['name' => 'Chief Zebra Officer', 'department_id' => Department::first()->id]);
    $target = Employee::factory()->create([
        'first_name' => 'Zarnigar', 'last_name' => 'Qadri', 'email' => 'zq.unique@company.pk',
        'machine_uid' => '987654', 'pseudo_name' => 'Zee', 'designation_id' => $designation->id,
    ]);

    $term = match ($field) {
        'first_name' => 'Zarnig',
        'full_name' => 'Zarnigar Qad',
        'email' => 'zq.unique',
        'code' => $target->fresh()->code,
        'machine_uid' => '987654',
        'pseudo_name' => 'Zee',
        'designation' => 'Zebra',
    };

    $this->getJson('/api/v1/employees?search='.urlencode($term))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $target->id);
})->with(['first_name', 'full_name', 'email', 'code', 'machine_uid', 'pseudo_name', 'designation']);

it('treats LIKE wildcards in search literally', function () {
    Employee::factory()->count(2)->create();

    $this->getJson('/api/v1/employees?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
});

it('filters by each id filter', function (string $param, string $model) {
    [$a, $b] = [$model::query()->first(), $model::query()->skip(1)->first()];
    $column = $param;
    Employee::factory()->count(2)->create([$column => $a->id]);
    Employee::factory()->count(3)->create([$column => $b->id]);

    $this->getJson("/api/v1/employees?{$param}[]={$a->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson("/api/v1/employees?{$param}[]={$a->id}&{$param}[]={$b->id}")
        ->assertJsonPath('meta.total', 5);
})->with([
    ['department_id', Department::class],
    ['designation_id', Designation::class],
    ['division_id', Division::class],
    ['campaign_id', Campaign::class],
    ['shift_id', Shift::class],
    ['location_id', Location::class],
]);

it('filters by employment status and work mode', function () {
    [$active, $probation] = [statusId('Active'), statusId('Probation')];
    Employee::factory()->count(2)->create(['employment_status_id' => $probation, 'work_mode' => WorkMode::Remote]);
    Employee::factory()->count(3)->create(['employment_status_id' => $active, 'work_mode' => WorkMode::Onsite]);

    $this->getJson("/api/v1/employees?employment_status_id[]={$probation}")
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.employment_status', ['id' => $probation, 'name' => 'Probation', 'can_login' => true, 'is_notice_period' => false]);
    $this->getJson('/api/v1/employees?work_mode[]=onsite')->assertJsonPath('meta.total', 3);
    $this->getJson("/api/v1/employees?employment_status_id={$active},{$probation}")->assertJsonPath('meta.total', 5);
});

it('filters everyone on notice, across notice-period statuses', function () {
    Employee::factory()->inStatus('Notice period')->create();
    Employee::factory()->inStatus('Serving notice (terminated)')->create();
    Employee::factory()->inStatus('Active')->count(2)->create();

    $this->getJson('/api/v1/employees?on_notice=1')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('status_counts.on_notice', 2)
        ->assertJsonPath('status_counts.all', 4);
});

it('computes status counts with every filter except status', function () {
    [$active, $probation] = [statusId('Active'), statusId('Probation')];
    $remote = ['work_mode' => WorkMode::Remote];
    Employee::factory()->count(2)->create(['employment_status_id' => $active] + $remote);
    Employee::factory()->count(1)->create(['employment_status_id' => $probation] + $remote);
    Employee::factory()->count(4)->create(['employment_status_id' => $active, 'work_mode' => WorkMode::Onsite]);

    $counts = $this->getJson("/api/v1/employees?work_mode[]=remote&employment_status_id[]={$probation}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->json('status_counts');

    expect($counts['all'])->toBe(3)
        ->and($counts['by_status'][$active])->toBe(2)
        ->and($counts['by_status'][$probation])->toBe(1)
        ->and($counts['by_status'][statusId('Inactive')])->toBe(0)
        ->and($counts['by_status'])->toHaveCount(EmploymentStatus::count());
});

it('excludes soft-deleted employees', function () {
    Employee::factory()->count(2)->create();
    Employee::factory()->create()->delete();

    $this->getJson('/api/v1/employees')->assertJsonPath('meta.total', 2)->assertJsonPath('status_counts.all', 2);
});

it('sorts by name by default and by every supported sort', function () {
    $eng = Department::where('name', 'Engineering')->first();
    $sales = Department::where('name', 'Sales')->first();
    $c = Employee::factory()->create(['first_name' => 'Cyrus', 'last_name' => 'A', 'join_date' => '2022-01-01', 'department_id' => $sales->id]);
    $a = Employee::factory()->create(['first_name' => 'Aleena', 'last_name' => 'B', 'join_date' => '2020-01-01', 'department_id' => $sales->id]);
    $b = Employee::factory()->create(['first_name' => 'Bilal', 'last_name' => 'C', 'join_date' => '2024-01-01', 'department_id' => $eng->id]);

    $ids = fn (string $query) => collect($this->getJson('/api/v1/employees'.$query)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(''))->toBe([$a->id, $b->id, $c->id])
        ->and($ids('?sort=name'))->toBe([$a->id, $b->id, $c->id])
        ->and($ids('?sort=-name'))->toBe([$c->id, $b->id, $a->id])
        ->and($ids('?sort=-join_date'))->toBe([$b->id, $c->id, $a->id])
        ->and($ids('?sort=join_date'))->toBe([$a->id, $c->id, $b->id])
        ->and($ids('?sort=code'))->toBe([$c->id, $a->id, $b->id])
        ->and($ids('?sort=-code'))->toBe([$b->id, $a->id, $c->id])
        ->and($ids('?sort=department')[0])->toBe($b->id)
        ->and($ids('?sort=-department')[2])->toBe($b->id);
});

it('paginates and caps per_page at 100', function () {
    Employee::factory()->count(15)->create();

    $this->getJson('/api/v1/employees?page=2')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(3, 'data');

    $this->getJson('/api/v1/employees?per_page=5&page=3')->assertJsonCount(5, 'data')->assertJsonPath('meta.last_page', 3);
    $this->getJson('/api/v1/employees?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
});

it('validates list parameters', function (string $query, string $field) {
    $this->getJson('/api/v1/employees?'.$query)->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    ['sort=salary', 'sort'],
    ['employment_status_id[]=abc', 'employment_status_id.0'],
    ['on_notice=maybe', 'on_notice'],
    ['work_mode[]=moon', 'work_mode.0'],
    ['department_id[]=abc', 'department_id.0'],
    ['per_page=0', 'per_page'],
    ['page=-1', 'page'],
]);

it('marks can.delete false for the viewer\'s own record', function () {
    $me = Employee::factory()->create();
    actingAsUser($me);

    $this->getJson('/api/v1/employees')
        ->assertJsonPath('data.0.can', ['update' => true, 'delete' => false, 'reset_2fa' => true, 'unbind_device' => true]);
});

it('reports two_factor_enabled and device_bound per row', function () {
    $withDevice = Employee::factory()->withAccount(twoFactor: true)->withDevice()->create(['first_name' => 'Aaa']);
    Employee::factory()->withAccount(twoFactor: false)->create(['first_name' => 'Bbb']);

    $this->getJson('/api/v1/employees')
        ->assertJsonPath('data.0.id', $withDevice->id)
        ->assertJsonPath('data.0.two_factor_enabled', true)
        ->assertJsonPath('data.0.device_bound', true)
        ->assertJsonPath('data.1.two_factor_enabled', false)
        ->assertJsonPath('data.1.device_bound', false);
});

it('runs the same number of queries for 10 rows and 50 rows', function () {
    $countQueries = function (int $perPage): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/employees?per_page='.$perPage)->assertOk()->assertJsonCount($perPage, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Employee::factory()->count(10)->withAccount()->withDevice()->create();
    $tenRows = $countQueries(10);

    Employee::factory()->count(40)->withAccount()->withDevice()->create();
    $fiftyRows = $countQueries(50);

    expect($fiftyRows)->toBe($tenRows);
});
