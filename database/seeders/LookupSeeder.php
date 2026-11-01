<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Department;
use App\Models\Division;
use App\Models\Location;
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
    }
}
