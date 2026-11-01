<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeDetailResource;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;

class EmployeeDetailController extends Controller
{
    /**
     * The whole form record (draft or real employee), for the create/edit wizard.
     */
    public function __invoke(Employee $employee): EmployeeDetailResource
    {
        Gate::authorize('update', $employee);

        return new EmployeeDetailResource($employee->load(EmployeeDetailResource::relations()));
    }
}
