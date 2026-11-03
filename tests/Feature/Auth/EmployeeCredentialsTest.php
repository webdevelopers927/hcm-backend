<?php

use App\Jobs\SendEmployeeCredentials;
use App\Mail\EmployeeCredentials;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use App\Support\PasswordGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Mail::fake());

/** Hire someone through the API and return the password that was emailed to them. */
function hireAndCapturePassword(string $email): string
{
    $designation = Designation::whereNotNull('department_id')->firstOrFail();
    test()->postJson('/api/v1/employees', [
        'first_name' => 'Sana', 'last_name' => 'Tariq', 'email' => $email, 'cnic' => '35202-5555555-1',
        'department_id' => $designation->department_id, 'designation_id' => $designation->id,
        'employment_status_id' => statusId('Probation'), 'duty_type_id' => dutyTypeId('Full-time'), 'join_date' => '2026-11-03',
    ])->assertCreated();

    $password = null;
    Mail::assertSent(EmployeeCredentials::class, function (EmployeeCredentials $mail) use ($email, &$password) {
        $password = $mail->password;

        return $mail->hasTo($email);
    });

    return (string) $password;
}

it('generates 10-character passwords of letters, digits and %, with at least one of each', function () {
    foreach (range(1, 300) as $_) {
        $password = PasswordGenerator::generate();

        expect($password)->toMatch('/^[A-Za-z0-9%]{10}$/')
            ->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->toContain('%')
            ->not->toMatch('/[0O1lI]/');
    }

    expect(collect(range(1, 50))->map(fn () => PasswordGenerator::generate())->unique())->toHaveCount(50);
});

it('emails a new employee a random password they can sign in with', function () {
    actingAsUser();
    $password = hireAndCapturePassword('sana.tariq@company.pk');

    auth()->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'sana.tariq@company.pk', 'password' => $password])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);
    $this->postJson('/api/v1/auth/login', ['email' => 'sana.tariq@company.pk', 'password' => 'wrong-pass%1'])
        ->assertStatus(422);
});

it('renders the email with the sign-in details and the brand', function () {
    $html = (new EmployeeCredentials('Sana Tariq', 'sana@company.pk', 'Ab3%kP9qRz', 'EMP-0042'))->render();

    expect($html)->toContain('Welcome to Kaarvan, Sana Tariq')
        ->toContain('sana@company.pk')
        ->toContain('Ab3%kP9qRz')
        ->toContain('EMP-0042')
        ->toContain('http://localhost:3000/login');
    expect((new EmployeeCredentials('Sana', 'sana@company.pk', 'x', null))->envelope()->subject)
        ->toBe('Welcome to Kaarvan: your sign-in details');
});

it('never queues the mail itself, so the plain password stays out of the jobs table', function () {
    expect(new EmployeeCredentials('a', 'b', 'c'))->not->toBeInstanceOf(ShouldQueue::class)
        ->and(serialize(new SendEmployeeCredentials(1, 2)))->not->toContain('password');
});

it('stores only a hash of the password', function () {
    actingAsUser();
    $password = hireAndCapturePassword('hash.check@company.pk');

    $stored = User::where('email', 'hash.check@company.pk')->value('password');
    expect($stored)->not->toBe($password)->toStartWith('$2y$');
});

it('resends a new password and signs out existing sessions', function () {
    actingAsUser();
    $old = hireAndCapturePassword('resend@company.pk');
    $employee = Employee::where('email', 'resend@company.pk')->firstOrFail();
    User::findOrFail($employee->user_id)->createToken('phone');

    $this->postJson("/api/v1/employees/{$employee->id}/credentials/resend")
        ->assertOk()
        ->assertJsonPath('message', 'A new password is on its way to resend@company.pk.');

    $new = null;
    Mail::assertSent(EmployeeCredentials::class, function (EmployeeCredentials $mail) use (&$new) {
        if ($mail->isReset) {
            $new = $mail->password;
        }

        return $mail->isReset;
    });
    expect($new)->not->toBe($old)
        ->and(User::findOrFail($employee->user_id)->tokens()->count())->toBe(0)
        ->and(Activity::where('event', 'credentials_resent')->count())->toBe(1);

    auth()->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'resend@company.pk', 'password' => $old])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'resend@company.pk', 'password' => $new])->assertOk();
});

it('refuses to resend for drafts and statuses that may not sign in', function () {
    actingAsUser();
    $draft = Employee::factory()->draft()->create();
    $suspended = Employee::factory()->inStatus('Suspended')->withAccount()->create();

    $this->postJson("/api/v1/employees/{$draft->id}/credentials/resend")->assertStatus(422)->assertJsonValidationErrors(['employee']);
    $this->postJson("/api/v1/employees/{$suspended->id}/credentials/resend")->assertStatus(422)->assertJsonValidationErrors(['employee']);
    Mail::assertNothingSent();
});
