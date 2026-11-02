<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of GET /employee-shifts. Needs the relations set by ListEmployeeShifts.
 *
 * @mixin Employee
 */
class EmployeeShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;
        /** @var EmployeeShiftAssignment|null $current */
        $current = $employee->getRelation('currentAssignment');
        /** @var EmployeeShiftAssignment|null $previous */
        $previous = $employee->getRelation('previousAssignment');
        /** @var EmployeeShiftAssignment|null $upcoming */
        $upcoming = $employee->getRelation('upcomingAssignment');

        return [
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->full_name,
                'avatar_url' => $employee->avatar_url,
            ],
            'department' => $employee->department ? ['id' => $employee->department->id, 'name' => $employee->department->name] : null,
            'division' => $employee->division ? ['id' => $employee->division->id, 'name' => $employee->division->name] : null,
            'shift' => self::shift($employee->shift),
            'effective_from' => $current?->effective_from->toDateString(),
            'previous_shift' => $previous ? self::shift($previous->shift) : null,
            'upcoming' => $upcoming ? [
                'id' => $upcoming->id,
                'shift' => self::shift($upcoming->shift),
                'effective_from' => $upcoming->effective_from->toDateString(),
            ] : null,
        ];
    }

    /**
     * @return array{id: int, name: string, start: string|null, end: string|null, break_minutes: int}|null
     */
    public static function shift(?Shift $shift): ?array
    {
        return $shift ? [
            'id' => $shift->id,
            'name' => $shift->name,
            'start' => Shift::hhmm($shift->start_time),
            'end' => Shift::hhmm($shift->end_time),
            'break_minutes' => $shift->break_minutes,
        ] : null;
    }
}
