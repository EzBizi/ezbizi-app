<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guidelines (Jose, 2026-10-14): cards for situations a team meets, each
 * opening to step-by-step instructions. Owned like assistant_entries:
 * organization, then optionally a company, then optionally a franchise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guidelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
            $table->string('title', 120);
            $table->string('summary', 255)->nullable();
            // #RRGGBB, the card's accent.
            $table->string('color', 7)->nullable();
            $table->string('image_path')->nullable();
            // An ordered list of strings.
            $table->json('steps');
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guidelines');
    }
};
