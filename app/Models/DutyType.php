<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setup → Duty types (Full-time, Contract, Internship…). `is_contractual` marks fixed-term or
 * engagement-based work.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_contractual
 * @property int $sort_order
 */
class DutyType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'is_contractual', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_contractual' => 'boolean',
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

    /**
     * Every duty type in display order, for dropdowns.
     *
     * @return list<array{id: int, name: string, is_contractual: bool}>
     */
    public static function options(): array
    {
        return self::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (self $t) => $t->toRef())->values()->all();
    }

    /**
     * @return array{id: int, name: string, is_contractual: bool}
     */
    public function toRef(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'is_contractual' => $this->is_contractual];
    }
}
