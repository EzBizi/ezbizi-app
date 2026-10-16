<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Financials table can mirror one table of the Airtable copy (Reports'
 * airtable_records). A mirrored table is read-only: Airtable stays the place
 * it is typed into until the business moves its bookkeeping here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_tables', function (Blueprint $table) {
            $table->string('source_base_id', 32)->nullable()->after('name');
            $table->string('source_table_id', 32)->nullable()->after('source_base_id');
            $table->timestamp('synced_at')->nullable()->after('source_table_id');
        });

        Schema::table('financial_fields', function (Blueprint $table) {
            $table->string('source_name')->nullable()->after('name');
        });

        Schema::table('financial_records', function (Blueprint $table) {
            $table->string('source_record_id', 32)->nullable()->after('table_id');
            $table->unique(['table_id', 'source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_records', function (Blueprint $table) {
            $table->dropUnique(['table_id', 'source_record_id']);
            $table->dropColumn('source_record_id');
        });
        Schema::table('financial_fields', fn (Blueprint $table) => $table->dropColumn('source_name'));
        Schema::table('financial_tables', fn (Blueprint $table) => $table->dropColumn(['source_base_id', 'source_table_id', 'synced_at']));
    }
};
