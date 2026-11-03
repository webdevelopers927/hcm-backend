<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DutyType;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Setup → Duty types.
 */
class DutyTypeController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', DutyType::class);

        $counts = Employee::query()->finalized()->whereNotNull('duty_type_id')
            ->groupBy('duty_type_id')->selectRaw('duty_type_id, count(*) as aggregate')
            ->pluck('aggregate', 'duty_type_id');

        $types = DutyType::query()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $types->map(fn (DutyType $t) => $this->row($t, (int) ($counts[$t->id] ?? 0)))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', DutyType::class);

        $data = $this->validated($request, null);
        $data['sort_order'] ??= (int) DutyType::query()->max('sort_order') + 1;
        $type = DutyType::create($data);

        return response()->json(['data' => $this->row($type->refresh(), 0)], 201);
    }

    public function show(DutyType $dutyType): JsonResponse
    {
        Gate::authorize('view', $dutyType);

        return response()->json(['data' => $this->row($dutyType, $this->usage($dutyType))]);
    }

    public function update(Request $request, DutyType $dutyType): JsonResponse
    {
        Gate::authorize('update', $dutyType);

        $dutyType->update($this->validated($request, $dutyType));

        return response()->json(['data' => $this->row($dutyType, $this->usage($dutyType))]);
    }

    /** Refused while any employee record (including drafts and deleted employees) has it. */
    public function destroy(DutyType $dutyType): JsonResponse
    {
        Gate::authorize('delete', $dutyType);

        $usage = Employee::withTrashed()->where('duty_type_id', $dutyType->id)->count();
        if ($usage > 0) {
            return response()->json([
                'message' => "This duty type is assigned to {$usage} employee record(s). Move them to another duty type first.",
            ], 409);
        }

        $dutyType->delete();

        return response()->json(['message' => 'Duty type deleted.']);
    }

    /**
     * @return array{name?: string, is_contractual?: bool, sort_order?: int}
     */
    private function validated(Request $request, ?DutyType $type): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $present = $type ? 'sometimes' : 'required';

        /** @var array{name?: string, is_contractual?: bool, sort_order?: int} $data */
        $data = $request->validate([
            'name' => [$present, 'string', 'max:100', Rule::unique('duty_types', 'name')->ignore($type?->id)],
            'is_contractual' => [$present, 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], [], ['is_contractual' => 'contractual']);

        return $data;
    }

    private function usage(DutyType $type): int
    {
        return Employee::query()->finalized()->where('duty_type_id', $type->id)->count();
    }

    /**
     * @return array{id: int, name: string, is_contractual: bool, sort_order: int, employees_count: int}
     */
    private function row(DutyType $type, int $employees): array
    {
        return [...$type->toRef(), 'sort_order' => $type->sort_order, 'employees_count' => $employees];
    }
}
