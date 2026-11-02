<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated shift history. employees.shift_id stays the shift in force today (kept in step by
 * AssignShift and the daily shifts:apply-scheduled command); this table records every change, including
 * ones scheduled for the future.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Set once the change has been copied to employees.shift_id; null = scheduled for later.
            $table->timestamp('applied_at')->nullable()->index();
            $table->timestamps();

            // One change per employee per day; assigning again on the same day replaces it.
            $table->unique(['employee_id', 'effective_from']);
            $table->index(['shift_id', 'effective_from']);
        });

        // Existing employees: their current shift, effective from when they joined.
        DB::table('employees')
            ->where('is_draft', false)->whereNotNull('shift_id')
            ->orderBy('id')
            ->select(['id', 'shift_id', 'join_date', 'created_at'])
            ->chunk(500, function ($employees) {
                DB::table('employee_shift_assignments')->insert($employees->map(fn (object $e) => [
                    'employee_id' => $e->id,
                    'shift_id' => $e->shift_id,
                    'effective_from' => $e->join_date ?? substr((string) $e->created_at, 0, 10),
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_assignments');
    }
};
