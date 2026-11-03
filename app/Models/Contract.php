<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fixed-term contract of a contractual employee.
 *
 * Status is derived from the dates: upcoming (not started), active, expiring (ends within
 * EXPIRING_DAYS), expired (ended), or renewed (replaced by a newer contract; history).
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property bool $leave_entitled
 * @property string|null $notes
 * @property int|null $renewed_from_id
 * @property Carbon|null $renewed_at
 * @property Carbon|null $expired_at
 * @property int|null $status_before_expiry_id
 * @property int|null $created_by_id
 * @property Carbon $created_at
 */
class Contract extends Model
{
    /** Ends within this many days: shown as expiring, and renewable. */
    public const EXPIRING_DAYS = 30;

    /** Employment status employees move to when their contract runs out (seeded; can't sign in). */
    public const EXPIRED_STATUS_NAME = 'Contract expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id', 'start_date', 'end_date', 'leave_entitled', 'notes', 'renewed_from_id', 'renewed_at',
        'expired_at', 'status_before_expiry_id', 'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'leave_entitled' => 'boolean',
            'renewed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /** upcoming | active | expiring | expired | renewed */
    public function status(?Carbon $today = null): string
    {
        $today ??= today();

        return match (true) {
            $this->renewed_at !== null => 'renewed',
            $this->start_date->gt($today) => 'upcoming',
            $this->end_date->lt($today) => 'expired',
            $this->end_date->lte($today->copy()->addDays(self::EXPIRING_DAYS)) => 'expiring',
            default => 'active',
        };
    }

    /** Renewable once it is expiring or has expired (and hasn't been renewed already). */
    public function renewable(?Carbon $today = null): bool
    {
        return in_array($this->status($today), ['expiring', 'expired'], true);
    }

    public static function expiredStatusId(): ?int
    {
        return EmploymentStatus::query()->where('name', self::EXPIRED_STATUS_NAME)->value('id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'renewed_from_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
