<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary and bank details move to the payroll module, which will define its own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('employee_bank_accounts');
    }

    public function down(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 120);
            $table->string('account_title', 200)->nullable();
            $table->text('iban')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross_salary', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });
    }
};
