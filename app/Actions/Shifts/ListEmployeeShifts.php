<?php

namespace App\Actions\Shifts;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Real employees with the shift they work today, the shift before it and any scheduled change.
 *
 * Filters: shift_id (an id, or "unassigned"), search (name, code, department, division), sort.
 */
class ListEmployeeShifts
{
    public const SORTS = ['name', '-name', 'code', '-code', 'department', '-department', 'effective_from', '-effective_from'];

    /**
     * @param  array{search?: string|null, shift_id?: int|string|null, sort?: string|null}  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function handle(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->query($filters['search'] ?? null)
            ->select([
                'employees.id', 'employees.code', 'employees.first_name', 'employees.middle_name', 'employees.last_name',
                'employees.avatar_path', 'employees.department_id', 'employees.division_id', 'employees.shift_id',
            ])
            ->with(['department:id,name', 'division:id,name', 'shift:id,name,start_time,end_time,break_minutes']);

        $shift = $filters['shift_id'] ?? null;
        if ($shift === 'unassigned') {
            $query->whereNull('employees.shift_id');
        } elseif ($shift !== null && $shift !== '') {
            $query->where('employees.shift_id', (int) $shift);
        }

        $page = $this->sort($query, $filters['sort'] ?? null)->paginate($perPage);
        $this->attachHistory($page->getCollection());

        return $page;
    }

    /**
     * Every shift with its headcount, plus totals; honours the search but not the shift filter, so the
     * cards and tabs keep their numbers while a tab is selected.
     *
     * @return array{shifts: list<array<string, mixed>>, counts: array{all: int, unassigned: int}}
     */
    public function summary(?string $search): array
    {
        /** @var array<int|string, int> $byShift */
        $byShift = $this->query($search)->toBase()
            ->selectRaw('employees.shift_id, count(*) as aggregate')
            ->groupBy('employees.shift_id')
            ->pluck('aggregate', 'shift_id')
            ->all();

        $shifts = Shift::query()->orderBy('start_time')->get()->map(fn (Shift $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'start' => Shift::hhmm($s->start_time),
            'end' => Shift::hhmm($s->end_time),
            'break_minutes' => $s->break_minutes,
            'employees_count' => (int) ($byShift[$s->id] ?? 0),
        ])->all();

        return [
            'shifts' => $shifts,
            'counts' => ['all' => (int) array_sum($byShift), 'unassigned' => (int) ($byShift[''] ?? 0)],
        ];
    }

    /**
     * @return Builder<Employee>
     */
    private function query(?string $search): Builder
    {
        $query = Employee::query()->finalized();

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q
                ->where('employees.first_name', 'like', $like)
                ->orWhere('employees.last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', employees.first_name, employees.middle_name, employees.last_name) like ?", [$like])
                ->orWhere('employees.code', 'like', $like)
                ->orWhereHas('department', fn (Builder $d) => $d->where('name', 'like', $like))
                ->orWhereHas('division', fn (Builder $d) => $d->where('name', 'like', $like)));
        }

        return $query;
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function sort(Builder $query, ?string $sort): Builder
    {
        $sort = $sort ?: 'name';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (ltrim($sort, '-')) {
            'code' => $query->orderBy('employees.code', $direction),
            'department' => $query->orderBy(
                Department::query()->select('name')->whereColumn('departments.id', 'employees.department_id'),
                $direction,
            ),
            'effective_from' => $query->orderBy(
                EmployeeShiftAssignment::query()->selectRaw('max(effective_from)')
                    ->whereColumn('employee_shift_assignments.employee_id', 'employees.id')
                    ->whereNotNull('applied_at'),
                $direction,
            ),
            default => $query->orderBy('employees.first_name', $direction)->orderBy('employees.last_name', $direction),
        };

        return $query->orderBy('employees.id', $direction);
    }

    /**
     * Sets current_assignment, previous_shift and upcoming_assignment on each employee, from one query.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function attachHistory(Collection $employees): void
    {
        $history = EmployeeShiftAssignment::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->with('shift:id,name,start_time,end_time,break_minutes')
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id');

        foreach ($employees as $employee) {
            /** @var Collection<int, EmployeeShiftAssignment> $rows */
            $rows = $history->get($employee->id, collect());
            $applied = $rows->filter(fn (EmployeeShiftAssignment $a) => $a->applied_at !== null)->values();

            // The change that put the employee on their current shift (the first of a run of the same shift).
            $current = null;
            $previous = null;
            for ($i = $applied->count() - 1; $i >= 0; $i--) {
                $row = $applied[$i];
                if ($row->shift_id !== $employee->shift_id) {
                    $previous = $current ? $row : null;
                    break;
                }
                $current = $row;
            }

            $employee->setRelation('currentAssignment', $current);
            $employee->setRelation('previousAssignment', $previous);
            $employee->setRelation('upcomingAssignment', $rows->first(fn (EmployeeShiftAssignment $a) => $a->applied_at === null));
        }
    }
}
