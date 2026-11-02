<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding. The exit dates live on the employee (required while their employment status is a notice
 * period); an offboarding case carries the workflow: exit type, stage, checklist and the status the
 * employee moves to after their last working day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('resigned_date')->nullable()->after('separation_date');
            $table->date('last_working_day')->nullable()->after('resigned_date')->index();
            $table->text('reason_for_resignation')->nullable()->after('last_working_day');
        });

        Schema::create('offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('exit_type', 20);
            $table->string('stage', 20)->index();
            // Restored when the case is cancelled (e.g. a withdrawn resignation).
            $table->foreignId('previous_employment_status_id')->nullable()->constrained('employment_statuses')->nullOnDelete();
            // Where the employee goes the day after their last working day.
            $table->foreignId('final_employment_status_id')->nullable()->constrained('employment_statuses')->nullOnDelete();
            $table->timestamp('exited_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'completed_at']);
        });

        Schema::create('offboarding_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('label', 200);
            $table->string('owner', 50)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_checklist_items');
        Schema::dropIfExists('offboardings');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['last_working_day']);
            $table->dropColumn(['resigned_date', 'last_working_day', 'reason_for_resignation']);
        });
    }
};
