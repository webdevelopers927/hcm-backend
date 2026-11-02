<?php

namespace App\Actions\Employees;

use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Models\Campaign;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Support\FileBatch;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk create from a spreadsheet. Every row goes through the same rules as POST /employees.
 * All-or-nothing: one invalid row means nothing is written, and errors come back keyed
 * "rows.<line>.<column>" (line = spreadsheet line, header = 1).
 */
class ImportEmployees
{
    public const MAX_ROWS = 500;

    public const REQUIRED_COLUMNS = ['first_name', 'last_name', 'email', 'cnic', 'department', 'designation', 'status', 'duty_type', 'join_date'];

    public const COLUMNS = [
        'first_name', 'last_name', 'email', 'phone', 'cnic', 'gender', 'birth_date', 'pseudo_name', 'machine_uid',
        'department', 'designation', 'division', 'campaign', 'shift', 'location', 'reports_to', 'has_team_authority',
        'work_mode', 'status', 'duty_type', 'join_date',
    ];

    /** Validated id field => spreadsheet column, so errors point at the column the user typed. */
    private const ID_COLUMNS = [
        'department_id' => 'department', 'designation_id' => 'designation', 'division_id' => 'division',
        'campaign_id' => 'campaign', 'shift_id' => 'shift', 'location_id' => 'location', 'reports_to_id' => 'reports_to',
        'employment_status_id' => 'status',
    ];

    /** @var array<string, array<string, int>> lookup table => lowercase name => id */
    private array $lookups = [];

    public function __construct(
        private readonly ReadEmployeeImportFile $reader,
        private readonly CreateEmployee $createEmployee,
    ) {}

    /**
     * @return array{total_rows: int, employee_ids: list<int>}
     */
    public function handle(User $actor, string $path, string $extension, bool $dryRun, ?string $ip): array
    {
        $rows = $this->reader->handle($path, $extension, self::MAX_ROWS);
        $this->assertColumns($rows);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no employee rows.']);
        }

        [$validated, $deferredManagers] = $this->validateRows($rows);

        if ($dryRun) {
            return ['total_rows' => count($rows), 'employee_ids' => []];
        }

        $passwordHash = Hash::make(Str::random(64));

        $created = FileBatch::transaction(function (FileBatch $files) use ($validated, $deferredManagers, $passwordHash, $actor, $ip) {
            $created = [];
            foreach ($validated as $line => $data) {
                $created[$line] = $this->createEmployee->persist($data, $files, $actor, $passwordHash);
            }

            // Managers that are themselves new rows in this file, resolved once every row has an id.
            $byEmail = collect($created)->mapWithKeys(fn (array $pair) => [$pair[0]->email => $pair[0]->id]);
            foreach ($deferredManagers as $line => $managerEmail) {
                $created[$line][0]->forceFill(['reports_to_id' => $byEmail[$managerEmail]])->save();
            }

            activity('employees')
                ->causedBy($actor)
                ->event('imported')
                ->withProperties(['ip' => $ip, 'count' => count($created), 'employee_ids' => collect($created)->map(fn ($p) => $p[0]->id)->values()])
                ->log('Employees imported');

            return $created;
        });

        foreach ($created as [$employee, $user]) {
            $this->createEmployee->sendInvite($employee, $user);
        }

        return [
            'total_rows' => count($rows),
            'employee_ids' => array_values(array_map(fn (array $pair) => $pair[0]->id, $created)),
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function assertColumns(array $rows): void
    {
        $present = array_keys((array) reset($rows));
        $missing = array_diff(self::REQUIRED_COLUMNS, $present);

        if ($rows !== [] && $missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Missing required columns: '.implode(', ', $missing).'. Download the template for the expected headers.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} validated data per line, deferred manager email per line
     */
    private function validateRows(array $rows): array
    {
        $rules = (new StoreEmployeeRequest)->rules();
        $fileEmails = $this->fileEmails($rows);
        $errors = [];
        $validated = [];
        $deferred = [];
        $seen = ['email' => [], 'cnic' => [], 'machine_uid' => []];

        foreach ($rows as $line => $row) {
            [$data, $rowErrors, $managerEmail] = $this->mapRow($row, $fileEmails);

            foreach (['email', 'cnic', 'machine_uid'] as $unique) {
                $value = strtolower((string) ($data[$unique] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (isset($seen[$unique][$value])) {
                    $rowErrors[$unique][] = "Duplicate {$unique} in this file (also on line {$seen[$unique][$value]}).";
                }
                $seen[$unique][$value] ??= $line;
            }
            if ($managerEmail !== null && $managerEmail === strtolower((string) ($data['email'] ?? ''))) {
                $rowErrors['reports_to'][] = 'An employee cannot report to themselves.';
            }

            // Same field rules as POST /employees, plus everything a complete employee needs.
            $rowRules = $rules;
            foreach (EmployeeCompleteness::rules($data) as $field => $required) {
                $rowRules[$field] = [...$required, ...($rowRules[$field] ?? [])];
            }
            $validator = Validator::make($data, $rowRules, EmployeeCompleteness::messages(), $this->attributeNames());
            foreach ($validator->errors()->messages() as $field => $messages) {
                $column = self::ID_COLUMNS[$field] ?? $field;
                // A failed name lookup already explains the problem; don't add "field is required".
                // A bad department also makes the designation unresolvable, so skip that echo too.
                if (isset($rowErrors[$column]) || ($column === 'designation' && isset($rowErrors['department']))) {
                    continue;
                }
                $rowErrors[$column] = $messages;
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $column => $messages) {
                    $errors["rows.{$line}.{$column}"] = $messages;
                }

                continue;
            }

            $validated[$line] = $validator->validated();
            if ($managerEmail !== null) {
                $deferred[$line] = $managerEmail;
            }
        }

        foreach ($this->cyclicLines($validated, $deferred) as $line) {
            $errors["rows.{$line}.reports_to"] = ['Reporting cycle between rows in this file.'];
        }

        // A manager who is a new row in this file needs team authority too.
        $teamByEmail = [];
        foreach ($validated as $data) {
            $teamByEmail[strtolower((string) $data['email'])] = (bool) ($data['has_team_authority'] ?? false);
        }
        foreach ($deferred as $line => $managerEmail) {
            if (isset($teamByEmail[$managerEmail]) && ! $teamByEmail[$managerEmail]) {
                $errors["rows.{$line}.reports_to"] ??= ["{$managerEmail} does not have team authority (set has_team_authority to yes on their row)."];
            }
        }

        if ($errors !== []) {
            $invalidRows = count(array_unique(array_map(fn ($k) => explode('.', $k)[1], array_keys($errors))));
            ksort($errors, SORT_NATURAL);

            // Standard 422 shape with a summary message.
            throw new HttpResponseException(response()->json([
                'message' => "{$invalidRows} of ".count($rows).' rows have errors. Nothing was imported.',
                'errors' => $errors,
            ], 422));
        }

        return [$validated, $deferred];
    }

    /**
     * Lines whose in-file manager chain loops back to themselves (A -> B -> A).
     *
     * @param  array<int, array<string, mixed>>  $validated
     * @param  array<int, string>  $deferred
     * @return list<int>
     */
    private function cyclicLines(array $validated, array $deferred): array
    {
        $lineByEmail = [];
        foreach ($validated as $line => $data) {
            $lineByEmail[strtolower((string) $data['email'])] = $line;
        }

        $cyclic = [];
        foreach (array_keys($deferred) as $start) {
            $line = $start;
            $steps = 0;
            while (isset($deferred[$line]) && $steps++ <= count($deferred)) {
                $line = $lineByEmail[$deferred[$line]] ?? null;
                if ($line === null) {
                    break;
                }
                if ($line === $start) {
                    $cyclic[] = $start;
                    break;
                }
            }
        }

        return $cyclic;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, true>  $fileEmails
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: string|null}
     */
    private function mapRow(array $row, array $fileEmails): array
    {
        $errors = [];
        $value = fn (string $key): ?string => ($v = trim($row[$key] ?? '')) === '' ? null : $v;
        $enum = fn (string $key): ?string => ($v = $value($key)) === null ? null : (string) preg_replace('/[\s-]+/', '_', strtolower($v));

        $data = [
            'first_name' => $value('first_name'),
            'last_name' => $value('last_name'),
            'email' => ($v = $value('email')) === null ? null : strtolower($v),
            'phone' => $value('phone'),
            'cnic' => $value('cnic'),
            'gender' => $enum('gender'),
            'birth_date' => self::excelDate($value('birth_date')),
            'pseudo_name' => $value('pseudo_name'),
            'machine_uid' => $value('machine_uid'),
            'work_mode' => $enum('work_mode'),
            'duty_type' => $enum('duty_type'),
            'has_team_authority' => self::yesNo($value('has_team_authority')),
            'join_date' => self::excelDate($value('join_date')),
        ];

        $department = $value('department');
        $data['department_id'] = $this->lookup('departments', $department, $errors, 'department');

        $designation = $value('designation');
        if ($designation !== null && $data['department_id'] !== null) {
            $data['designation_id'] = $this->lookup('designations:'.$data['department_id'], $designation, $errors, 'designation',
                "Designation \"{$designation}\" does not exist in department \"{$department}\".");
        } else {
            $data['designation_id'] = null;
        }

        foreach (['division' => 'divisions', 'campaign' => 'campaigns', 'shift' => 'shifts', 'location' => 'locations'] as $column => $table) {
            $data[$column.'_id'] = $this->lookup($table, $value($column), $errors, $column);
        }

        // Employment status by name ("On leave"; "on_leave" from older templates also matches).
        $status = $value('status');
        $data['employment_status_id'] = $this->lookup('employment_statuses', $status === null ? null : str_replace('_', ' ', $status), $errors, 'status',
            $status === null ? null : "Employment status \"{$status}\" does not exist.");

        // reports_to: an existing employee's code or email, or the email of another row in this file.
        $managerEmail = null;
        $data['reports_to_id'] = null;
        if (($ref = $value('reports_to')) !== null) {
            $existing = Employee::query()->finalized()
                ->where(fn ($q) => $q->where('code', $ref)->orWhere('email', strtolower($ref)))
                ->value('id');
            if ($existing !== null) {
                $data['reports_to_id'] = $existing;
            } elseif (isset($fileEmails[strtolower($ref)])) {
                $managerEmail = strtolower($ref);
            } else {
                $errors['reports_to'][] = "No employee with code or email \"{$ref}\".";
            }
        }

        return [$data, $errors, $managerEmail];
    }

    /** "Yes"/"No" (also true/false, y/n, 1/0); anything else passes through and fails the boolean rule. */
    private static function yesNo(?string $value): bool|string|null
    {
        return match (strtolower((string) $value)) {
            '' => null,
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => $value,
        };
    }

    /**
     * Unformatted Excel date cells arrive as serial day numbers (45473 = 2024-06-30); anything else
     * passes through for normal Y-m-d validation.
     */
    private static function excelDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/^\d{5}$/', $value) === 1) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        return $value;
    }

    /**
     * Case-insensitive name -> id. Null input stays null (optional column left blank).
     *
     * @param  array<string, list<string>>  $errors
     */
    private function lookup(string $table, ?string $name, array &$errors, string $column, ?string $message = null): ?int
    {
        if ($name === null) {
            return null;
        }

        $map = $this->lookups[$table] ??= $this->loadLookup($table);
        $id = $map[strtolower($name)] ?? null;

        if ($id === null) {
            $errors[$column][] = $message ?? ucfirst($column)." \"{$name}\" does not exist.";
        }

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function loadLookup(string $table): array
    {
        /** @var Collection<int, object{id: int, name: string}> $rows */
        $rows = match (true) {
            str_starts_with($table, 'designations:') => Designation::query()
                ->where('department_id', (int) substr($table, strlen('designations:')))->get(['id', 'name']),
            $table === 'departments' => Department::query()->get(['id', 'name']),
            $table === 'divisions' => Division::query()->get(['id', 'name']),
            $table === 'campaigns' => Campaign::query()->get(['id', 'name']),
            $table === 'shifts' => Shift::query()->get(['id', 'name']),
            $table === 'employment_statuses' => EmploymentStatus::query()->get(['id', 'name']),
            default => Location::query()->get(['id', 'name']),
        };

        return $rows->mapWithKeys(fn ($r) => [strtolower($r->name) => $r->id])->all();
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, true>
     */
    private function fileEmails(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            if (($email = strtolower(trim($row['email'] ?? ''))) !== '') {
                $emails[$email] = true;
            }
        }

        return $emails;
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return array_combine(array_keys(self::ID_COLUMNS), array_values(self::ID_COLUMNS));
    }
}
