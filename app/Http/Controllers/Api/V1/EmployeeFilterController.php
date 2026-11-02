<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkMode;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmployeeFilterController extends Controller
{
    /**
     * Every dropdown option for the employee list, in one call.
     */
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        return response()->json(['data' => [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->orderBy('name')->get(['id', 'name', 'department_id']),
            'divisions' => Division::query()->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::query()->orderBy('start_time')->get(['id', 'name', 'start_time', 'end_time'])
                ->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'start' => Shift::hhmm($s->start_time),
                    'end' => Shift::hhmm($s->end_time),
                ]),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'employment_statuses' => EmploymentStatus::options(),
            'work_modes' => array_map(
                fn (WorkMode $m) => ['value' => $m->value, 'label' => $m->label()],
                WorkMode::cases(),
            ),
        ]]);
    }
}
