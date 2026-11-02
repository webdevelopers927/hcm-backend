<?php

namespace Database\Seeders;

use App\Models\Designation;
use App\Models\Employee;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Seeder;

/**
 * 64 employees in a 3-level reporting tree: 4 heads -> 12 managers -> 48 staff.
 */
class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $leadDesignations = Designation::query()
            ->whereIn('name', array_map(fn (array $d) => $d[0], LookupSeeder::DEPARTMENTS))
            ->get();

        $make = fn () => Employee::factory()->withAccount();
        $active = EmployeeFactory::statusId('Active');

        $heads = $leadDesignations->take(4)->map(fn (Designation $d) => $make()->teamLead()->create([
            'designation_id' => $d->id,
            'department_id' => $d->department_id,
            'employment_status_id' => $active,
            'join_date' => fake()->dateTimeBetween('-8 years', '-5 years')->format('Y-m-d'),
        ]));

        $managers = collect(range(1, 12))->map(function (int $i) use ($make, $heads, $leadDesignations, $active) {
            $designation = $leadDesignations[$i % $leadDesignations->count()];

            return $make()->teamLead()->create([
                'designation_id' => $designation->id,
                'department_id' => $designation->department_id,
                'reports_to_id' => $heads[$i % $heads->count()]->id,
                'employment_status_id' => $active,
            ]);
        });

        foreach (range(1, 48) as $i) {
            $factory = $make();
            if ($i % 3 === 0) {
                $factory = $factory->withDevice();
            }
            // Guarantee most status tabs have someone, whatever the random mix.
            $status = match ($i) {
                5, 37 => 'Inactive',
                10, 26 => 'Notice period',
                15 => 'On leave',
                20 => 'Probation',
                21 => 'Resigned',
                30 => 'Suspended',
                42 => 'Serving notice (terminated)',
                default => null,
            };
            if ($status !== null) {
                $factory = $factory->inStatus($status);
            }
            $factory->create(['reports_to_id' => $managers[$i % $managers->count()]->id]);
        }
    }
}
