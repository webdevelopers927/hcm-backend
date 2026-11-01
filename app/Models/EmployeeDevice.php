<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property string $device_id
 * @property string|null $model
 * @property string|null $platform
 * @property Carbon $bound_at
 * @property Carbon|null $unbound_at
 */
class EmployeeDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['device_id', 'model', 'platform', 'bound_at', 'unbound_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
            'unbound_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
