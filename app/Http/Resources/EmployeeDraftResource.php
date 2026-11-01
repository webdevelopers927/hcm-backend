<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of GET /employees/drafts. Any field may still be empty.
 *
 * @mixin Employee
 */
class EmployeeDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;
        $name = $employee->full_name;

        return [
            'id' => $employee->id,
            'name' => $name !== '' ? $name : null,
            'email' => $employee->email,
            'avatar_url' => $employee->avatar_url,
            'department' => EmployeeListResource::idName($employee->department),
            'designation' => EmployeeListResource::idName($employee->designation),
            'created_by' => $employee->createdBy ? ['id' => $employee->createdBy->id, 'name' => $employee->createdBy->name] : null,
            'created_at' => $employee->created_at->toIso8601String(),
            'updated_at' => $employee->updated_at->toIso8601String(),
        ];
    }
}
