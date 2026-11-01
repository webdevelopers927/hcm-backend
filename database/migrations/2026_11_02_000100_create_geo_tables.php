<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * World geography, seeded from the dr5hn countries-states-cities dataset (ODbL). Ids are the dataset's own,
 * so re-seeding keeps foreign keys stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100)->index();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->nullable();
            $table->string('phone_code', 10)->nullable();
            $table->string('nationality', 100)->nullable()->index();
            $table->string('emoji', 16)->nullable();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('country_id');
            $table->string('name', 150);
            $table->string('code', 10)->nullable();

            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            $table->index(['country_id', 'name']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('state_id');
            $table->unsignedInteger('country_id');
            $table->string('name', 150);

            $table->foreign('state_id')->references('id')->on('states')->cascadeOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            $table->index(['state_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
