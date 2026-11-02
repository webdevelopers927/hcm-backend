<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Offboarding\OffboardingWorkflow;
use App\Enums\ExitType;
use App\Enums\OffboardingStage;
use App\Http\Controllers\Controller;
use App\Http\Resources\OffboardingResource;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\OffboardingChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Employees → Off-boarding: cases, stages, checklist.
 */
class OffboardingController extends Controller
{
    /** Completed cases stay on the board for this many days. */
    private const COMPLETED_VISIBLE_DAYS = 90;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Offboarding::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::enum(OffboardingStage::class)],
            'exit_type' => ['nullable', Rule::enum(ExitType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min(100, (int) ($validated['per_page'] ?? 50));

        $cases = $this->visible($validated['search'] ?? null)
            ->when($validated['stage'] ?? null, fn (Builder $q, string $stage) => $q->where('offboardings.stage', $stage))
            ->when($validated['exit_type'] ?? null, fn (Builder $q, string $type) => $q->where('offboardings.exit_type', $type))
            ->with(OffboardingResource::relations(withChecklist: false))
            ->withCount([
                'checklist as checklist_total',
                'checklist as checklist_done' => fn ($q) => $q->whereNotNull('done_at'),
            ])
            ->orderByRaw('offboardings.completed_at is not null')
            ->orderBy(Employee::withTrashed()->select('last_working_day')->whereColumn('employees.id', 'offboardings.employee_id'))
            ->orderBy('offboardings.id')
            ->paginate($perPage);

        return OffboardingResource::collection($cases)->additional(['summary' => $this->summary($validated['search'] ?? null)]);
    }

    public function store(Request $request, OffboardingWorkflow $workflow): JsonResponse
    {
        Gate::authorize('create', Offboarding::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_draft', false)->whereNull('deleted_at')],
            'exit_type' => ['required', Rule::enum(ExitType::class)],
            'resigned_date' => ['required', 'date_format:Y-m-d'],
            'last_working_day' => ['required', 'date_format:Y-m-d', 'after_or_equal:resigned_date'],
            'reason_for_resignation' => ['required', 'string', 'max:2000'],
            'notice_employment_status_id' => ['nullable', 'integer', Rule::exists('employment_statuses', 'id')->where('is_notice_period', true)],
            'final_employment_status_id' => ['nullable', 'integer', Rule::exists('employment_statuses', 'id')],
            'stage' => ['nullable', Rule::enum(OffboardingStage::class), Rule::notIn([OffboardingStage::Completed->value])],
        ], [
            'notice_employment_status_id.exists' => 'Choose an employment status marked as a notice period.',
        ], $this->attributeNames());

        /** @var Employee $employee */
        $employee = Employee::query()->findOrFail($data['employee_id']);
        /** @var User $actor */
        $actor = $request->user();
        $case = $workflow->start($actor, $employee, $data, $request->ip());

        return (new OffboardingResource($case->load(OffboardingResource::relations(withChecklist: true))))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Offboarding $offboarding): OffboardingResource
    {
        Gate::authorize('view', $offboarding);

        return new OffboardingResource($offboarding->load(OffboardingResource::relations(withChecklist: true)));
    }

    /** Edit the case and its exit details. Use /complete to finish and DELETE to cancel. */
    public function update(Request $request, Offboarding $offboarding): OffboardingResource
    {
        Gate::authorize('update', $offboarding);
        abort_if($offboarding->completed_at !== null, 409, 'This case is completed.');

        $employee = Employee::withTrashed()->findOrFail($offboarding->employee_id);
        $data = $request->validate([
            'exit_type' => ['sometimes', Rule::enum(ExitType::class)],
            'stage' => ['sometimes', Rule::enum(OffboardingStage::class), Rule::notIn([OffboardingStage::Completed->value])],
            'final_employment_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employment_statuses', 'id')],
            'resigned_date' => ['sometimes', 'date_format:Y-m-d'],
            'last_working_day' => ['sometimes', 'date_format:Y-m-d'],
            'reason_for_resignation' => ['sometimes', 'string', 'max:2000'],
        ], ['stage.not_in' => 'Use the complete action to finish the case.'], $this->attributeNames());

        $resigned = $data['resigned_date'] ?? $employee->resigned_date?->toDateString();
        $lwd = $data['last_working_day'] ?? $employee->last_working_day?->toDateString();
        if ($resigned && $lwd && $lwd < $resigned) {
            abort(response()->json([
                'message' => 'The last working day cannot be before the resigned date.',
                'errors' => ['last_working_day' => ['The last working day cannot be before the resigned date.']],
            ], 422));
        }

        DB::transaction(function () use ($offboarding, $employee, $data) {
            $offboarding->fill(array_intersect_key($data, array_flip(['exit_type', 'stage', 'final_employment_status_id'])))->save();
            $employee->forceFill(array_intersect_key($data, array_flip(['resigned_date', 'last_working_day', 'reason_for_resignation'])))->save();
        });

        return new OffboardingResource($offboarding->fresh(OffboardingResource::relations(withChecklist: true)));
    }

    public function complete(Request $request, Offboarding $offboarding, OffboardingWorkflow $workflow): OffboardingResource
    {
        Gate::authorize('update', $offboarding);
        abort_if($offboarding->completed_at !== null, 409, 'This case is already completed.');

        /** @var User $actor */
        $actor = $request->user();
        $workflow->complete($actor, $offboarding, $request->ip());

        return new OffboardingResource($offboarding->fresh(OffboardingResource::relations(withChecklist: true)));
    }

    /** Cancel (e.g. resignation withdrawn): the employee gets their previous status back. */
    public function destroy(Request $request, Offboarding $offboarding, OffboardingWorkflow $workflow): JsonResponse
    {
        Gate::authorize('delete', $offboarding);

        /** @var User $actor */
        $actor = $request->user();
        $workflow->cancel($actor, $offboarding, $request->ip());

        return response()->json(['message' => 'Offboarding cancelled.']);
    }

    public function addItem(Request $request, Offboarding $offboarding): JsonResponse
    {
        Gate::authorize('update', $offboarding);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:200'],
            'owner' => ['nullable', 'string', 'max:50'],
        ]);
        $offboarding->checklist()->create($data + ['sort_order' => (int) $offboarding->checklist()->max('sort_order') + 1]);

        return (new OffboardingResource($offboarding->fresh(OffboardingResource::relations(withChecklist: true))))->response()->setStatusCode(201);
    }

    /** Tick/untick (`done`), rename (`label`) or reassign (`owner`) a checklist item. */
    public function updateItem(Request $request, Offboarding $offboarding, OffboardingChecklistItem $item): OffboardingResource
    {
        Gate::authorize('update', $offboarding);
        abort_if($offboarding->completed_at !== null, 409, 'This case is completed.');

        $data = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'string', 'max:200'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $item->fill(array_intersect_key($data, array_flip(['label', 'owner'])));
        if (array_key_exists('done', $data)) {
            $item->done_at = $data['done'] ? ($item->done_at ?? now()) : null;
            $item->done_by_id = $data['done'] ? ($item->done_by_id ?? $request->user()?->getAuthIdentifier()) : null;
        }
        $item->save();

        return new OffboardingResource($offboarding->fresh(OffboardingResource::relations(withChecklist: true)));
    }

    public function removeItem(Offboarding $offboarding, OffboardingChecklistItem $item): OffboardingResource
    {
        Gate::authorize('update', $offboarding);
        abort_if($offboarding->completed_at !== null, 409, 'This case is completed.');

        $item->delete();

        return new OffboardingResource($offboarding->fresh(OffboardingResource::relations(withChecklist: true)));
    }

    /**
     * Open cases plus those completed recently, for real employees (deleted ones included: they left).
     *
     * @return Builder<Offboarding>
     */
    private function visible(?string $search): Builder
    {
        $query = Offboarding::query()
            ->where(fn (Builder $q) => $q->whereNull('offboardings.completed_at')
                ->orWhere('offboardings.completed_at', '>=', now()->subDays(self::COMPLETED_VISIBLE_DAYS)));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->whereHas('employee', fn (Builder $e) => $e->withTrashed()->where(fn (Builder $w) => $w
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                ->orWhere('code', 'like', $like)));
        }

        return $query;
    }

    /**
     * Stat strip and board column counts.
     *
     * @return array<string, mixed>
     */
    private function summary(?string $search): array
    {
        $byStage = $this->visible($search)->toBase()
            ->selectRaw('stage, count(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage');

        $leavingSoon = $this->visible($search)->open()
            ->whereHas('employee', fn (Builder $e) => $e->withTrashed()->whereBetween('last_working_day', [today(), today()->addDays(30)]))
            ->count();

        return [
            'in_progress' => $this->visible($search)->open()->count(),
            'leaving_in_30_days' => $leavingSoon,
            'pending_clearance' => (int) ($byStage[OffboardingStage::Clearance->value] ?? 0),
            'completed' => (int) ($byStage[OffboardingStage::Completed->value] ?? 0),
            'by_stage' => collect(OffboardingStage::cases())->mapWithKeys(fn (OffboardingStage $s) => [$s->value => (int) ($byStage[$s->value] ?? 0)]),
            'stages' => OffboardingStage::options(),
            'exit_types' => ExitType::options(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return [
            'employee_id' => 'employee',
            'exit_type' => 'exit type',
            'resigned_date' => 'resigned date',
            'last_working_day' => 'last working day',
            'reason_for_resignation' => 'reason for resignation',
            'notice_employment_status_id' => 'notice period status',
            'final_employment_status_id' => 'status after leaving',
        ];
    }
}
