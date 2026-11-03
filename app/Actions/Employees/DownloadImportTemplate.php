<?php

namespace App\Actions\Employees;

use App\Models\Department;
use App\Models\DutyType;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\Shift;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadImportTemplate
{
    /**
     * CSV with every supported column (required ones marked *) and one example row
     * built from real lookup names so it imports cleanly after editing.
     */
    public function handle(): StreamedResponse
    {
        $department = Department::query()->with('designations:id,department_id,name')->orderBy('name')->first();

        $example = [
            'first_name' => 'Ayesha',
            'last_name' => 'Khan',
            'email' => 'ayesha.khan@company.pk',
            'phone' => '+92 300 1234567',
            'cnic' => '35202-1234567-1',
            'gender' => 'female',
            'birth_date' => '1995-04-18',
            'pseudo_name' => 'Ayesha',
            'machine_uid' => '',
            'department' => $department->name ?? '',
            'designation' => $department?->designations->first()->name ?? '',
            'division' => '',
            'campaign' => '',
            'shift' => Shift::query()->orderBy('id')->value('name') ?? '',
            'location' => Location::query()->orderBy('id')->value('name') ?? '',
            'reports_to' => '',
            'has_team_authority' => 'no',
            'work_mode' => 'onsite',
            'status' => EmploymentStatus::query()->orderBy('sort_order')->value('name') ?? '',
            'duty_type' => DutyType::query()->orderBy('sort_order')->value('name') ?? '',
            'join_date' => now()->toDateString(),
            'resigned_date' => '',
            'last_working_day' => '',
            'reason_for_resignation' => '',
        ];

        $headers = array_map(
            fn (string $c) => in_array($c, ImportEmployees::REQUIRED_COLUMNS, true) ? $c.'*' : $c,
            ImportEmployees::COLUMNS,
        );

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            fputcsv($out, array_map(fn (string $c) => $example[$c], ImportEmployees::COLUMNS));
            fclose($out);
        }, 'employee-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
