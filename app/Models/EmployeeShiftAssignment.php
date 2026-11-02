<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One shift change: from effective_from onwards the employee works this shift.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $shift_id
 * @property Carbon $effective_from
 * @property int|null $assigned_by_id
 * @property Carbon|null $applied_at null while scheduled for a future date
 * @property Carbon $created_at
 */
class EmployeeShiftAssignment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['employee_id', 'shift_id', 'effective_from', 'assigned_by_id', 'applied_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
