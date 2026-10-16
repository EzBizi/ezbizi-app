<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Airtable table plays which part in a business's books — its P&L, a
 * repair team's sheet, refunds, office expenses — and which of its columns is
 * the amount, the date, the company. Each organization maps its own; the
 * hourly Airtable copy and the reports read this instead of a constant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('base_id', 32);
            $table->string('table_id', 32);
            $table->string('role', 32);
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            // A table shared by several places: its rows where filter_field = filter_value are this place's.
            $table->string('filter_field')->nullable();
            $table->string('filter_value')->nullable();
            $table->json('field_map')->nullable();
            // Values, not columns: e.g. {"summary": "Weekly Summary", "skip": "Total"}.
            $table->json('markers')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'base_id', 'table_id', 'franchise_id'], 'financial_sources_place_unique');
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_sources');
    }
};
