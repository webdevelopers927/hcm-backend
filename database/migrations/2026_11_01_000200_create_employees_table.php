<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Derived from the id right after insert (EMP-0001); nullable only for that instant.
            $table->string('code', 20)->nullable()->unique();
            $table->string('machine_uid', 50)->nullable()->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('pseudo_name', 100)->nullable()->index();
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('cnic')->nullable();
            // HMAC blind index of the normalised CNIC, so uniqueness can be enforced on encrypted data.
            $table->char('cnic_hash', 64)->nullable()->unique();

            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('work_mode', 20)->default('onsite')->index();
            $table->string('status', 20)->default('active')->index();
            $table->date('join_date')->index();
            $table->string('avatar_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['first_name', 'last_name']);
            $table->index('last_name');
        });

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

        Schema::create('employee_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_id');
            $table->string('model')->nullable();
            $table->string('platform', 20)->nullable();
            $table->timestamp('bound_at');
            // Unbinding keeps the row as history; the active device has unbound_at = null.
            $table->timestamp('unbound_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'unbound_at']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_devices');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employees');
    }
};
