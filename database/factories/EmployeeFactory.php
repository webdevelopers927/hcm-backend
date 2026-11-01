<?php

namespace Database\Factories;

use App\Enums\DutyType;
use App\Enums\EmployeeStatus;
use App\Enums\Gender;
use App\Enums\WorkMode;
use App\Models\Campaign;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    private const MALE = ['Ahmed', 'Ali', 'Hassan', 'Usman', 'Bilal', 'Hamza', 'Faisal', 'Imran', 'Kamran', 'Zain',
        'Saad', 'Omer', 'Danish', 'Waqas', 'Asad', 'Junaid', 'Taimur', 'Fahad', 'Haris', 'Shahzaib', 'Adeel', 'Noman'];

    private const FEMALE = ['Fatima', 'Ayesha', 'Zainab', 'Maryam', 'Hira', 'Sana', 'Mahnoor', 'Amna', 'Iqra', 'Rabia',
        'Sadia', 'Nimra', 'Kiran', 'Mehwish', 'Anum', 'Saba', 'Areeba', 'Laiba', 'Hafsa', 'Khadija', 'Alina', 'Eman'];

    private const LAST = ['Khan', 'Javed', 'Malik', 'Qureshi', 'Siddiqui', 'Butt', 'Chaudhry', 'Sheikh', 'Raza', 'Hussain',
        'Iqbal', 'Ahmed', 'Mirza', 'Abbasi', 'Rana', 'Shah', 'Hashmi', 'Baig', 'Aslam', 'Nawaz', 'Farooq', 'Anwar'];

    /** @var array<string, int> */
    private static array $usedEmails = [];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement([Gender::Male, Gender::Female]);
        $first = fake()->randomElement($gender === Gender::Male ? self::MALE : self::FEMALE);
        $last = fake()->randomElement(self::LAST);

        $designation = Designation::query()->whereNotNull('department_id')->inRandomOrder()->first()
            ?? Designation::create([
                'name' => 'Associate',
                'department_id' => Department::query()->firstOrCreate(['name' => 'General'])->id,
            ]);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'pseudo_name' => fake()->boolean(35) ? $first : null,
            'email' => self::uniqueEmail($first, $last),
            'phone' => '+92 3'.fake()->numerify('##-#######'),
            'gender' => $gender,
            'birth_date' => fake()->dateTimeBetween('-55 years', '-21 years')->format('Y-m-d'),
            'cnic' => fake()->randomElement(['3', '4', '6']).fake()->unique()->numerify('####-#######-#'),
            'machine_uid' => (string) fake()->unique()->numberBetween(2400, 9999),
            'designation_id' => $designation->id,
            'department_id' => $designation->department_id,
            'division_id' => Division::query()->inRandomOrder()->value('id'),
            'campaign_id' => fake()->boolean(70) ? Campaign::query()->inRandomOrder()->value('id') : null,
            'shift_id' => Shift::query()->inRandomOrder()->value('id'),
            'location_id' => Location::query()->inRandomOrder()->value('id'),
            'work_mode' => fake()->randomElement([WorkMode::Onsite, WorkMode::Onsite, WorkMode::Hybrid, WorkMode::Hybrid, WorkMode::Remote]),
            'status' => fake()->randomElement([
                ...array_fill(0, 13, EmployeeStatus::Active),
                ...array_fill(0, 3, EmployeeStatus::Probation),
                ...array_fill(0, 2, EmployeeStatus::OnLeave),
                EmployeeStatus::NoticePeriod,
                EmployeeStatus::Inactive,
            ]),
            'join_date' => fake()->dateTimeBetween('-6 years', '-1 week')->format('Y-m-d'),
            'duty_type' => fake()->randomElement([...array_fill(0, 8, DutyType::FullTime), DutyType::Contract, DutyType::PartTime]),
        ];
    }

    /** Can have people reporting to them. */
    public function teamLead(): static
    {
        return $this->state(['has_team_authority' => true]);
    }

    /** An unfinished draft: no code, no login account, most fields still empty. */
    public function draft(): static
    {
        return $this->state(fn () => [
            'is_draft' => true,
            'status' => null,
            'duty_type' => null,
            'cnic' => null,
            'join_date' => null,
        ]);
    }

    private static function uniqueEmail(string $first, string $last): string
    {
        $base = Str::lower($first.'.'.$last);
        $n = self::$usedEmails[$base] = (self::$usedEmails[$base] ?? 0) + 1;

        return ($n === 1 ? $base : $base.$n).'@company.pk';
    }

    /** Linked login account (role/permissions come later). */
    public function withAccount(?bool $twoFactor = null): static
    {
        return $this->afterCreating(function (Employee $employee) use ($twoFactor) {
            $user = User::factory()->create([
                'name' => $employee->full_name,
                'email' => $employee->email,
            ]);
            $user->forceFill(['two_factor_enabled' => $twoFactor ?? fake()->boolean(60)])->save();
            $employee->user()->associate($user)->saveQuietly();
        });
    }

    public function withDevice(): static
    {
        return $this->afterCreating(function (Employee $employee) {
            $android = fake()->boolean();
            $employee->devices()->create([
                'device_id' => (string) Str::uuid(),
                'model' => $android ? fake()->randomElement(['Samsung Galaxy A54', 'Infinix Note 30', 'Tecno Camon 20', 'Xiaomi Redmi Note 12'])
                    : fake()->randomElement(['iPhone 13', 'iPhone 14', 'iPhone 15']),
                'platform' => $android ? 'android' : 'ios',
                'bound_at' => fake()->dateTimeBetween('-6 months', '-1 day'),
            ]);
        });
    }
}
