<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contracts and what they do to the employee:
 *
 * - No contract covers today but one has ended → the employee moves to "Contract expired" (can't sign in)
 *   and is signed out. Their status before that is kept on the ended contract.
 * - A contract covers today again (renewed or edited) → their previous status comes back.
 *
 * sync() applies this for one employee; the daily contracts:sync command runs it for everyone.
 * Only contractual employees (duty type is_contractual) are affected, and people who have left
 * (exit status) or are serving notice are never touched.
 */
class ContractLifecycle
{
    /**
     * @param  array{employee_id: int, start_date: string, end_date: string, leave_entitled?: bool, notes?: string|null}  $data
     */
    public function create(User $actor, array $data, ?string $ip = null): Contract
    {
        return DB::transaction(function () use ($actor, $data, $ip) {
            $this->assertNoOverlap((int) $data['employee_id'], $data['start_date'], $data['end_date']);

            $contract = Contract::create($data + ['created_by_id' => $actor->id]);
            $this->sync($contract->employee_id);
            $this->log($actor, $contract, 'contract_created', $ip);

            return $contract;
        });
    }

    /**
     * @param  array{start_date?: string, end_date?: string, leave_entitled?: bool, notes?: string|null}  $data
     */
    public function update(User $actor, Contract $contract, array $data, ?string $ip = null): Contract
    {
        return DB::transaction(function () use ($actor, $contract, $data, $ip) {
            $start = $data['start_date'] ?? $contract->start_date->toDateString();
            $end = $data['end_date'] ?? $contract->end_date->toDateString();
            if ($end < $start) {
                throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
            }
            $this->assertNoOverlap($contract->employee_id, $start, $end, ignoreId: $contract->id);

            $contract->update($data);
            $this->sync($contract->employee_id);
            $this->log($actor, $contract, 'contract_updated', $ip);

            return $contract;
        });
    }

    /**
     * New contract continuing an expiring or expired one. Starts the day after the old one ends unless a
     * later start is given; the old one stays as history (renewed).
     *
     * @param  array{end_date: string, start_date?: string|null, leave_entitled?: bool, notes?: string|null}  $data
     */
    public function renew(User $actor, Contract $contract, array $data, ?string $ip = null): Contract
    {
        if (! $contract->renewable()) {
            throw ValidationException::withMessages(['contract' => $contract->renewed_at
                ? 'This contract has already been renewed.'
                : 'A contract can be renewed once it is within '.Contract::EXPIRING_DAYS.' days of its end date, or after it has expired.']);
        }

        return DB::transaction(function () use ($actor, $contract, $data, $ip) {
            $start = $data['start_date'] ?? $contract->end_date->copy()->addDay()->toDateString();
            if ($start <= $contract->end_date->toDateString()) {
                throw ValidationException::withMessages(['start_date' => 'The renewal must start after the current contract ends ('.$contract->end_date->toDateString().').']);
            }
            if ($data['end_date'] < $start) {
                throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
            }
            $this->assertNoOverlap($contract->employee_id, $start, $data['end_date'], ignoreId: $contract->id);

            $renewal = Contract::create([
                'employee_id' => $contract->employee_id,
                'start_date' => $start,
                'end_date' => $data['end_date'],
                'leave_entitled' => $data['leave_entitled'] ?? $contract->leave_entitled,
                'notes' => $data['notes'] ?? null,
                'renewed_from_id' => $contract->id,
                // Carry the status to restore along the chain.
                'status_before_expiry_id' => $contract->status_before_expiry_id,
                'created_by_id' => $actor->id,
            ]);
            $contract->forceFill(['renewed_at' => now()])->save();

            $this->sync($contract->employee_id);
            $this->log($actor, $renewal, 'contract_renewed', $ip, ['renewed_from_id' => $contract->id]);

            return $renewal;
        });
    }

    public function delete(User $actor, Contract $contract, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $contract, $ip) {
            // The contract it renewed becomes the current one again.
            if ($contract->renewed_from_id !== null) {
                Contract::query()->whereKey($contract->renewed_from_id)->update(['renewed_at' => null]);
            }
            $this->log($actor, $contract, 'contract_deleted', $ip);
            $contract->delete();
            $this->sync($contract->employee_id);
        });
    }

    /**
     * Brings one employee's status in line with their contracts (see class doc). Returns what happened:
     * "expired", "restored" or null.
     */
    public function sync(int $employeeId, ?Carbon $today = null): ?string
    {
        $today ??= today();
        $employee = Employee::query()->finalized()->with('dutyType:id,is_contractual')->find($employeeId);
        $expiredStatus = Contract::expiredStatusId();
        if (! $employee || $expiredStatus === null) {
            return null;
        }

        $contracts = Contract::query()->where('employee_id', $employee->id)->orderBy('start_date')->get();
        $covering = $contracts->first(fn (Contract $c) => $c->start_date->lte($today) && $c->end_date->gte($today));

        if ($covering && $employee->employment_status_id === $expiredStatus) {
            $lastExpired = $contracts->whereNotNull('expired_at')->sortByDesc('expired_at')->first();
            $restore = $covering->status_before_expiry_id
                ?? $lastExpired->status_before_expiry_id
                ?? EmploymentStatus::query()->where('name', 'Active')->value('id');
            $employee->forceFill(['employment_status_id' => $restore])->save();
            activity('employees')->performedOn($employee)->event('contract_restored')
                ->withProperties(['contract_id' => $covering->id])->log('Access restored: contract in force');

            return 'restored';
        }

        $ended = $contracts->filter(fn (Contract $c) => $c->end_date->lt($today))->sortByDesc('end_date')->first();
        $leaving = $employee->hasExited() || $employee->onNotice();
        if ($covering || ! $ended || $employee->employment_status_id === $expiredStatus
            || ! $employee->dutyType?->is_contractual || $leaving) {
            return null;
        }

        $ended->forceFill(['expired_at' => now(), 'status_before_expiry_id' => $employee->employment_status_id])->save();
        $employee->forceFill(['employment_status_id' => $expiredStatus])->save();
        if ($employee->user_id !== null) {
            User::query()->find($employee->user_id)?->tokens()->delete();
        }
        activity('employees')->performedOn($employee)->event('contract_expired')
            ->withProperties(['contract_id' => $ended->id])->log('Contract expired: sign-in blocked');

        return 'expired';
    }

    /**
     * Runs sync() for every employee with a contract. Returns counts.
     *
     * @return array{expired: int, restored: int}
     */
    public function syncAll(?Carbon $today = null): array
    {
        $result = ['expired' => 0, 'restored' => 0];
        Contract::query()->select('employee_id')->distinct()->orderBy('employee_id')
            ->chunk(200, function ($rows) use (&$result, $today) {
                foreach ($rows as $row) {
                    $outcome = DB::transaction(fn () => $this->sync((int) $row->employee_id, $today));
                    if ($outcome !== null) {
                        $result[$outcome]++;
                    }
                }
            });

        return $result;
    }

    private function assertNoOverlap(int $employeeId, string $start, string $end, ?int $ignoreId = null): void
    {
        $clash = Contract::query()
            ->where('employee_id', $employeeId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->first(['id', 'start_date', 'end_date']);

        if ($clash) {
            throw ValidationException::withMessages(['start_date' => sprintf(
                'This employee already has a contract from %s to %s. Renew or edit that one instead.',
                $clash->start_date->toDateString(), $clash->end_date->toDateString(),
            )]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, Contract $contract, string $event, ?string $ip, array $properties = []): void
    {
        activity('employees')
            ->causedBy($actor)
            ->performedOn($contract)
            ->event($event)
            ->withProperties(['ip' => $ip, 'employee_id' => $contract->employee_id, ...$properties])
            ->log(str_replace('_', ' ', ucfirst($event)));
    }
}
