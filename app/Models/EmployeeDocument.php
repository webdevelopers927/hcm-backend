<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * File on the private local disk; downloaded only through the authenticated documents endpoint.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $sort_order
 * @property string|null $title
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 */
class EmployeeDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['sort_order', 'title', 'path', 'original_name', 'mime_type', 'size'];

    /**
     * @var list<string>
     */
    protected $hidden = ['path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
