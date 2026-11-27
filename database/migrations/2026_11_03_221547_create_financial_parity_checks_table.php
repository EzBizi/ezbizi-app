<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day's comparison of one Airtable table against its EzBizi copy
 * (ezbizi:airtable-parity, plan docs/plans/2026-11-03-airtable-retirement.md
 * section 4). A base may switch to EzBizi after 14 green days in a row.
 * `diffs` holds whatever checks that table's role runs (counts, monthly cents,
 * report output; P&L weeks later), so a new check needs no new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_parity_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_base_id', 64);
            $table->string('source_table_id', 64);
            $table->string('table_name')->nullable();
            $table->date('day');
            $table->boolean('green');
            $table->boolean('cut_over')->default(false);
            $table->unsignedInteger('airtable_rows')->default(0);
            $table->unsignedInteger('ezbizi_rows')->default(0);
            $table->json('diffs')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source_base_id', 'source_table_id', 'day'], 'financial_parity_checks_one_a_day');
            $table->index(['organization_id', 'source_base_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_parity_checks');
    }
};
