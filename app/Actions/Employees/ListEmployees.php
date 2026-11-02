<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmploymentStatus;
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
     * Counts per employment status using every current filter except the status ones, so the status
     * tabs stay correct while other filters are applied. One grouped query.
     *
     * @param  array<string, mixed>  $filters
     * @return array{all: int, on_notice: int, by_status: array<int, int>}
     */
    public function statusCounts(array $filters): array
    {
        /** @var array<int|string, int> $rows */
        $rows = $this->builder->handle($filters, except: ['employment_status_id', 'on_notice'])
            ->toBase()
            ->selectRaw('employees.employment_status_id, count(*) as aggregate')
            ->groupBy('employees.employment_status_id')
            ->pluck('aggregate', 'employment_status_id')
            ->all();

        $statuses = EmploymentStatus::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'is_notice_period']);
        $byStatus = [];
        $onNotice = 0;
        foreach ($statuses as $status) {
            $byStatus[$status->id] = (int) ($rows[$status->id] ?? 0);
            $onNotice += $status->is_notice_period ? $byStatus[$status->id] : 0;
        }

        return ['all' => (int) array_sum($rows), 'on_notice' => $onNotice, 'by_status' => $byStatus];
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
            'employmentStatus:id,name,can_login,is_notice_period',
        ];
    }
}
