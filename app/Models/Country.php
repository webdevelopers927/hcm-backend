<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seeded reference data (GeoSeeder); also the source of nationalities.
 *
 * @property int $id
 * @property string $name
 * @property string $iso2
 * @property string|null $iso3
 * @property string|null $phone_code
 * @property string|null $nationality
 * @property string|null $emoji
 */
class Country extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'name', 'iso2', 'iso3', 'phone_code', 'nationality', 'emoji'];

    /**
     * @return HasMany<State, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
