<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeating sections of the employee form. Each list keeps the order the user entered it in
 * (sort_order), so validation errors like "work_experiences.1.company_name" point at the right row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_spouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('full_name', 200)->nullable();
            $table->text('cnic')->nullable();
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });

        Schema::create('employee_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_spouse_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('full_name', 200)->nullable();
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index(['employee_spouse_id', 'sort_order']);
        });

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('institution', 200)->nullable();
            $table->foreignId('degree_program_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });

        Schema::create('employee_work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('company_name', 200)->nullable();
            $table->string('designation', 150)->nullable();
            $table->decimal('last_salary', 12, 2)->nullable();
            $table->unsignedInteger('country_id')->nullable();
            $table->unsignedInteger('state_id')->nullable();
            $table->unsignedInteger('city_id')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('contact_person_name', 200)->nullable();
            $table->string('contact_person_email')->nullable();
            $table->string('contact_person_phone', 30)->nullable();
            $table->string('experience_letter_path')->nullable();
            $table->string('experience_letter_name')->nullable();
            $table->string('experience_letter_mime', 150)->nullable();
            $table->unsignedBigInteger('experience_letter_size')->nullable();
            $table->text('reason_for_leaving')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->index(['employee_id', 'sort_order']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title', 150)->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_work_experiences');
        Schema::dropIfExists('employee_qualifications');
        Schema::dropIfExists('employee_children');
        Schema::dropIfExists('employee_spouses');
    }
};
