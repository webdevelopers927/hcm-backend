<?php

namespace App\Actions\Shifts;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes shift history and keeps employees.shift_id equal to the shift in force today.
 */
class AssignShift
{
    /**
     * Moves employees to a shift from a date (past, today or future). A second change for the same
     * employee on the same date replaces the first. Changes dated today or earlier apply at once;
     * later ones are applied by shifts:apply-scheduled on their date.
     *
     * @param  list<int>  $employeeIds
     */
    public function handle(?User $actor, array $employeeIds, int $shiftId, string $effectiveFrom, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $employeeIds, $shiftId, $effectiveFrom, $ip) {
            foreach ($employeeIds as $employeeId) {
                $this->record($employeeId, $shiftId, $effectiveFrom, $actor?->id);
            }

            $this->applyDue($employeeIds);

            if ($actor) {
                activity('employees')
                    ->causedBy($actor)
                    ->event('shift_assigned')
                    ->withProperties([
                        'ip' => $ip,
                        'shift_id' => $shiftId,
                        'effective_from' => $effectiveFrom,
                        'employee_ids' => $employeeIds,
                    ])
                    ->log('Shift assigned');
            }
        });
    }

    /**
     * One history row, not yet applied. Call applyDue() afterwards (or mark it applied yourself when
     * employees.shift_id already holds it).
     */
    public function record(int $employeeId, int $shiftId, string $effectiveFrom, ?int $assignedById = null, bool $alreadyApplied = false): void
    {
        EmployeeShiftAssignment::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'effective_from' => $effectiveFrom],
            ['shift_id' => $shiftId, 'assigned_by_id' => $assignedById, 'applied_at' => $alreadyApplied ? now() : null],
        );
    }

    /**
     * Applies every change that is due (effective today or earlier) and not applied yet. An employee
     * moves to their latest due shift only when that change is one of the newly applied ones, so a
     * back-dated entry older than the current shift doesn't undo it. Returns how many employees moved.
     *
     * @param  list<int>|null  $employeeIds  null = everyone
     */
    public function applyDue(?array $employeeIds = null, ?Carbon $today = null): int
    {
        $today ??= today();

        $due = EmployeeShiftAssignment::query()
            ->whereNull('applied_at')
            ->whereDate('effective_from', '<=', $today)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get(['id', 'employee_id', 'shift_id', 'effective_from']);

        if ($due->isEmpty()) {
            return 0;
        }

        // The change in force today for each affected employee, whether newly due or applied before.
        $latest = EmployeeShiftAssignment::query()
            ->whereIn('employee_id', $due->pluck('employee_id')->unique()->all())
            ->whereDate('effective_from', '<=', $today)
            ->orderBy('effective_from')
            ->get(['id', 'employee_id', 'shift_id'])
            ->keyBy('employee_id');

        $dueIds = $due->pluck('id')->flip();
        $moved = 0;
        foreach ($latest as $employeeId => $assignment) {
            if ($dueIds->has($assignment->id)) {
                $moved += Employee::query()->whereKey($employeeId)->update(['shift_id' => $assignment->shift_id]);
            }
        }

        EmployeeShiftAssignment::query()->whereIn('id', $due->pluck('id')->all())->update(['applied_at' => now()]);

        return $moved;
    }
}
