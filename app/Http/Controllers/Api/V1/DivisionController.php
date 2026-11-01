<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DivisionController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Division::class);

        $divisions = Division::query()->orderBy('name')->get(['id', 'name']);
        $counts = Employee::query()->whereNotNull('division_id')
            ->groupBy('division_id')->selectRaw('division_id, count(*) as aggregate')
            ->pluck('aggregate', 'division_id');

        return response()->json(['data' => $divisions->map(fn (Division $d) => $this->row($d, (int) ($counts[$d->id] ?? 0)))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Division::class);

        $division = Division::create($this->validated($request, null));

        return response()->json(['data' => $this->row($division, 0)], 201);
    }

    public function show(Division $division): JsonResponse
    {
        Gate::authorize('view', $division);

        return response()->json(['data' => $this->row($division, $this->usage($division))]);
    }

    public function update(Request $request, Division $division): JsonResponse
    {
        Gate::authorize('update', $division);

        $division->update($this->validated($request, $division));

        return response()->json(['data' => $this->row($division, $this->usage($division))]);
    }

    /** Refused while employees (or drafts) use it, so nobody silently loses their division. */
    public function destroy(Division $division): JsonResponse
    {
        Gate::authorize('delete', $division);

        $usage = $this->usage($division);
        if ($usage > 0) {
            return response()->json([
                'message' => "This division is assigned to {$usage} employee(s). Move them to another division first.",
            ], 409);
        }

        $division->delete();

        return response()->json(['message' => 'Division deleted.']);
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?Division $division): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        /** @var array{name: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('divisions', 'name')->ignore($division?->id)],
        ]);

        return $data;
    }

    private function usage(Division $division): int
    {
        return Employee::query()->where('division_id', $division->id)->count();
    }

    /**
     * @return array{id: int, name: string, employees_count: int}
     */
    private function row(Division $division, int $employees): array
    {
        return ['id' => $division->id, 'name' => $division->name, 'employees_count' => $employees];
    }
}
