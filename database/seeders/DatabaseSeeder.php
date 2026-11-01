<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (development data).
     */
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            AdminUserSeeder::class,
            EmployeeSeeder::class,
        ]);
    }
}
