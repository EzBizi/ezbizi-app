<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates, inside Jobs (Jose, 2026-09-29: "build it, but as a part of Jobs").
 *
 * A1 Tools made the estimates A-1 has (2,388, $5.85M quoted); once it is gone
 * EzBizi is their only home. `source`, `external_ref` and `import_batch` are
 * for that import, created here so it needs no second migration: a batch can
 * be rolled back, and a re-run skips what is already in.
 *
 * Spec: docs/superpowers/specs/2026-09-29-jobs-billing-design.md, Phase 1 §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('number', 32);
            $table->string('status', 16)->default('draft');
            $table->date('issued_on')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('note')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('source', 16)->default('ezbizi');
            $table->string('external_ref', 64)->nullable();
            $table->uuid('import_batch')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'source', 'external_ref']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('estimate_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_lines');
        Schema::dropIfExists('estimates');
    }
};
