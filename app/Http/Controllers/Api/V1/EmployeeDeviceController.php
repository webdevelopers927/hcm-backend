<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\UnbindDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeDeviceController extends Controller
{
    /** Returns { data: device } or { data: null } when nothing is bound. */
    public function show(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $device = $employee->activeDevice;

        return response()->json(['data' => $device ? new DeviceResource($device) : null]);
    }

    public function unbind(Request $request, Employee $employee, UnbindDevice $unbindDevice): JsonResponse
    {
        Gate::authorize('unbindDevice', $employee);

        /** @var User $actor */
        $actor = $request->user();
        $unbindDevice->handle($actor, $employee, $request->ip());

        return response()->json(['message' => 'Device unbound.', 'device_bound' => false]);
    }
}
