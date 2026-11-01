<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $sort_order
 * @property string|null $company_name
 * @property string|null $designation
 * @property string|null $last_salary
 * @property int|null $country_id
 * @property int|null $state_id
 * @property int|null $city_id
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property string|null $contact_person_name
 * @property string|null $contact_person_email
 * @property string|null $contact_person_phone
 * @property string|null $experience_letter_path
 * @property string|null $experience_letter_name
 * @property string|null $experience_letter_mime
 * @property int|null $experience_letter_size
 * @property string|null $reason_for_leaving
 * @property string|null $description
 */
class EmployeeWorkExperience extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'sort_order', 'company_name', 'designation', 'last_salary', 'country_id', 'state_id', 'city_id',
        'period_from', 'period_to', 'contact_person_name', 'contact_person_email', 'contact_person_phone',
        'reason_for_leaving', 'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_salary' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'experience_letter_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
