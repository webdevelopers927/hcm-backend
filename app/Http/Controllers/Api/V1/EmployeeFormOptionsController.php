<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BloodGroup;
use App\Enums\EmergencyContactRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\Religion;
use App\Enums\Salutation;
use App\Enums\WorkMode;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DegreeProgram;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\DutyType;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Location;
use App\Models\ProbationDuration;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmployeeFormOptionsController extends Controller
{
    /**
     * Every fixed dropdown of the employee form in one call. Countries, states, cities, nationalities and
     * managers are searched separately (GET /countries..., GET /employees?search=).
     */
    public function __invoke(): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        $option = fn (string $value, string $label) => ['value' => $value, 'label' => $label];

        return response()->json(['data' => [
            'salutations' => Salutation::options(),
            'genders' => array_map(fn (Gender $g) => $option($g->value, $g->label()), Gender::cases()),
            'marital_statuses' => MaritalStatus::options(),
            'religions' => Religion::options(),
            'blood_groups' => BloodGroup::options(),
            'employment_statuses' => EmploymentStatus::options(),
            'duty_types' => DutyType::options(),
            'work_modes' => array_map(fn (WorkMode $m) => $option($m->value, $m->label()), WorkMode::cases()),
            'emergency_contact_relations' => EmergencyContactRelation::options(),
            'probation_durations' => ProbationDuration::query()->orderBy('months')->get(['id', 'name', 'months']),
            'degree_programs' => DegreeProgram::query()->orderBy('name')->get(['id', 'name']),
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
        ]]);
    }
}
