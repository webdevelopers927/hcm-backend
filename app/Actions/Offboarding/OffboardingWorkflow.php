<?php

namespace App\Actions\Offboarding;

use App\Enums\ExitType;
use App\Enums\OffboardingStage;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Offboarding;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Offboarding cases. The exit dates live on the employee; a case tracks exit type, stage, checklist and the
 * status the employee moves to the day after their last working day (applied by offboarding:process-exits).
 */
class OffboardingWorkflow
{
    /**
     * Starts offboarding and opens a case with the default checklist.
     *
     * - Last working day in the future: the employee serves notice (moved into a notice-period status);
     *   the daily job moves them to the final status the day after.
     * - Last working day today or earlier (e.g. terminated with immediate effect): no notice period. They
     *   move to the final status now and are signed out if it can't log in; the case opens at Clearance
     *   so the checklist (assets, access, settlement) still gets done.
     *
     * @param  array{exit_type: string, resigned_date: string, last_working_day: string, reason_for_resignation: string, notice_employment_status_id?: int|null, final_employment_status_id?: int|null, stage?: string|null}  $data
     */
    public function start(User $actor, Employee $employee, array $data, ?string $ip = null): Offboarding
    {
        return DB::transaction(function () use ($actor, $employee, $data, $ip) {
            if ($employee->openOffboarding()->exists()) {
                throw new ConflictHttpException('This employee is already being offboarded.');
            }

            $previousStatus = $employee->employment_status_id;
            $immediate = Carbon::parse($data['last_working_day'])->lte(today());
            $noticeStatus = $immediate ? null : ($data['notice_employment_status_id'] ?? self::defaultNoticeStatusId());

            $employee->forceFill([
                'resigned_date' => $data['resigned_date'],
                'last_working_day' => $data['last_working_day'],
                'reason_for_resignation' => $data['reason_for_resignation'],
                'employment_status_id' => $noticeStatus ?? $employee->employment_status_id,
            ])->save();

            $type = ExitType::from($data['exit_type']);
            $stage = OffboardingStage::tryFrom((string) ($data['stage'] ?? ''))
                ?? ($immediate ? OffboardingStage::Clearance : OffboardingStage::Resigned);
            $case = $this->open($employee, $type, $stage, $previousStatus, $data['final_employment_status_id'] ?? null, $actor);

            if ($immediate) {
                $this->applyExit($case, $employee);
            }

            $this->log($actor, $employee, 'offboarding_started', $ip, [
                'offboarding_id' => $case->id, 'exit_type' => $type->value, 'immediate' => $immediate,
            ]);

            return $case;
        });
    }

    /**
     * Called after an employee save (form, draft submit, import), inside the caller's transaction:
     *
     * - Now in a notice-period status with no open case: open one (a resignation, Notice period stage).
     * - Just moved into a status that ends employment (e.g. set to Terminated directly): that's an
     *   immediate exit. The open case (or a new one, exit type from the status name) gets this status as
     *   its final status, the exit is applied now and the case sits at Clearance for the checklist.
     */
    public function syncWithStatus(Employee $employee, ?int $previousStatusId, ?User $actor): void
    {
        if ($employee->is_draft) {
            return;
        }

        if ($employee->onNotice()) {
            if (! $employee->openOffboarding()->exists()) {
                $this->open($employee, ExitType::Resignation, OffboardingStage::NoticePeriod, $previousStatusId, null, $actor);
            }

            return;
        }

        if ((int) $employee->employment_status_id === (int) $previousStatusId || ! $employee->hasExited()) {
            return;
        }

        $case = $employee->openOffboarding()->first();
        if ($case?->exited_at !== null) {
            return;
        }

        if ($case === null) {
            $statusName = (string) EmploymentStatus::query()->whereKey($employee->employment_status_id)->value('name');
            $case = $this->open($employee, ExitType::forStatusName($statusName), OffboardingStage::Clearance,
                $previousStatusId, $employee->employment_status_id, $actor);
        } else {
            $case->forceFill([
                'final_employment_status_id' => $employee->employment_status_id,
                'stage' => OffboardingStage::Clearance,
            ])->save();
        }

        $this->applyExit($case, $employee);
    }

    /**
     * Marks a case done. Every checklist item must be ticked. If the last working day has already passed
     * the exit is applied now; otherwise the daily job applies it.
     */
    public function complete(User $actor, Offboarding $case, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $case, $ip) {
            $open = $case->checklist()->whereNull('done_at')->count();
            if ($open > 0) {
                throw ValidationException::withMessages(['checklist' => "Tick off the remaining {$open} checklist item(s) first."]);
            }

            $case->forceFill(['stage' => OffboardingStage::Completed, 'completed_at' => now()])->save();

            $employee = $case->employee()->firstOrFail();
            if ($case->exited_at === null && $employee->last_working_day?->lt(today())) {
                $this->applyExit($case, $employee);
            }

            $this->log($actor, $employee, 'offboarding_completed', $ip, ['offboarding_id' => $case->id]);
        });
    }

    /**
     * Cancels a case that hasn't exited yet (e.g. a withdrawn resignation): the employee goes back to the
     * status they had before and their exit dates are cleared.
     */
    public function cancel(User $actor, Offboarding $case, ?string $ip = null): void
    {
        if ($case->exited_at !== null || $case->completed_at !== null) {
            throw new ConflictHttpException('This employee has already left; the case can no longer be cancelled.');
        }

        DB::transaction(function () use ($actor, $case, $ip) {
            $employee = $case->employee()->firstOrFail();

            $employee->forceFill([
                'employment_status_id' => $case->previous_employment_status_id ?? $employee->employment_status_id,
                'resigned_date' => null,
                'last_working_day' => null,
                'reason_for_resignation' => null,
            ])->save();

            $case->delete();
            $this->log($actor, $employee, 'offboarding_cancelled', $ip, ['offboarding_id' => $case->id]);
        });
    }

    /**
     * Applies every exit whose last working day has passed. Returns how many employees left.
     */
    public function processDue(?Carbon $today = null): int
    {
        $today ??= today();
        $count = 0;

        Offboarding::query()
            ->whereNull('exited_at')
            ->whereHas('employee', fn ($q) => $q->whereDate('last_working_day', '<', $today))
            ->with('employee')
            ->chunkById(100, function ($cases) use (&$count) {
                foreach ($cases as $case) {
                    DB::transaction(fn () => $this->applyExit($case, $case->employee));
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The employee leaves: final status, separation date, and no more sign-in if that status forbids it.
     */
    public function applyExit(Offboarding $case, Employee $employee): void
    {
        $employee->forceFill([
            'employment_status_id' => $case->final_employment_status_id ?? $employee->employment_status_id,
            'separation_date' => $employee->separation_date ?? $employee->last_working_day,
        ])->save();

        if ($employee->user_id !== null && ! $employee->loginAllowed()) {
            User::query()->find($employee->user_id)?->tokens()->delete();
        }

        $case->forceFill(['exited_at' => now()])->save();

        activity('employees')
            ->performedOn($employee)
            ->event('employee_exited')
            ->withProperties(['offboarding_id' => $case->id, 'employment_status_id' => $employee->employment_status_id])
            ->log('Employee exited after last working day');
    }

    public static function defaultNoticeStatusId(): ?int
    {
        return EmploymentStatus::query()->where('is_notice_period', true)->orderBy('sort_order')->value('id');
    }

    private function open(Employee $employee, ExitType $type, OffboardingStage $stage, ?int $previousStatusId, ?int $finalStatusId, ?User $actor): Offboarding
    {
        /** @var Offboarding $case */
        $case = $employee->offboardings()->create([
            'exit_type' => $type,
            'stage' => $stage,
            'previous_employment_status_id' => $previousStatusId,
            'final_employment_status_id' => $finalStatusId ?? Offboarding::defaultFinalStatusId($type),
            'created_by_id' => $actor?->id,
        ]);

        $order = 0;
        foreach (Offboarding::DEFAULT_CHECKLIST as $label => $owner) {
            $case->checklist()->create(['label' => $label, 'owner' => $owner, 'sort_order' => $order++]);
        }

        return $case;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, Employee $employee, string $event, ?string $ip, array $properties): void
    {
        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event($event)
            ->withProperties(['ip' => $ip, ...$properties])
            ->log(str_replace('_', ' ', ucfirst($event)));
    }
}
