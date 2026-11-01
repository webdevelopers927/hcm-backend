<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmployeeSpouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PII. Served only by GET /employees/{id}/sensitive, which writes an audit entry.
 *
 * @mixin Employee
 */
class EmployeeSensitiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return [
            'employee_id' => $employee->id,
            'cnic' => $employee->cnic,
            'passport_no' => $employee->passport_no,
            'driving_license_no' => $employee->driving_license_no,
            'spouses' => $employee->spouses->map(fn (EmployeeSpouse $s) => ['id' => $s->id, 'cnic' => $s->cnic])->all(),
        ];
    }
}
