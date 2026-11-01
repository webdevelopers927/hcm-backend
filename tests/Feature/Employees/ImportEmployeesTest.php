<?php

use App\Actions\Employees\ImportEmployees;
use App\Mail\EmployeeInvitation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    actingAsUser();
    Mail::fake();
});

const IMPORT_HEADER = ['first_name*', 'last_name*', 'email*', 'department*', 'designation*', 'join_date*',
    'cnic', 'work_mode', 'status', 'shift', 'location', 'reports_to'];

/** @param list<list<string>> $rows */
function importCsv(array $rows, array $header = IMPORT_HEADER): UploadedFile
{
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, $header);
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    rewind($fh);

    return UploadedFile::fake()->createWithContent('employees.csv', stream_get_contents($fh));
}

function validRow(string $first, string $email, array $override = []): array
{
    $row = array_combine(array_map(fn ($h) => rtrim($h, '*'), IMPORT_HEADER), [
        $first, 'Raza', $email, 'engineering', 'Software Engineer', '2025-02-01',
        '', 'Hybrid', 'On leave', 'morning', 'Lahore', '',
    ]);

    return array_values(array_merge($row, $override));
}

function postImport(UploadedFile $file, bool $dryRun = false)
{
    return test()->post('/api/v1/employees/import', ['file' => $file, 'dry_run' => $dryRun ? '1' : '0'], ['Accept' => 'application/json']);
}

it('downloads a template with every column and a valid example row', function () {
    $response = $this->get('/api/v1/employees/import/template')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('employee-import-template.csv');

    $lines = array_map('str_getcsv', array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent()))));
    expect($lines[0])->toHaveCount(count(ImportEmployees::COLUMNS))
        ->and($lines[0])->toContain('first_name*', 'department*', 'phone');

    // The template itself must import cleanly.
    $file = UploadedFile::fake()->createWithContent('t.csv', $response->streamedContent());
    postImport($file, dryRun: true)->assertOk()->assertJsonPath('total_rows', 1);
});

it('validates the whole file in dry run without writing anything', function () {
    postImport(importCsv([validRow('Ali', 'ali.raza@company.pk'), validRow('Sara', 'sara.raza@company.pk')]), dryRun: true)
        ->assertOk()
        ->assertJson(['dry_run' => true, 'total_rows' => 2]);

    expect(Employee::count())->toBe(0)->and(User::count())->toBe(1);
    Mail::assertNothingQueued();
});

it('imports every row like a single create: employee, user and invite', function () {
    $response = postImport(importCsv([validRow('Ali', 'ali.raza@company.pk'), validRow('Sara', 'Sara.Raza@company.pk')]))
        ->assertCreated()
        ->assertJson(['dry_run' => false, 'imported' => 2]);

    $ali = Employee::where('email', 'ali.raza@company.pk')->firstOrFail();
    expect($response->json('employee_ids'))->toHaveCount(2)
        ->and($ali->department->name)->toBe('Engineering')
        ->and($ali->designation->name)->toBe('Software Engineer')
        ->and($ali->work_mode->value)->toBe('hybrid')
        ->and($ali->status->value)->toBe('on_leave')
        ->and($ali->shift->name)->toBe('Morning')
        ->and($ali->code)->toStartWith('EMP-')
        ->and($ali->join_date->toDateString())->toBe('2025-02-01')
        ->and($ali->user->email)->toBe('ali.raza@company.pk')
        ->and(Employee::where('email', 'sara.raza@company.pk')->exists())->toBeTrue();

    Mail::assertQueued(EmployeeInvitation::class, 2);
    expect(Activity::query()->where('event', 'imported')->sole()->properties->get('count'))->toBe(2);
});

it('rejects the whole file with row-numbered errors and writes nothing', function () {
    Employee::factory()->create(['email' => 'taken@company.pk']);

    $response = postImport(importCsv([
        validRow('Ali', 'ali.raza@company.pk'),                                  // line 2 ok
        validRow('Bad', 'taken@company.pk', ['department' => 'Space Program']),   // line 3
        validRow('Dup', 'ali.raza@company.pk', ['join_date' => '31/12/2025']),    // line 4
        validRow('Des', 'des@company.pk', ['designation' => 'Accountant']),       // line 5 (Finance title, Engineering dept)
    ]))->assertStatus(422);

    $response->assertJsonPath('message', '3 of 4 rows have errors. Nothing was imported.')
        ->assertJsonValidationErrors([
            'rows.3.email', 'rows.3.department',
            'rows.4.email', 'rows.4.join_date',
            'rows.5.designation',
        ])
        ->assertJsonMissingValidationErrors(['rows.2.email', 'rows.3.designation']);

    expect($response->json('errors')['rows.4.email'][0])->toContain('line 2')
        ->and($response->json('errors')['rows.5.designation'][0])->toContain('does not exist in department');

    expect(Employee::count())->toBe(1);
    Mail::assertNothingQueued();
});

it('resolves reports_to from existing employee codes/emails and from other rows in the file', function () {
    $boss = Employee::factory()->create();

    postImport(importCsv([
        validRow('Lead', 'lead@company.pk', ['reports_to' => $boss->fresh()->code]),
        validRow('Dev', 'dev@company.pk', ['reports_to' => 'lead@company.pk']),
        validRow('Qa', 'qa@company.pk', ['reports_to' => $boss->email]),
    ]))->assertCreated();

    $lead = Employee::where('email', 'lead@company.pk')->first();
    expect($lead->reports_to_id)->toBe($boss->id)
        ->and(Employee::where('email', 'dev@company.pk')->value('reports_to_id'))->toBe($lead->id)
        ->and(Employee::where('email', 'qa@company.pk')->value('reports_to_id'))->toBe($boss->id);
});

it('rejects unknown managers and reporting cycles inside the file', function () {
    postImport(importCsv([
        validRow('A', 'a@company.pk', ['reports_to' => 'b@company.pk']),
        validRow('B', 'b@company.pk', ['reports_to' => 'a@company.pk']),
        validRow('C', 'c@company.pk', ['reports_to' => 'EMP-9999']),
        validRow('D', 'd@company.pk', ['reports_to' => 'd@company.pk']),
    ]))->assertStatus(422)
        ->assertJsonValidationErrors(['rows.2.reports_to', 'rows.3.reports_to', 'rows.4.reports_to', 'rows.5.reports_to']);

    expect(Employee::count())->toBe(0);
});

it('imports an xlsx file', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(IMPORT_HEADER));
    $row = validRow('Xl', 'xl@company.pk');
    $row[5] = new DateTimeImmutable('2024-06-30'); // unformatted date cell: read back as serial 45473
    $writer->addRow(Row::fromValues($row));
    $writer->close();

    postImport(new UploadedFile($path, 'staff.xlsx', null, null, true))->assertCreated()->assertJsonPath('imported', 1);

    $e = Employee::where('email', 'xl@company.pk')->firstOrFail();
    expect($e->join_date->toDateString())->toBe('2024-06-30');
});

it('accepts files that still carry the old salary and bank columns', function () {
    $header = [...IMPORT_HEADER, 'gross_salary', 'bank_name', 'iban'];
    $row = [...validRow('Old', 'old@company.pk'), '0', 'HBL', 'NOT-AN-IBAN'];

    postImport(importCsv([$row], $header))->assertCreated()->assertJsonPath('imported', 1);
});

it('rolls back every row if the database fails midway', function () {
    $calls = 0;
    Employee::created(function () use (&$calls) {
        if (++$calls === 2) {
            throw new RuntimeException('Simulated failure');
        }
    });

    postImport(importCsv([validRow('Ali', 'ali.raza@company.pk'), validRow('Sara', 'sara.raza@company.pk')]))
        ->assertStatus(500);

    expect(DB::table('employees')->count())->toBe(0)
        ->and(User::count())->toBe(1);
    Mail::assertNothingQueued();
});

it('validates the upload itself', function (Closure $file, string $message) {
    $this->post('/api/v1/employees/import', ['file' => $file()], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file'])
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, $message));
})->with([
    'missing file' => [fn () => null, 'required'],
    'wrong type' => [fn () => UploadedFile::fake()->create('staff.pdf', 10), 'extension'],
    'missing columns' => [fn () => importCsv([['Ali']], ['first_name']), 'Missing required columns'],
    'no rows' => [fn () => importCsv([]), 'no employee rows'],
    'corrupt xlsx' => [fn () => UploadedFile::fake()->createWithContent('bad.xlsx', 'not a zip'), 'could not be read'],
]);

it('caps the number of rows', function () {
    $rows = array_map(fn ($i) => validRow("E{$i}", "e{$i}@company.pk"), range(1, ImportEmployees::MAX_ROWS + 1));

    postImport(importCsv($rows), dryRun: true)
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'The file may not contain more than '.ImportEmployees::MAX_ROWS.' employee rows.');
});

it('requires authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/employees/import')->assertUnauthorized();
    $this->getJson('/api/v1/employees/import/template')->assertUnauthorized();
});
