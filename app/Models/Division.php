<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Division extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
