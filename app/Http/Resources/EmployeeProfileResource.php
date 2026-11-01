<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * Profile = EmployeeListItem + contact, reporting line and employment details.
 * No CNIC: it comes only from GET /employees/{id}/sensitive.
 *
 * @mixin Employee
 */
class EmployeeProfileResource extends EmployeeListResource
{
    /**
     * Relations the profile needs; load them before building the resource.
     *
     * @return array<int|string, string|\Closure>
     */
    public static function relations(): array
    {
        return [
            'designation:id,name',
            'department:id,name',
            'shift:id,name,start_time,end_time',
            'campaign:id,name',
            'location:id,name',
            'user:id,two_factor_enabled',
            'activeDevice',
            'reportsTo:id,first_name,middle_name,last_name,avatar_path,designation_id',
            'reportsTo.designation:id,name',
            'directReports' => fn ($q) => $q->select(['id', 'first_name', 'middle_name', 'last_name', 'avatar_path', 'designation_id', 'reports_to_id'])
                ->where('is_draft', false)
                ->orderBy('first_name')->orderBy('last_name'),
            'directReports.designation:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return array_merge(parent::toArray($request), [
            'phone' => $employee->phone,
            'gender' => $employee->gender?->value,
            // Month and day only; the birth year is never exposed.
            'birthday' => $employee->birth_date?->format('m-d'),
            'reports_to' => $employee->reportsTo ? new EmployeeSummaryResource($employee->reportsTo) : null,
            'direct_reports' => EmployeeSummaryResource::collection($employee->directReports),
            'employment' => [
                'join_date' => $employee->join_date?->toDateString(),
                'status' => $employee->status?->value,
            ],
            // Placeholders until the attendance and leave modules exist; keys kept for a stable contract.
            'attendance_rate' => null,
            'leave_balances' => null,
        ]);
    }
}
