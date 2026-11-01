<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $sort_order
 * @property string|null $institution
 * @property int|null $degree_program_id
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property int|null $year
 */
class EmployeeQualification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['sort_order', 'institution', 'degree_program_id', 'start_date', 'end_date', 'year'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'year' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DegreeProgram, $this>
     */
    public function degreeProgram(): BelongsTo
    {
        return $this->belongsTo(DegreeProgram::class);
    }
}
