<?php

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Models\Designation;
use App\Models\Employee;
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

        $heads = $leadDesignations->take(4)->map(fn (Designation $d) => $make()->teamLead()->create([
            'designation_id' => $d->id,
            'department_id' => $d->department_id,
            'status' => EmployeeStatus::Active,
            'join_date' => fake()->dateTimeBetween('-8 years', '-5 years')->format('Y-m-d'),
        ]));

        $managers = collect(range(1, 12))->map(function (int $i) use ($make, $heads, $leadDesignations) {
            $designation = $leadDesignations[$i % $leadDesignations->count()];

            return $make()->teamLead()->create([
                'designation_id' => $designation->id,
                'department_id' => $designation->department_id,
                'reports_to_id' => $heads[$i % $heads->count()]->id,
                'status' => EmployeeStatus::Active,
            ]);
        });

        foreach (range(1, 48) as $i) {
            $factory = $make();
            if ($i % 3 === 0) {
                $factory = $factory->withDevice();
            }
            $attributes = ['reports_to_id' => $managers[$i % $managers->count()]->id];
            // Guarantee every status tab has someone, whatever the random mix.
            $attributes += match ($i) {
                5, 21, 37 => ['status' => EmployeeStatus::Inactive],
                10, 26 => ['status' => EmployeeStatus::NoticePeriod],
                15 => ['status' => EmployeeStatus::OnLeave],
                20 => ['status' => EmployeeStatus::Probation],
                default => [],
            };
            $factory->create($attributes);
        }
    }
}
