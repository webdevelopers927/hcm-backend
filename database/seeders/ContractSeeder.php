<?php

namespace Database\Seeders;

use App\Actions\Contracts\ContractLifecycle;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Development data: a contract for every contractual employee who has none, cycling through the states
 * the Contracts page shows (active, expiring, expired, upcoming). Expired ones lock the employee out,
 * exactly as the daily job would.
 */
class ContractSeeder extends Seeder
{
    public function run(ContractLifecycle $lifecycle): void
    {
        $today = today();
        $patterns = [
            fn () => [$today->copy()->subMonths(10), $today->copy()->addMonths(8)],            // active
            fn () => [$today->copy()->subMonths(9), $today->copy()->addDays(10)],              // expiring
            fn () => [$today->copy()->subYear(), $today->copy()->subDays(34)],                 // expired
            fn () => [$today->copy()->subMonths(4), $today->copy()->addMonths(14)],            // active
            fn () => [$today->copy()->addDays(20), $today->copy()->addYear()],                 // upcoming
        ];

        $employees = Employee::query()->finalized()
            ->whereHas('dutyType', fn ($q) => $q->where('is_contractual', true))
            ->whereDoesntHave('contracts')
            ->orderBy('id')
            ->get(['id']);

        foreach ($employees->values() as $i => $employee) {
            [$start, $end] = $patterns[$i % count($patterns)]();
            Contract::create([
                'employee_id' => $employee->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'leave_entitled' => $i % 3 !== 2,
            ]);
            $lifecycle->sync($employee->id);
        }

        $this->command->info("contracts: {$employees->count()}");
    }
}
