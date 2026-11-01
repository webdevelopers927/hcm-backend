<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SubmitEmployeeDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Http\Resources\EmployeeDraftResource;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Drafts: the long employee form saved bit by bit. Create here, fill with PATCH /employees/{id},
 * read with GET /employees/{id}/details, then submit.
 */
class EmployeeDraftController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min((int) config('hcm.employees.per_page_max'), (int) ($validated['per_page'] ?? config('hcm.employees.per_page_default')));
        $search = trim((string) ($validated['search'] ?? ''));

        $drafts = Employee::query()->drafts()
            ->with(['department:id,name', 'designation:id,name', 'createdBy:id,name'])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                    ->orWhere('email', 'like', $like));
            })
            ->latest('updated_at')->orderByDesc('id')
            ->paginate($perPage);

        return EmployeeDraftResource::collection($drafts);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        /** @var User $actor */
        $actor = $request->user();
        $draft = $createEmployee->handle($request->validated(), $actor, asDraft: true);

        return (new EmployeeDetailResource($draft->fresh(EmployeeDetailResource::relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function submit(Request $request, Employee $employee, SubmitEmployeeDraft $submit): EmployeeProfileResource
    {
        Gate::authorize('create', Employee::class);
        abort_unless($employee->is_draft, 409, 'This draft has already been submitted.');

        /** @var User $actor */
        $actor = $request->user();
        $submit->handle($actor, $employee, $request->ip());

        return new EmployeeProfileResource($employee->fresh(EmployeeProfileResource::relations()));
    }
}
