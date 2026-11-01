<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $months
 */
class ProbationDuration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'months'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['months' => 'integer'];
    }
}
