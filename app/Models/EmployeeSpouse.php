<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $sort_order
 * @property string|null $full_name
 * @property string|null $cnic
 * @property Carbon|null $birth_date
 */
class EmployeeSpouse extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['sort_order', 'full_name', 'cnic', 'birth_date'];

    /**
     * @var list<string>
     */
    protected $hidden = ['cnic'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cnic' => 'encrypted',
            'birth_date' => 'date',
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
     * @return HasMany<EmployeeChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(EmployeeChild::class)->orderBy('sort_order')->orderBy('id');
    }
}
