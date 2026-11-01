<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UnbindDevice
{
    public function handle(User $actor, Employee $employee, ?string $ip): void
    {
        $device = $employee->activeDevice;

        if (! $device) {
            throw ValidationException::withMessages(['device' => 'No device is bound to this employee.']);
        }

        // Kept as history rather than deleted.
        $device->forceFill(['unbound_at' => now()])->save();

        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('device_unbound')
            ->withProperties(['ip' => $ip, 'employee_device_id' => $device->id])
            ->log('Attendance device unbound');
    }
}
