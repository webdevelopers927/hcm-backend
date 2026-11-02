<?php

namespace App\Models;

use App\Enums\BloodGroup;
use App\Enums\DutyType;
use App\Enums\EmergencyContactRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\Religion;
use App\Enums\Salutation;
use App\Enums\WorkMode;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An employee, or a draft of one (is_draft = true). A draft may have any field empty, has no login
 * account and no code; SubmitEmployeeDraft turns it into a real employee once EmployeeCompleteness passes.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $code
 * @property bool $is_draft
 * @property int|null $created_by_id
 * @property string|null $machine_uid
 * @property Salutation|null $salutation
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string|null $pseudo_name
 * @property string|null $father_husband_name
 * @property string|null $email business email; also the login email
 * @property string|null $personal_email
 * @property string|null $phone primary contact number (E.164)
 * @property string|null $secondary_phone
 * @property string|null $landline
 * @property string|null $permanent_address
 * @property string|null $present_address
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property EmergencyContactRelation|null $emergency_contact_relation
 * @property Gender|null $gender
 * @property Carbon|null $birth_date
 * @property string|null $cnic
 * @property string|null $cnic_hash
 * @property Carbon|null $cnic_expiry
 * @property int|null $country_id
 * @property int|null $state_id
 * @property int|null $city_id
 * @property int|null $nationality_id
 * @property MaritalStatus|null $marital_status
 * @property Religion|null $religion
 * @property BloodGroup|null $blood_group
 * @property int|null $designation_id
 * @property int|null $department_id
 * @property int|null $division_id
 * @property int|null $campaign_id
 * @property int|null $shift_id
 * @property int|null $location_id
 * @property int|null $reports_to_id
 * @property int|null $indirect_reports_to_id
 * @property WorkMode $work_mode
 * @property int|null $employment_status_id
 * @property DutyType|null $duty_type
 * @property Carbon|null $join_date
 * @property Carbon|null $probation_start_date
 * @property int|null $probation_duration_id
 * @property Carbon|null $confirmation_date
 * @property Carbon|null $separation_date
 * @property Carbon|null $resigned_date
 * @property Carbon|null $last_working_day
 * @property string|null $reason_for_resignation
 * @property bool $has_team_authority
 * @property bool $remote_access
 * @property bool $permanent_remote_access
 * @property Carbon|null $remote_access_from
 * @property Carbon|null $remote_access_until
 * @property string|null $passport_no
 * @property Carbon|null $passport_expiry
 * @property string|null $driving_license_no
 * @property Carbon|null $driving_license_expiry
 * @property bool $has_work_experience
 * @property string|null $avatar_path
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $full_name
 * @property-read string|null $avatar_url
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Columns written straight from validated form data (see SaveEmployee). Relations, files and
     * flags managed by the app (is_draft, code, user_id, created_by_id, avatar_path) are not here.
     */
    public const FORM_FIELDS = [
        'machine_uid', 'salutation', 'first_name', 'middle_name', 'last_name', 'pseudo_name', 'father_husband_name',
        'email', 'personal_email', 'gender', 'birth_date', 'cnic', 'cnic_expiry',
        'country_id', 'state_id', 'city_id', 'nationality_id', 'marital_status', 'religion', 'blood_group',
        'designation_id', 'department_id', 'division_id', 'campaign_id', 'shift_id', 'location_id',
        'reports_to_id', 'indirect_reports_to_id', 'work_mode', 'employment_status_id', 'duty_type', 'join_date',
        'probation_start_date', 'probation_duration_id', 'confirmation_date', 'separation_date',
        'resigned_date', 'last_working_day', 'reason_for_resignation',
        'has_team_authority', 'remote_access', 'permanent_remote_access', 'remote_access_from', 'remote_access_until',
        'phone', 'secondary_phone', 'landline', 'permanent_address', 'present_address',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'passport_no', 'passport_expiry', 'driving_license_no', 'driving_license_expiry', 'has_work_experience',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'avatar_path', ...self::FORM_FIELDS,
    ];

    /**
     * Same as the column defaults, so new instances read correctly before they are saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_draft' => false,
        'work_mode' => 'onsite',
        'has_team_authority' => false,
        'remote_access' => false,
        'permanent_remote_access' => false,
        'has_work_experience' => false,
    ];

    /**
     * PII must never leak through accidental serialization (toArray, logs, etc.).
     *
     * @var list<string>
     */
    protected $hidden = [
        'cnic', 'cnic_hash', 'phone', 'secondary_phone', 'landline', 'birth_date', 'passport_no', 'driving_license_no',
        'permanent_address', 'present_address', 'personal_email',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'cnic' => 'encrypted',
            'passport_no' => 'encrypted',
            'driving_license_no' => 'encrypted',
            'salutation' => Salutation::class,
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'religion' => Religion::class,
            'blood_group' => BloodGroup::class,
            'emergency_contact_relation' => EmergencyContactRelation::class,
            'work_mode' => WorkMode::class,
            'duty_type' => DutyType::class,
            'birth_date' => 'date',
            'cnic_expiry' => 'date',
            'join_date' => 'date',
            'probation_start_date' => 'date',
            'confirmation_date' => 'date',
            'separation_date' => 'date',
            'resigned_date' => 'date',
            'last_working_day' => 'date',
            'remote_access_from' => 'date',
            'remote_access_until' => 'date',
            'passport_expiry' => 'date',
            'driving_license_expiry' => 'date',
            'has_team_authority' => 'boolean',
            'remote_access' => 'boolean',
            'permanent_remote_access' => 'boolean',
            'has_work_experience' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('cnic')) {
                $employee->cnic_hash = $employee->cnic === null ? null : self::cnicBlindIndex($employee->cnic);
            }
        });

        // Drafts get their code when they are submitted.
        static::created(function (Employee $employee): void {
            if ($employee->code === null && ! $employee->is_draft) {
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

    /**
     * Real employees only (not drafts).
     *
     * @param  Builder<Employee>  $query
     */
    #[Scope]
    protected function finalized(Builder $query): void
    {
        $query->where('employees.is_draft', false);
    }

    /**
     * @param  Builder<Employee>  $query
     */
    #[Scope]
    protected function drafts(Builder $query): void
    {
        $query->where('employees.is_draft', true);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** Whether this employee's employment status allows signing in (no status: allowed). */
    public function loginAllowed(): bool
    {
        if ($this->employment_status_id === null) {
            return true;
        }

        return (bool) EmploymentStatus::query()->whereKey($this->employment_status_id)->value('can_login');
    }

    /** Last day of probation, when both the start and the duration are known. */
    public function probationEndDate(): ?Carbon
    {
        $months = $this->probationDuration?->months;

        return $this->probation_start_date && $months !== null
            ? $this->probation_start_date->copy()->addMonthsNoOverflow($months)->subDay()
            : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<EmploymentStatus, $this>
     */
    public function employmentStatus(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class);
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

    /**
     * @return BelongsTo<Country, $this>
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    /**
     * @return BelongsTo<ProbationDuration, $this>
     */
    public function probationDuration(): BelongsTo
    {
        return $this->belongsTo(ProbationDuration::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function indirectReportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'indirect_reports_to_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function indirectReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'indirect_reports_to_id');
    }

    /**
     * @return HasMany<EmployeeSpouse, $this>
     */
    public function spouses(): HasMany
    {
        return $this->hasMany(EmployeeSpouse::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EmployeeQualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EmployeeWorkExperience, $this>
     */
    public function workExperiences(): HasMany
    {
        return $this->hasMany(EmployeeWorkExperience::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Offboarding, $this>
     */
    public function offboardings(): HasMany
    {
        return $this->hasMany(Offboarding::class);
    }

    /**
     * The case still in progress, if any.
     *
     * @return HasOne<Offboarding, $this>
     */
    public function openOffboarding(): HasOne
    {
        return $this->hasOne(Offboarding::class)->whereNull('completed_at');
    }

    /** Whether this employee's employment status is a notice period. */
    public function onNotice(): bool
    {
        return $this->employment_status_id !== null
            && (bool) EmploymentStatus::query()->whereKey($this->employment_status_id)->value('is_notice_period');
    }

    /** Whether this employee's employment status means they have left (resigned, terminated…). */
    public function hasExited(): bool
    {
        return EmploymentStatus::isExitId($this->employment_status_id);
    }

    /**
     * Shift history, oldest first.
     *
     * @return HasMany<EmployeeShiftAssignment, $this>
     */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class)->orderBy('effective_from');
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
