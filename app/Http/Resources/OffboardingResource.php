<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\OffboardingChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An offboarding case. The checklist items are included when loaded (case detail); the list only has
 * the done/total counts.
 *
 * @mixin Offboarding
 */
class OffboardingResource extends JsonResource
{
    /**
     * @return array<int|string, string|\Closure>
     */
    public static function relations(bool $withChecklist): array
    {
        return [
            'employee' => fn ($q) => $q->withTrashed()->select([
                'id', 'code', 'first_name', 'middle_name', 'last_name', 'avatar_path', 'designation_id', 'department_id',
                'employment_status_id', 'resigned_date', 'last_working_day', 'reason_for_resignation', 'separation_date',
            ]),
            'employee.designation:id,name',
            'employee.department:id,name',
            'employee.employmentStatus:id,name,can_login,is_notice_period,is_exit',
            'finalStatus:id,name,can_login,is_notice_period,is_exit',
            'createdBy:id,name',
            ...($withChecklist ? ['checklist.doneBy:id,name'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Offboarding $case */
        $case = $this->resource;
        /** @var Employee $employee */
        $employee = $case->employee;
        $lwd = $employee->last_working_day;

        $data = [
            'id' => $case->id,
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->full_name,
                'avatar_url' => $employee->avatar_url,
                'designation' => EmployeeListResource::idName($employee->designation),
                'department' => EmployeeListResource::idName($employee->department),
                'employment_status' => $employee->employmentStatus?->toRef(),
            ],
            'exit_type' => $case->exit_type->value,
            'stage' => $case->stage->value,
            'resigned_date' => $employee->resigned_date?->toDateString(),
            'last_working_day' => $lwd?->toDateString(),
            // Negative once the last working day has passed.
            'days_left' => $lwd ? (int) today()->diffInDays($lwd, false) : null,
            'reason_for_resignation' => $employee->reason_for_resignation,
            'separation_date' => $employee->separation_date?->toDateString(),
            'final_employment_status' => $case->finalStatus?->toRef(),
            'checklist_progress' => $this->progress($case),
            'exited_at' => $case->exited_at?->toIso8601String(),
            'completed_at' => $case->completed_at?->toIso8601String(),
            'created_by' => $case->createdBy ? ['id' => $case->createdBy->id, 'name' => $case->createdBy->name] : null,
            'created_at' => $case->created_at->toIso8601String(),
        ];

        if ($case->relationLoaded('checklist')) {
            $data['checklist'] = $case->checklist->map(fn (OffboardingChecklistItem $item) => [
                'id' => $item->id,
                'label' => $item->label,
                'owner' => $item->owner,
                'done' => $item->done_at !== null,
                'done_at' => $item->done_at?->toIso8601String(),
                'done_by' => $item->doneBy ? ['id' => $item->doneBy->id, 'name' => $item->doneBy->name] : null,
            ])->all();
        }

        return $data;
    }

    /**
     * From the list's withCount columns when present, otherwise from the loaded checklist.
     *
     * @return array{done: int, total: int}
     */
    private function progress(Offboarding $case): array
    {
        $attributes = $case->getAttributes();
        if (array_key_exists('checklist_total', $attributes)) {
            return ['done' => (int) $attributes['checklist_done'], 'total' => (int) $attributes['checklist_total']];
        }

        return ['done' => $case->checklist->whereNotNull('done_at')->count(), 'total' => $case->checklist->count()];
    }
}
