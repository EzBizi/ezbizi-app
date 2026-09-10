<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report somebody composed and named (spec 2026-09-10).
 *
 * The definition is JSON rather than columns because its shape is the
 * builder's, and the builder will grow. What must NOT be in it is scope: the
 * place lives in company_id / franchise_id where every other owned row keeps
 * it, so the existing scoping helpers apply unchanged and a definition can
 * never talk its way into another company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()
                ->constrained('franchises')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('definition');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
