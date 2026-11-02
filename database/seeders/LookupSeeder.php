<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\DegreeProgram;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\ProbationDuration;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class LookupSeeder extends Seeder
{
    /** Department => designations (first one is the lead role). */
    public const DEPARTMENTS = [
        'Engineering' => ['Engineering Manager', 'Senior Software Engineer', 'Software Engineer', 'QA Engineer', 'DevOps Engineer'],
        'Design' => ['Design Lead', 'Product Designer', 'UI Designer'],
        'Sales' => ['Sales Manager', 'Account Manager', 'Sales Executive'],
        'Customer Success' => ['Customer Success Manager', 'Support Team Lead', 'Support Agent'],
        'Finance' => ['Finance Manager', 'Accountant', 'Payroll Officer'],
        'People' => ['People Ops Lead', 'HR Generalist', 'Talent Acquisition Specialist'],
        'Operations' => ['Operations Manager', 'Operations Executive', 'Facilities Coordinator'],
        'Marketing' => ['Marketing Manager', 'Marketing Executive', 'Content Writer'],
    ];

    public const PROBATION_MONTHS = [1, 2, 3, 6, 12];

    /** Name => [can log in, is a notice period], in display order. */
    public const EMPLOYMENT_STATUSES = [
        'Active' => [true, false],
        'Probation' => [true, false],
        'On leave' => [true, false],
        'Notice period' => [true, true],
        'Serving notice (terminated)' => [true, true],
        'Suspended' => [false, false],
        'Resigned' => [false, false],
        'Terminated' => [false, false],
        'Retired' => [false, false],
        'Inactive' => [false, false],
    ];

    public const DEGREE_PROGRAMS = [
        'Matriculation', 'O Levels', 'Intermediate (FA)', 'Intermediate (FSc)', 'Intermediate (ICS)',
        'Intermediate (ICom)', 'A Levels', 'Diploma of Associate Engineering (DAE)', 'Associate Degree',
        'BA', 'BSc', 'BCom', 'BBA', 'BS Computer Science', 'BS Software Engineering', 'BS Information Technology',
        'BE / BSc Engineering', 'MBBS', 'LLB', 'MA', 'MSc', 'MCom', 'MBA', 'MCS', 'MS / MPhil', 'PhD',
        'ACCA', 'CA', 'Other',
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $department => $designations) {
            $dept = Department::query()->firstOrCreate(['name' => $department]);
            foreach ($designations as $designation) {
                $dept->designations()->firstOrCreate(['name' => $designation]);
            }
        }

        foreach (['Technology', 'Commercial', 'Corporate'] as $name) {
            Division::query()->firstOrCreate(['name' => $name]);
        }

        foreach (['Atlas', 'Orion', 'Nova', 'Zenith', 'Apex'] as $name) {
            Campaign::query()->firstOrCreate(['name' => $name]);
        }

        foreach (['Lahore', 'Karachi', 'Islamabad', 'Remote'] as $name) {
            Location::query()->firstOrCreate(['name' => $name]);
        }

        $shifts = [
            ['name' => 'Morning', 'start_time' => '09:00', 'end_time' => '18:00'],
            ['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '23:00'],
            ['name' => 'Night', 'start_time' => '21:00', 'end_time' => '06:00'],
            ['name' => 'Flexible', 'start_time' => '10:00', 'end_time' => '19:00'],
        ];
        foreach ($shifts as $shift) {
            Shift::query()->firstOrCreate(['name' => $shift['name']], $shift + ['break_minutes' => 60]);
        }

        foreach (self::PROBATION_MONTHS as $months) {
            ProbationDuration::query()->firstOrCreate(
                ['months' => $months],
                ['name' => $months === 1 ? '1 month' : "{$months} months"],
            );
        }

        foreach (self::DEGREE_PROGRAMS as $name) {
            DegreeProgram::query()->firstOrCreate(['name' => $name]);
        }

        // firstOrCreate: never overwrite flags an admin has changed since.
        $order = 0;
        foreach (self::EMPLOYMENT_STATUSES as $name => [$canLogin, $notice]) {
            EmploymentStatus::query()->firstOrCreate(
                ['name' => $name],
                ['can_login' => $canLogin, 'is_notice_period' => $notice, 'sort_order' => $order],
            );
            $order++;
        }
    }
}
