<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeListItem. Must never contain CNIC or phone.
 *
 * @mixin Employee
 */
class EmployeeListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;
        /** @var User $viewer */
        $viewer = $request->user();

        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'machine_uid' => $employee->machine_uid,
            'name' => $employee->full_name,
            'pseudo_name' => $employee->pseudo_name,
            'email' => $employee->email,
            'avatar_url' => $employee->avatar_url,
            'designation' => self::idName($employee->designation),
            'department' => self::idName($employee->department),
            'status' => $employee->status->value,
            'work_mode' => $employee->work_mode->value,
            'shift' => $employee->shift ? [
                'id' => $employee->shift->id,
                'name' => $employee->shift->name,
                'start' => Shift::hhmm($employee->shift->start_time),
                'end' => Shift::hhmm($employee->shift->end_time),
            ] : null,
            'campaign' => self::idName($employee->campaign),
            'location' => self::idName($employee->location),
            'join_date' => $employee->join_date->toDateString(),
            'two_factor_enabled' => (bool) $employee->user?->two_factor_enabled,
            'device_bound' => $this->deviceBound($employee),
            'can' => [
                'update' => $viewer->can('update', $employee),
                'delete' => $viewer->can('delete', $employee),
                'reset_2fa' => $viewer->can('resetTwoFactor', $employee),
                'unbind_device' => $viewer->can('unbindDevice', $employee),
            ],
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public static function idName(Department|Designation|Campaign|Location|null $model): ?array
    {
        return $model ? ['id' => $model->id, 'name' => $model->name] : null;
    }

    /** Uses the withExists() column when present (list), otherwise the relation (profile). */
    private function deviceBound(Employee $employee): bool
    {
        if (array_key_exists('device_bound', $employee->getAttributes())) {
            return (bool) $employee->getAttribute('device_bound');
        }

        return $employee->activeDevice !== null;
    }
}
