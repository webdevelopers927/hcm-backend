<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Setup → Employment statuses.
 */
class EmploymentStatusController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', EmploymentStatus::class);

        $counts = Employee::query()->finalized()->whereNotNull('employment_status_id')
            ->groupBy('employment_status_id')->selectRaw('employment_status_id, count(*) as aggregate')
            ->pluck('aggregate', 'employment_status_id');

        $statuses = EmploymentStatus::query()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $statuses->map(fn (EmploymentStatus $s) => $this->row($s, (int) ($counts[$s->id] ?? 0)))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', EmploymentStatus::class);

        $data = $this->validated($request, null);
        $data['sort_order'] ??= (int) EmploymentStatus::query()->max('sort_order') + 1;
        $status = EmploymentStatus::create($data);

        return response()->json(['data' => $this->row($status->refresh(), 0)], 201);
    }

    public function show(EmploymentStatus $employmentStatus): JsonResponse
    {
        Gate::authorize('view', $employmentStatus);

        return response()->json(['data' => $this->row($employmentStatus, $this->usage($employmentStatus))]);
    }

    /** Turning "can log in" off signs out everyone currently in this status. */
    public function update(Request $request, EmploymentStatus $employmentStatus): JsonResponse
    {
        Gate::authorize('update', $employmentStatus);

        $data = $this->validated($request, $employmentStatus);

        DB::transaction(function () use ($employmentStatus, $data) {
            $loginTurnedOff = $employmentStatus->can_login && array_key_exists('can_login', $data) && ! $data['can_login'];
            $employmentStatus->update($data);

            if ($loginTurnedOff) {
                PersonalAccessToken::query()
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->whereIn('tokenable_id', Employee::query()->where('employment_status_id', $employmentStatus->id)->whereNotNull('user_id')->select('user_id'))
                    ->delete();
            }
        });

        return response()->json(['data' => $this->row($employmentStatus, $this->usage($employmentStatus))]);
    }

    /** Refused while any employee (including drafts and deleted employees) has it. */
    public function destroy(EmploymentStatus $employmentStatus): JsonResponse
    {
        Gate::authorize('delete', $employmentStatus);

        $usage = Employee::withTrashed()->where('employment_status_id', $employmentStatus->id)->count();
        if ($usage > 0) {
            return response()->json([
                'message' => "This status is assigned to {$usage} employee record(s). Move them to another status first.",
            ], 409);
        }

        $employmentStatus->delete();

        return response()->json(['message' => 'Employment status deleted.']);
    }

    /**
     * @return array{name: string, can_login?: bool, is_notice_period?: bool, sort_order?: int}
     */
    private function validated(Request $request, ?EmploymentStatus $status): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $present = $status ? 'sometimes' : 'required';

        /** @var array{name: string, can_login?: bool, is_notice_period?: bool, sort_order?: int} $data */
        $data = $request->validate([
            'name' => [$present, 'string', 'max:100', Rule::unique('employment_statuses', 'name')->ignore($status?->id)],
            'can_login' => [$present, 'boolean'],
            'is_notice_period' => [$present, 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], [], ['can_login' => 'can log in', 'is_notice_period' => 'notice period']);

        return $data;
    }

    private function usage(EmploymentStatus $status): int
    {
        return Employee::query()->finalized()->where('employment_status_id', $status->id)->count();
    }

    /**
     * @return array{id: int, name: string, can_login: bool, is_notice_period: bool, sort_order: int, employees_count: int}
     */
    private function row(EmploymentStatus $status, int $employees): array
    {
        return [...$status->toRef(), 'sort_order' => $status->sort_order, 'employees_count' => $employees];
    }
}
