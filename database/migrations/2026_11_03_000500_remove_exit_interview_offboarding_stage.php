<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "exit interview" offboarding stage was dropped; cases parked there go back to clearance.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offboardings')->where('stage', 'exit_interview')->update(['stage' => 'clearance']);
    }

    public function down(): void
    {
        // Nothing to restore: which cases were at the exit interview stage isn't recorded.
    }
};
