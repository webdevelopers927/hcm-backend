<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_spouse_id
 * @property int $sort_order
 * @property string|null $full_name
 * @property Carbon|null $birth_date
 */
class EmployeeChild extends Model
{
    // The inflector would otherwise look for "employee_childs".
    protected $table = 'employee_children';

    /**
     * @var list<string>
     */
    protected $fillable = ['sort_order', 'full_name', 'birth_date'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }
}
