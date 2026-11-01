<?php

namespace App\Actions\Employees;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployees
{
    public function __construct(private readonly BuildEmployeeQuery $builder) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function handle(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->builder->handle($filters)
            ->select(BuildEmployeeQuery::LIST_COLUMNS)
            ->with(self::listRelations())
            ->withExists('activeDevice as device_bound');

        return $this->builder->sort($query, $filters['sort'] ?? null)->paginate($perPage);
    }

    /**
     * Counts per status using every current filter except status itself, so the status tabs
     * stay correct while other filters are applied. One grouped query.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(array $filters): array
    {
        /** @var array<string, int> $rows */
        $rows = $this->builder->handle($filters, except: ['status'])
            ->toBase()
            ->selectRaw('employees.status, count(*) as aggregate')
            ->groupBy('employees.status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = ['all' => (int) array_sum($rows)];
        foreach (EmployeeStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<int, string>
     */
    public static function listRelations(): array
    {
        return [
            'designation:id,name',
            'department:id,name',
            'shift:id,name,start_time,end_time',
            'campaign:id,name',
            'location:id,name',
            'user:id,two_factor_enabled',
        ];
    }
}
