<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Credentials come from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD in .env, never from code.
     */
    public function run(): void
    {
        $email = config('hcm.seed_admin.email');
        $password = config('hcm.seed_admin.password');

        if (! $email || ! $password) {
            $this->command->warn('SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD not set: admin user skipped.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => strtolower((string) $email)]);
        $user->forceFill([
            'name' => config('hcm.seed_admin.name'),
            'password' => $password,
            'email_verified_at' => now(),
        ])->save();

        $this->command->info("Admin user ready: {$user->email}");
    }
}
