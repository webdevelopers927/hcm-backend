<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employment contracts for contractual employees (duty type flagged is_contractual). When a contract ends
 * without a renewal the employee is moved to the "Contract expired" status (no sign-in); renewing restores
 * their previous status. See ContractLifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('leave_entitled')->default(true);
            $table->text('notes')->nullable();
            // Renewal chain: the new contract points at the one it replaced; the old one gets renewed_at.
            $table->foreignId('renewed_from_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->timestamp('renewed_at')->nullable();
            // Set when this contract's end locked the employee out; their status before that, for restoring.
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('status_before_expiry_id')->nullable()->constrained('employment_statuses')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index('end_date');
        });

        // The status an employee is moved to when their contract runs out: no sign-in, but not an exit.
        if (! DB::table('employment_statuses')->where('name', 'Contract expired')->exists()) {
            DB::table('employment_statuses')->insert([
                'name' => 'Contract expired',
                'can_login' => false,
                'is_notice_period' => false,
                'is_exit' => false,
                'sort_order' => (int) DB::table('employment_statuses')->max('sort_order') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
