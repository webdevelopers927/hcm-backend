<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full employee form (personal, positional, contact, other) plus drafts. A draft may leave any field
 * empty, so the columns that are required for a real employee become nullable here; completeness is
 * enforced in the app (EmployeeCompleteness) when a draft is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->change();
            $table->string('last_name', 100)->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->date('join_date')->nullable()->change();
            $table->string('status', 20)->nullable()->default(null)->change();

            $table->boolean('is_draft')->default(false)->after('code')->index();
            $table->foreignId('created_by_id')->nullable()->after('is_draft')->constrained('users')->nullOnDelete();

            // Personal
            $table->string('salutation', 10)->nullable()->after('machine_uid');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('father_husband_name', 200)->nullable()->after('pseudo_name');
            $table->date('cnic_expiry')->nullable()->after('cnic_hash');
            $table->string('personal_email')->nullable()->after('email');
            $table->unsignedInteger('country_id')->nullable()->after('cnic_expiry');
            $table->unsignedInteger('state_id')->nullable()->after('country_id');
            $table->unsignedInteger('city_id')->nullable()->after('state_id');
            $table->unsignedInteger('nationality_id')->nullable()->after('city_id');
            $table->string('marital_status', 20)->nullable()->after('nationality_id');
            $table->string('religion', 30)->nullable()->after('marital_status');
            $table->string('blood_group', 5)->nullable()->after('religion');

            // Positional
            $table->foreignId('indirect_reports_to_id')->nullable()->after('reports_to_id')->constrained('employees')->nullOnDelete();
            $table->string('duty_type', 20)->nullable()->after('status')->index();
            $table->date('probation_start_date')->nullable()->after('join_date');
            $table->foreignId('probation_duration_id')->nullable()->after('probation_start_date')->constrained()->nullOnDelete();
            $table->date('confirmation_date')->nullable()->after('probation_duration_id');
            $table->date('separation_date')->nullable()->after('confirmation_date');
            $table->boolean('has_team_authority')->default(false)->after('separation_date');
            $table->boolean('remote_access')->default(false)->after('has_team_authority');
            $table->boolean('permanent_remote_access')->default(false)->after('remote_access');
            $table->date('remote_access_from')->nullable()->after('permanent_remote_access');
            $table->date('remote_access_until')->nullable()->after('remote_access_from');

            // Contact (`phone` is the primary contact number)
            $table->string('secondary_phone', 30)->nullable()->after('phone');
            $table->string('landline', 30)->nullable()->after('secondary_phone');
            $table->text('permanent_address')->nullable()->after('landline');
            $table->text('present_address')->nullable()->after('permanent_address');
            $table->string('emergency_contact_name', 200)->nullable()->after('present_address');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relation', 20)->nullable()->after('emergency_contact_phone');

            // Other (encrypted, like the CNIC)
            $table->text('passport_no')->nullable()->after('emergency_contact_relation');
            $table->date('passport_expiry')->nullable()->after('passport_no');
            $table->text('driving_license_no')->nullable()->after('passport_expiry');
            $table->date('driving_license_expiry')->nullable()->after('driving_license_no');

            // Work experience toggle; the experiences themselves live in employee_work_experiences.
            $table->boolean('has_work_experience')->default(false)->after('driving_license_expiry');

            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('nationality_id')->references('id')->on('countries')->nullOnDelete();
        });

        // Existing employees predate these fields: treat them as full-time, and anyone who already
        // manages people keeps the authority to do so.
        DB::table('employees')->update(['duty_type' => 'full_time']);
        DB::table('employees')
            ->whereIn('id', DB::table('employees')->whereNotNull('reports_to_id')->select('reports_to_id'))
            ->update(['has_team_authority' => true]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['nationality_id']);
            $table->dropConstrainedForeignId('indirect_reports_to_id');
            $table->dropConstrainedForeignId('probation_duration_id');
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropIndex(['is_draft']);
            $table->dropIndex(['duty_type']);
            $table->dropColumn([
                'is_draft', 'salutation', 'middle_name', 'father_husband_name', 'cnic_expiry', 'personal_email',
                'country_id', 'state_id', 'city_id', 'nationality_id', 'marital_status', 'religion', 'blood_group',
                'duty_type', 'probation_start_date', 'confirmation_date', 'separation_date', 'has_team_authority',
                'remote_access', 'permanent_remote_access', 'remote_access_from', 'remote_access_until',
                'secondary_phone', 'landline', 'permanent_address', 'present_address', 'emergency_contact_name',
                'emergency_contact_phone', 'emergency_contact_relation', 'passport_no', 'passport_expiry',
                'driving_license_no', 'driving_license_expiry', 'has_work_experience',
            ]);
        });

        // Drafts cannot satisfy the old NOT NULL columns.
        DB::table('employees')->whereNull('email')->orWhereNull('first_name')->orWhereNull('join_date')->delete();

        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable(false)->change();
            $table->string('last_name', 100)->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->date('join_date')->nullable(false)->change();
            $table->string('status', 20)->nullable(false)->default('active')->change();
        });
    }
};
