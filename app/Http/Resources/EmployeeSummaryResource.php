<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact person card used for reports_to / direct_reports.
 *
 * @mixin Employee
 */
class EmployeeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'designation' => EmployeeListResource::idName($employee->designation),
            'avatar_url' => $employee->avatar_url,
        ];
    }
}
