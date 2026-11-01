<?php

namespace App\Actions\Employees;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the filtered + sorted employee query shared by the list, status counts and export.
 *
 * Filter keys (all optional): search, status[], department_id[], designation_id[], division_id[],
 * campaign_id[], shift_id[], location_id[], work_mode[], ids[].
 */
class BuildEmployeeQuery
{
    /** Columns the list item / export need; never PII. */
    public const LIST_COLUMNS = [
        'employees.id', 'employees.user_id', 'employees.code', 'employees.machine_uid',
        'employees.first_name', 'employees.last_name', 'employees.pseudo_name', 'employees.email',
        'employees.avatar_path', 'employees.designation_id', 'employees.department_id',
        'employees.shift_id', 'employees.campaign_id', 'employees.location_id',
        'employees.status', 'employees.work_mode', 'employees.join_date',
    ];

    /** Filter key => column for the simple "IN (...)" filters. */
    private const IN_FILTERS = [
        'status' => 'employees.status',
        'department_id' => 'employees.department_id',
        'designation_id' => 'employees.designation_id',
        'division_id' => 'employees.division_id',
        'campaign_id' => 'employees.campaign_id',
        'shift_id' => 'employees.shift_id',
        'location_id' => 'employees.location_id',
        'work_mode' => 'employees.work_mode',
        'ids' => 'employees.id',
    ];

    public const SORTS = ['name', '-name', 'join_date', '-join_date', 'department', '-department', 'code', '-code'];

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $except  filter keys to ignore (status counts drop "status")
     * @return Builder<Employee>
     */
    public function handle(array $filters, array $except = []): Builder
    {
        $query = Employee::query();

        foreach (self::IN_FILTERS as $key => $column) {
            if (in_array($key, $except, true) || empty($filters[$key])) {
                continue;
            }
            $query->whereIn($column, (array) $filters[$key]);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '' && ! in_array('search', $except, true)) {
            $this->applySearch($query, $search);
        }

        return $query;
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function sort(Builder $query, ?string $sort): Builder
    {
        $sort = $sort ?: 'name';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (ltrim($sort, '-')) {
            'join_date' => $query->orderBy('employees.join_date', $direction),
            'code' => $query->orderBy('employees.code', $direction),
            'department' => $query->orderBy(
                Department::query()->select('name')->whereColumn('departments.id', 'employees.department_id'),
                $direction,
            ),
            default => $query->orderBy('employees.first_name', $direction)->orderBy('employees.last_name', $direction),
        };

        // Stable pagination when sort values tie.
        return $query->orderBy('employees.id', $direction);
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '\\%_').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('employees.first_name', 'like', $like)
                ->orWhere('employees.last_name', 'like', $like)
                ->orWhereRaw("CONCAT(employees.first_name, ' ', employees.last_name) like ?", [$like])
                ->orWhere('employees.pseudo_name', 'like', $like)
                ->orWhere('employees.email', 'like', $like)
                ->orWhere('employees.code', 'like', $like)
                ->orWhere('employees.machine_uid', 'like', $like)
                ->orWhereHas('designation', fn (Builder $d) => $d->where('name', 'like', $like));
        });
    }
}
