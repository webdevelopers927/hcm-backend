<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 */
class Shift extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'start_time', 'end_time', 'break_minutes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['break_minutes' => 'integer'];
    }

    /** "09:00:00" -> "09:00" */
    public static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
