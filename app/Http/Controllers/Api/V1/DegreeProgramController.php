<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DegreeProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DegreeProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DegreeProgram::class);

        $search = trim((string) $request->query('search', ''));
        $programs = DegreeProgram::query()
            ->withCount('qualifications')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $programs->map(fn (DegreeProgram $p) => $this->row($p))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', DegreeProgram::class);

        $program = DegreeProgram::create($this->validated($request, null));
        $program->setAttribute('qualifications_count', 0);

        return response()->json(['data' => $this->row($program)], 201);
    }

    public function show(DegreeProgram $degreeProgram): JsonResponse
    {
        Gate::authorize('view', $degreeProgram);

        return response()->json(['data' => $this->row($degreeProgram->loadCount('qualifications'))]);
    }

    public function update(Request $request, DegreeProgram $degreeProgram): JsonResponse
    {
        Gate::authorize('update', $degreeProgram);

        $degreeProgram->update($this->validated($request, $degreeProgram));

        return response()->json(['data' => $this->row($degreeProgram->loadCount('qualifications'))]);
    }

    /** Refused while any qualification uses it. */
    public function destroy(DegreeProgram $degreeProgram): JsonResponse
    {
        Gate::authorize('delete', $degreeProgram);

        $usage = $degreeProgram->qualifications()->count();
        if ($usage > 0) {
            return response()->json([
                'message' => "This degree program is used by {$usage} qualification(s) and can't be deleted.",
            ], 409);
        }

        $degreeProgram->delete();

        return response()->json(['message' => 'Degree program deleted.']);
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?DegreeProgram $program): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        /** @var array{name: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('degree_programs', 'name')->ignore($program?->id)],
        ]);

        return $data;
    }

    /**
     * @return array{id: int, name: string, qualifications_count: int}
     */
    private function row(DegreeProgram $program): array
    {
        return [
            'id' => $program->id,
            'name' => $program->name,
            'qualifications_count' => (int) $program->getAttribute('qualifications_count'),
        ];
    }
}
