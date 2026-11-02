<?php

namespace App\Models;

use App\Enums\ExitType;
use App\Enums\OffboardingStage;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One offboarding case. The dates (resigned date, last working day, reason) are on the employee.
 * An employee has at most one open case (completed_at = null).
 *
 * @property int $id
 * @property int $employee_id
 * @property ExitType $exit_type
 * @property OffboardingStage $stage
 * @property int|null $previous_employment_status_id
 * @property int|null $final_employment_status_id
 * @property Carbon|null $exited_at set when the employee was moved to the final status
 * @property Carbon|null $completed_at
 * @property int|null $created_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Offboarding extends Model
{
    /** Default exit checklist: label => owner. */
    public const DEFAULT_CHECKLIST = [
        'Resignation / exit letter on file' => 'HR',
        'Knowledge handover completed' => 'Manager',
        'Laptop and accessories returned' => 'IT',
        'System and email access revoked' => 'IT',
        'ID card and access card returned' => 'Admin',
        'Final settlement processed' => 'Finance',
        'Exit interview held' => 'HR',
        'Experience letter issued' => 'HR',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id', 'exit_type', 'stage', 'previous_employment_status_id', 'final_employment_status_id',
        'exited_at', 'completed_at', 'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_type' => ExitType::class,
            'stage' => OffboardingStage::class,
            'exited_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Offboarding>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('offboardings.completed_at');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EmploymentStatus, $this>
     */
    public function finalStatus(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class, 'final_employment_status_id');
    }

    /**
     * @return BelongsTo<EmploymentStatus, $this>
     */
    public function previousStatus(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class, 'previous_employment_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<OffboardingChecklistItem, $this>
     */
    public function checklist(): HasMany
    {
        return $this->hasMany(OffboardingChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Same as checklist(); named for scoped route binding of `{item}`.
     *
     * @return HasMany<OffboardingChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->checklist();
    }

    /** The status to move to after the last working day when none was chosen: by exit type, else any no-login status. */
    public static function defaultFinalStatusId(ExitType $type): ?int
    {
        return EmploymentStatus::query()->where('name', $type->defaultFinalStatusName())->value('id')
            ?? EmploymentStatus::query()->where('can_login', false)->orderBy('sort_order')->value('id');
    }
}
