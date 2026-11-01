<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\WorkMode;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $code
 * @property string|null $machine_uid
 * @property string $first_name
 * @property string $last_name
 * @property string|null $pseudo_name
 * @property string $email
 * @property string|null $phone
 * @property Gender|null $gender
 * @property Carbon|null $birth_date
 * @property string|null $cnic
 * @property string|null $cnic_hash
 * @property int|null $designation_id
 * @property int|null $department_id
 * @property int|null $division_id
 * @property int|null $campaign_id
 * @property int|null $shift_id
 * @property int|null $location_id
 * @property int|null $reports_to_id
 * @property WorkMode $work_mode
 * @property EmployeeStatus $status
 * @property Carbon $join_date
 * @property string|null $avatar_path
 * @property-read string $full_name
 * @property-read string|null $avatar_url
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'machine_uid',
        'first_name', 'last_name', 'pseudo_name', 'email', 'phone', 'gender', 'birth_date', 'cnic',
        'designation_id', 'department_id', 'division_id', 'campaign_id', 'shift_id', 'location_id', 'reports_to_id',
        'work_mode', 'status', 'join_date', 'avatar_path',
    ];

    /**
     * PII must never leak through accidental serialization (toArray, logs, etc.).
     *
     * @var list<string>
     */
    protected $hidden = ['cnic', 'cnic_hash', 'phone', 'birth_date'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cnic' => 'encrypted',
            'gender' => Gender::class,
            'work_mode' => WorkMode::class,
            'status' => EmployeeStatus::class,
            'birth_date' => 'date',
            'join_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('cnic')) {
                $employee->cnic_hash = $employee->cnic === null ? null : self::cnicBlindIndex($employee->cnic);
            }
        });

        static::created(function (Employee $employee): void {
            if ($employee->code === null) {
                $employee->code = self::formatCode($employee->id);
                $employee->saveQuietly();
            }
        });
    }

    public static function formatCode(int $id): string
    {
        return sprintf('EMP-%04d', $id);
    }

    /**
     * Deterministic keyed hash of the CNIC, used only for uniqueness checks.
     */
    public static function cnicBlindIndex(string $cnic): string
    {
        $normalised = preg_replace('/\D/', '', $cnic) ?? $cnic;

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Division, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to_id');
    }

    /**
     * @return HasMany<EmployeeDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(EmployeeDevice::class);
    }

    /**
     * @return HasOne<EmployeeDevice, $this>
     */
    public function activeDevice(): HasOne
    {
        // BindDevice/UnbindDevice keep at most one row with unbound_at = null per employee.
        return $this->hasOne(EmployeeDevice::class)->whereNull('unbound_at');
    }
}
