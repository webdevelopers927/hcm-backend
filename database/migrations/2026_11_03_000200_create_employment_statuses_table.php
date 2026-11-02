<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employment statuses become a managed list (Setup → Employment statuses) instead of a hardcoded enum.
 * Each status says whether its employees may log in and whether it is a notice period. Existing
 * employees keep their status: the five old values become rows and employees.status → employment_status_id.
 */
return new class extends Migration
{
    /**
     * Old enum value => [name, can_login, is_notice_period, sort_order]. The sort order matches the
     * position in LookupSeeder::EMPLOYMENT_STATUSES, which adds the other statuses around these.
     */
    private const LEGACY = [
        'active' => ['Active', true, false, 0],
        'probation' => ['Probation', true, false, 1],
        'on_leave' => ['On leave', true, false, 2],
        'notice_period' => ['Notice period', true, true, 3],
        'inactive' => ['Inactive', false, false, 9],
    ];

    public function up(): void
    {
        Schema::create('employment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('can_login')->default(true);
            $table->boolean('is_notice_period')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $ids = [];
        foreach (self::LEGACY as $value => [$name, $canLogin, $notice, $order]) {
            $ids[$value] = DB::table('employment_statuses')->insertGetId([
                'name' => $name,
                'can_login' => $canLogin,
                'is_notice_period' => $notice,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('employment_status_id')->nullable()->after('work_mode')->constrained()->restrictOnDelete();
        });

        foreach ($ids as $value => $id) {
            DB::table('employees')->where('status', $value)->update(['employment_status_id' => $id]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('work_mode')->index();
        });

        foreach (self::LEGACY as $value => [$name]) {
            $id = DB::table('employment_statuses')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('employees')->where('employment_status_id', $id)->update(['status' => $value]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_status_id');
        });
        Schema::dropIfExists('employment_statuses');
    }
};
