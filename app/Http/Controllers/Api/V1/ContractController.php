<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contracts\ContractLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Employees → Contracts.
 */
class ContractController extends Controller
{
    private const STATUSES = ['active', 'expiring', 'expired', 'upcoming', 'renewed'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Contract::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'employee_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min((int) config('hcm.employees.per_page_max'), (int) ($validated['per_page'] ?? config('hcm.employees.per_page_default')));
        $employeeId = $validated['employee_id'] ?? null;

        // An employee's own history includes renewed contracts; the main list shows current ones only.
        $query = $this->base($validated['search'] ?? null, includeRenewed: $employeeId !== null || ($validated['status'] ?? null) === 'renewed')
            ->when($employeeId, fn (Builder $q, int $id) => $q->where('contracts.employee_id', $id));
        if ($status = $validated['status'] ?? null) {
            $this->whereStatus($query, $status);
        }

        $contracts = $query->with(ContractResource::relations())
            ->orderBy('contracts.end_date')->orderBy('contracts.id')
            ->paginate($perPage);

        return ContractResource::collection($contracts)->additional(['summary' => $this->summary($validated['search'] ?? null)]);
    }

    /** Contractual employees for the "New contract" picker. */
    public function eligibleEmployees(Request $request): JsonResponse
    {
        Gate::authorize('create', Contract::class);

        $search = trim((string) $request->query('search', ''));
        $employees = Employee::query()->finalized()
            ->whereHas('dutyType', fn (Builder $q) => $q->where('is_contractual', true))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn (Builder $w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                    ->orWhere('code', 'like', $like));
            })
            ->with(['designation:id,name', 'dutyType:id,name,is_contractual'])
            ->withExists(['contracts as has_current_contract' => fn ($q) => $q->whereNull('renewed_at')->whereDate('end_date', '>=', today())])
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(50)
            ->get(['id', 'code', 'first_name', 'middle_name', 'last_name', 'avatar_path', 'designation_id', 'duty_type_id']);

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'code' => $e->code,
            'name' => $e->full_name,
            'avatar_url' => $e->avatar_url,
            'designation' => $e->designation ? ['id' => $e->designation->id, 'name' => $e->designation->name] : null,
            'duty_type' => $e->dutyType?->toRef(),
            'has_current_contract' => (bool) $e->getAttribute('has_current_contract'),
        ])]);
    }

    public function store(Request $request, ContractLifecycle $lifecycle): JsonResponse
    {
        Gate::authorize('create', Contract::class);

        /** @var array{employee_id: int, start_date: string, end_date: string, leave_entitled?: bool, notes?: string|null} $data */
        $data = $request->validate([
            'employee_id' => ['required', 'integer', $this->contractualEmployee()],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'leave_entitled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames());

        /** @var User $actor */
        $actor = $request->user();
        $contract = $lifecycle->create($actor, $data, $request->ip());

        return (new ContractResource($contract->load(ContractResource::relations())))->response()->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return new ContractResource($contract->load(ContractResource::relations()));
    }

    public function update(Request $request, Contract $contract, ContractLifecycle $lifecycle): ContractResource
    {
        Gate::authorize('update', $contract);
        abort_if($contract->renewed_at !== null, 409, 'This contract has been renewed; edit the renewal instead.');

        /** @var array{start_date?: string, end_date?: string, leave_entitled?: bool, notes?: string|null} $data */
        $data = $request->validate([
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'leave_entitled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames());

        /** @var User $actor */
        $actor = $request->user();
        $lifecycle->update($actor, $contract, $data, $request->ip());

        return new ContractResource($contract->fresh(ContractResource::relations()));
    }

    /** Renew an expiring or expired contract. */
    public function renew(Request $request, Contract $contract, ContractLifecycle $lifecycle): JsonResponse
    {
        Gate::authorize('update', $contract);

        /** @var array{end_date: string, start_date?: string|null, leave_entitled?: bool, notes?: string|null} $data */
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'leave_entitled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames());

        /** @var User $actor */
        $actor = $request->user();
        $renewal = $lifecycle->renew($actor, $contract, $data, $request->ip());

        return (new ContractResource($renewal->load(ContractResource::relations())))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Contract $contract, ContractLifecycle $lifecycle): JsonResponse
    {
        Gate::authorize('delete', $contract);
        abort_if($contract->renewed_at !== null, 409, 'This contract has been renewed; delete the renewal first.');

        /** @var User $actor */
        $actor = $request->user();
        $lifecycle->delete($actor, $contract, $request->ip());

        return response()->json(['message' => 'Contract deleted.']);
    }

    /**
     * @return Builder<Contract>
     */
    private function base(?string $search, bool $includeRenewed = false): Builder
    {
        $query = Contract::query()
            ->whereHas('employee', fn (Builder $e) => $e->where('is_draft', false))
            ->when(! $includeRenewed, fn (Builder $q) => $q->whereNull('contracts.renewed_at'));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $w) => $w
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                ->orWhere('code', 'like', $like)
                ->orWhereHas('dutyType', fn (Builder $d) => $d->where('name', 'like', $like))));
        }

        return $query;
    }

    /**
     * @param  Builder<Contract>  $query
     */
    private function whereStatus(Builder $query, string $status): void
    {
        $today = today()->toDateString();
        $soon = today()->addDays(Contract::EXPIRING_DAYS)->toDateString();

        match ($status) {
            'renewed' => $query->whereNotNull('contracts.renewed_at'),
            'upcoming' => $query->whereDate('contracts.start_date', '>', $today),
            'expired' => $query->whereDate('contracts.end_date', '<', $today),
            'expiring' => $query->whereDate('contracts.start_date', '<=', $today)->whereBetween('contracts.end_date', [$today, $soon]),
            default => $query->whereDate('contracts.start_date', '<=', $today)->whereDate('contracts.end_date', '>', $soon),
        };
    }

    /**
     * Tab counts (current contracts) and the "expire this month" banner.
     *
     * @return array<string, mixed>
     */
    private function summary(?string $search): array
    {
        $counts = ['all' => $this->base($search)->count()];
        foreach (['active', 'expiring', 'expired', 'upcoming'] as $status) {
            $q = $this->base($search);
            $this->whereStatus($q, $status);
            $counts[$status] = $q->count();
        }

        $thisMonth = $this->base($search)
            ->whereBetween('contracts.end_date', [today()->toDateString(), today()->endOfMonth()->toDateString()])
            ->with(['employee' => fn ($q) => $q->select(['id', 'first_name', 'middle_name', 'last_name'])])
            ->orderBy('contracts.end_date')
            ->get(['id', 'employee_id', 'end_date']);

        return [
            ...$counts,
            'expiring_days' => Contract::EXPIRING_DAYS,
            'expiring_this_month' => [
                'count' => $thisMonth->count(),
                'names' => $thisMonth->take(3)->map(fn (Contract $c) => $c->employee?->full_name)->filter()->values(),
            ],
        ];
    }

    /** A real, non-deleted employee whose duty type is contractual. */
    private function contractualEmployee(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $employee = Employee::query()->finalized()->with('dutyType:id,is_contractual')->find((int) $value, ['id', 'duty_type_id']);
            if (! $employee) {
                $fail('The selected employee is invalid.');
            } elseif (! $employee->dutyType?->is_contractual) {
                $fail('Contracts are only for employees with a contractual duty type.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return ['employee_id' => 'employee', 'start_date' => 'start date', 'end_date' => 'end date', 'leave_entitled' => 'leave entitlement'];
    }
}
