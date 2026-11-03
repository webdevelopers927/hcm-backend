<?php

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    /**
     * @return array<int|string, string|\Closure>
     */
    public static function relations(): array
    {
        return [
            'employee' => fn ($q) => $q->withTrashed()->select([
                'id', 'code', 'first_name', 'middle_name', 'last_name', 'avatar_path', 'designation_id', 'department_id',
                'duty_type_id', 'employment_status_id',
            ]),
            'employee.designation:id,name',
            'employee.department:id,name',
            'employee.dutyType:id,name,is_contractual',
            'employee.employmentStatus:id,name,can_login,is_notice_period,is_exit',
            'createdBy:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Contract $contract */
        $contract = $this->resource;
        /** @var Employee $employee */
        $employee = $contract->employee;
        $today = today();
        $totalDays = max(1, (int) $contract->start_date->diffInDays($contract->end_date) + 1);
        $elapsed = (int) $contract->start_date->diffInDays($today, false) + 1;

        return [
            'id' => $contract->id,
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->full_name,
                'avatar_url' => $employee->avatar_url,
                'designation' => EmployeeListResource::idName($employee->designation),
                'department' => EmployeeListResource::idName($employee->department),
                'duty_type' => $employee->dutyType?->toRef(),
                'employment_status' => $employee->employmentStatus?->toRef(),
            ],
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date->toDateString(),
            // Negative once ended ("Ended 34d ago").
            'days_left' => (int) $today->diffInDays($contract->end_date, false),
            // Share of the contract period already elapsed, for the progress bar.
            'progress' => max(0, min(100, (int) round($elapsed / $totalDays * 100))),
            'status' => $contract->status($today),
            'renewable' => $contract->renewable($today),
            'leave_entitled' => $contract->leave_entitled,
            'notes' => $contract->notes,
            'renewed_from_id' => $contract->renewed_from_id,
            'renewed_at' => $contract->renewed_at?->toIso8601String(),
            'expired_at' => $contract->expired_at?->toIso8601String(),
            'created_by' => $contract->createdBy ? ['id' => $contract->createdBy->id, 'name' => $contract->createdBy->name] : null,
            'created_at' => $contract->created_at->toIso8601String(),
        ];
    }
}
