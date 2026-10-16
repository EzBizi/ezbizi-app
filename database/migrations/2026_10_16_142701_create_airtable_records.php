<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A copy of A-1's Airtable bases inside EzBizi (the A1 Leads takeover, Jose
 * 2026-10-16: "it's time to do the Takeover on A1Leads"). The reports read this
 * copy, not Airtable live, and every change to a row is kept as a version so a
 * past week can be rebuilt as it stood.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airtable_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('base_id', 32);
            $table->string('table_id', 32);
            $table->string('table_name');
            $table->string('record_id', 32);
            $table->json('fields');
            $table->char('fields_hash', 64);
            $table->timestamp('record_created_at')->nullable();
            // Last seen in Airtable; a row gone from Airtable keeps its copy with gone_at set.
            $table->timestamp('fetched_at');
            $table->timestamp('gone_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'base_id', 'table_id', 'record_id'], 'airtable_records_unique');
            $table->index(['organization_id', 'table_name']);
        });

        Schema::create('airtable_record_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('airtable_record_id')->constrained()->cascadeOnDelete();
            $table->json('fields');
            $table->timestamp('seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airtable_record_versions');
        Schema::dropIfExists('airtable_records');
    }
};
