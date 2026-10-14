<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financials (Jose, 2026-10-14): the Airtable replacement. A base holds
 * tables; a table holds typed fields, records and saved views. A record's
 * cells live in one JSON column keyed by field id, which is what lets a
 * person add a column without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_bases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('colour', 16)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('financial_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('base_id')->constrained('financial_bases')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('financial_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('financial_tables')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24);
            $table->json('options')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('financial_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('financial_tables')->cascadeOnDelete();
            $table->json('values');
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['table_id', 'position']);
        });

        Schema::create('financial_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('financial_tables')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16); // grid | kanban | calendar | gallery | form
            $table->json('config')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_views');
        Schema::dropIfExists('financial_records');
        Schema::dropIfExists('financial_fields');
        Schema::dropIfExists('financial_tables');
        Schema::dropIfExists('financial_bases');
    }
};
