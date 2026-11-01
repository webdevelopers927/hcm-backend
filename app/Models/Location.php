<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Location extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
