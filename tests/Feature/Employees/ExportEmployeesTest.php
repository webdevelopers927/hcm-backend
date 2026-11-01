<?php

use App\Enums\EmployeeStatus;
use App\Models\Employee;

beforeEach(fn () => actingAsUser());

/** @return list<list<string>> */
function parseCsv(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = array_values(array_filter(preg_split('/\r?\n/', $content), fn ($l) => $l !== ''));

    return array_map(fn ($line) => str_getcsv($line), $lines);
}

it('streams a CSV with the agreed columns and no PII', function () {
    $employee = Employee::factory()->create([
        'cnic' => '35202-9999999-9', 'phone' => '+92 333-0001112', 'birth_date' => '1990-05-06',
    ]);

    $response = $this->postJson('/api/v1/employees/export', ['format' => 'csv'])->assertOk();
    $content = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=employees-');

    $rows = parseCsv($content);
    expect($rows[0])->toBe(['Code', 'Name', 'Email', 'Designation', 'Department', 'Status', 'Work mode', 'Shift', 'Location', 'Join date'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe($employee->fresh()->code)
        ->and($content)->not->toContain('35202-9999999-9')
        ->not->toContain('+92 333-0001112')
        ->not->toContain('1990-05-06');
});

it('applies list filters to the export', function () {
    Employee::factory()->count(2)->create(['status' => EmployeeStatus::Inactive]);
    Employee::factory()->count(3)->create(['status' => EmployeeStatus::Active]);

    $rows = parseCsv($this->postJson('/api/v1/employees/export', ['format' => 'csv', 'status' => ['inactive']])->streamedContent());

    expect($rows)->toHaveCount(3); // header + 2
});

it('exports only the selected ids when given', function () {
    $picked = Employee::factory()->count(2)->create();
    Employee::factory()->count(3)->create();

    $rows = parseCsv($this->postJson('/api/v1/employees/export', [
        'format' => 'csv',
        'ids' => $picked->pluck('id')->all(),
    ])->streamedContent());

    expect(collect($rows)->skip(1)->pluck(0)->sort()->values()->all())
        ->toBe($picked->map->fresh()->pluck('code')->sort()->values()->all());
});

it('streams a valid XLSX file', function () {
    Employee::factory()->count(2)->create();

    $response = $this->postJson('/api/v1/employees/export', ['format' => 'xlsx'])->assertOk();
    $content = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and(substr($content, 0, 2))->toBe('PK'); // XLSX is a zip archive

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    unlink($path);

    expect($sheet.$strings)->toContain('Join date')->not->toContain('cnic');
});

it('neutralises spreadsheet formulas in CSV cells', function () {
    Employee::factory()->create(['first_name' => '=HYPERLINK("x")', 'last_name' => 'Evil']);

    $rows = parseCsv($this->postJson('/api/v1/employees/export', ['format' => 'csv'])->streamedContent());

    expect($rows[1][1])->toStartWith("'=");
});

it('validates the export request', function (array $payload, string $field) {
    $this->postJson('/api/v1/employees/export', $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    [[], 'format'],
    [['format' => 'pdf'], 'format'],
    [['format' => 'csv', 'ids' => ['x']], 'ids.0'],
    [['format' => 'csv', 'status' => ['nope']], 'status.0'],
]);
