<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shifts\AssignShift;
use App\Actions\Shifts\ListEmployeeShifts;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftResource;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Employees → Shift records: who works which shift, from when, and bulk shift changes.
 */
class EmployeeShiftController extends Controller
{
    public function index(Request $request, ListEmployeeShifts $list): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'shift_id' => ['nullable', Rule::anyOf([['integer'], [Rule::in(['unassigned'])]])],
            'sort' => ['nullable', Rule::in(ListEmployeeShifts::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min((int) config('hcm.employees.per_page_max'), (int) ($validated['per_page'] ?? config('hcm.employees.per_page_default')));

        return EmployeeShiftResource::collection($list->handle($validated, $perPage))
            ->additional($list->summary($validated['search'] ?? null));
    }

    /** Bulk "Change shift": the selected employees move to a shift from a date. */
    public function assign(Request $request, AssignShift $assign): JsonResponse
    {
        Gate::authorize('assignShift', Employee::class);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->where('is_draft', false)->whereNull('deleted_at')],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
        ], [], ['employee_ids.*' => 'employee', 'shift_id' => 'shift', 'effective_from' => 'effective from date']);

        /** @var User $actor */
        $actor = $request->user();
        /** @var list<int> $ids */
        $ids = array_map('intval', $validated['employee_ids']);
        $assign->handle($actor, $ids, (int) $validated['shift_id'], $validated['effective_from'], $request->ip());

        $scheduled = $validated['effective_from'] > today()->toDateString();
        $count = count($ids);

        return response()->json([
            'message' => $scheduled
                ? "Shift change scheduled for {$count} employee(s) from {$validated['effective_from']}."
                : "{$count} employee(s) moved to the new shift.",
            'assigned' => $count,
            'scheduled' => $scheduled,
        ]);
    }

    /** Cancels a change that hasn't started yet. */
    public function cancel(EmployeeShiftAssignment $assignment): JsonResponse
    {
        Gate::authorize('assignShift', Employee::class);

        if ($assignment->applied_at !== null) {
            return response()->json(['message' => 'This shift change has already taken effect and can\'t be cancelled.'], 409);
        }

        $assignment->delete();

        return response()->json(['message' => 'Scheduled shift change cancelled.']);
    }

    /** One employee's shift history, newest first. */
    public function history(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $rows = $employee->shiftAssignments()
            ->with(['shift:id,name,start_time,end_time,break_minutes', 'assignedBy:id,name'])
            ->get()
            ->reverse()
            ->values();

        $currentId = $rows->first(fn (EmployeeShiftAssignment $a) => $a->applied_at !== null)?->id;

        return response()->json(['data' => $rows->map(fn (EmployeeShiftAssignment $a) => [
            'id' => $a->id,
            'shift' => EmployeeShiftResource::shift($a->shift),
            'effective_from' => $a->effective_from->toDateString(),
            'status' => $a->applied_at === null ? 'upcoming' : ($a->id === $currentId ? 'current' : 'past'),
            'assigned_by' => $a->assignedBy ? ['id' => $a->assignedBy->id, 'name' => $a->assignedBy->name] : null,
            'created_at' => $a->created_at->toIso8601String(),
        ])]);
    }
}
