<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setup → Employment statuses. `can_login = false` blocks sign-in for employees with this status;
 * `is_notice_period` marks people who are serving notice; `is_exit` marks people who have left
 * (moving someone into it requires their exit details and opens an offboarding case).
 *
 * @property int $id
 * @property string $name
 * @property bool $can_login
 * @property bool $is_notice_period
 * @property bool $is_exit
 * @property int $sort_order
 */
class EmploymentStatus extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'can_login', 'is_notice_period', 'is_exit', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_login' => 'boolean',
            'is_notice_period' => 'boolean',
            'is_exit' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public static function isExitId(mixed $id): bool
    {
        return is_numeric($id) && (bool) self::query()->whereKey((int) $id)->value('is_exit');
    }

    /**
     * Every status in display order, for dropdowns and tabs.
     *
     * @return list<array{id: int, name: string, can_login: bool, is_notice_period: bool, is_exit: bool}>
     */
    public static function options(): array
    {
        return self::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (self $s) => $s->toRef())->values()->all();
    }

    /**
     * @return array{id: int, name: string, can_login: bool, is_notice_period: bool, is_exit: bool}
     */
    public function toRef(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'can_login' => $this->can_login,
            'is_notice_period' => $this->is_notice_period,
            'is_exit' => $this->is_exit,
        ];
    }
}
