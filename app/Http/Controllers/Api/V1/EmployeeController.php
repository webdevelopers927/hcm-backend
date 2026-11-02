<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\ListEmployees;
use App\Actions\Employees\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\ListEmployeesRequest;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeCollection;
use App\Http\Resources\EmployeeDetailResource;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, ListEmployees $listEmployees): EmployeeCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $request->filters();

        return (new EmployeeCollection($listEmployees->handle($filters, $request->perPage())))
            ->additional(['status_counts' => $listEmployees->statusCounts($filters)]);
    }

    public function show(Employee $employee): EmployeeProfileResource
    {
        // Drafts have no profile yet; GET /employees/{id}/details serves them.
        abort_if($employee->is_draft, 404);
        Gate::authorize('view', $employee);

        return new EmployeeProfileResource($employee->load(EmployeeProfileResource::relations()));
    }

    /** One-shot hire: the payload must already be complete. */
    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        /** @var User $actor */
        $actor = $request->user();
        $employee = $createEmployee->handle($request->validated(), $actor);

        return (new EmployeeProfileResource($employee->fresh(EmployeeProfileResource::relations())))
            ->response()
            ->setStatusCode(201);
    }

    /** Drafts and real employees; returns the full form record. */
    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $updateEmployee): EmployeeDetailResource
    {
        Gate::authorize('update', $employee);

        /** @var User $actor */
        $actor = $request->user();
        $updateEmployee->handle($employee, $request->validated(), $actor);

        return new EmployeeDetailResource($employee->fresh(EmployeeDetailResource::relations()));
    }

    /** A real employee is soft deleted; a draft is discarded for good. */
    public function destroy(Request $request, Employee $employee, DeleteEmployee $deleteEmployee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        /** @var User $actor */
        $actor = $request->user();
        $draft = $employee->is_draft;
        $deleteEmployee->handle($actor, $employee, $request->ip());

        return response()->json(['message' => $draft ? 'Draft deleted.' : 'Employee deleted.']);
    }
}
