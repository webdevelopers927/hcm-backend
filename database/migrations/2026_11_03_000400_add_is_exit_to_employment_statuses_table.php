<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Third status flag: the employee has left (resigned, terminated, retired…). Needed because "can't log in"
 * also covers statuses like Suspended, where the employee is still employed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_statuses', function (Blueprint $table) {
            $table->boolean('is_exit')->default(false)->after('is_notice_period');
        });

        DB::table('employment_statuses')
            ->whereIn('name', ['Resigned', 'Terminated', 'Retired', 'Inactive'])
            ->update(['is_exit' => true]);
    }

    public function down(): void
    {
        Schema::table('employment_statuses', function (Blueprint $table) {
            $table->dropColumn('is_exit');
        });
    }
};
