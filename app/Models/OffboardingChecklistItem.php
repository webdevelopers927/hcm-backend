<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $offboarding_id
 * @property int $sort_order
 * @property string $label
 * @property string|null $owner who is responsible ("IT", "HR", "Manager"…)
 * @property Carbon|null $done_at
 * @property int|null $done_by_id
 */
class OffboardingChecklistItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['sort_order', 'label', 'owner', 'done_at', 'done_by_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Offboarding, $this>
     */
    public function offboarding(): BelongsTo
    {
        return $this->belongsTo(Offboarding::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_id');
    }
}
