<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duty types become a managed list (Setup → Duty types) instead of a hardcoded enum, each flagged as
 * contractual or not. Existing employees keep theirs: employees.duty_type → duty_type_id.
 */
return new class extends Migration
{
    /** Old enum value => [name, is_contractual]. Order = display order. */
    private const LEGACY = [
        'full_time' => ['Full-time', false],
        'part_time' => ['Part-time', false],
        'contract' => ['Contract', true],
        'freelance' => ['Freelance', true],
        'internship' => ['Internship', true],
        'temporary' => ['Temporary', true],
        'consultant' => ['Consultant', true],
    ];

    public function up(): void
    {
        Schema::create('duty_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_contractual')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $ids = [];
        $order = 0;
        foreach (self::LEGACY as $value => [$name, $contractual]) {
            $ids[$value] = DB::table('duty_types')->insertGetId([
                'name' => $name,
                'is_contractual' => $contractual,
                'sort_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('duty_type_id')->nullable()->after('employment_status_id')->constrained()->restrictOnDelete();
        });

        foreach ($ids as $value => $id) {
            DB::table('employees')->where('duty_type', $value)->update(['duty_type_id' => $id]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['duty_type']);
            $table->dropColumn('duty_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('duty_type', 20)->nullable()->after('employment_status_id')->index();
        });

        foreach (self::LEGACY as $value => [$name]) {
            $id = DB::table('duty_types')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('employees')->where('duty_type_id', $id)->update(['duty_type' => $value]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duty_type_id');
        });
        Schema::dropIfExists('duty_types');
    }
};
