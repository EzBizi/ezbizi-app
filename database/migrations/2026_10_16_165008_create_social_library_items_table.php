<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social Media > the standard media library (A1 poster takeover, 2026-10-16):
 * the pictures and videos a company posts again and again, kept once and
 * picked in Compose. XOR-scoped like newsletters (§3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_library_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('name');
            $table->string('disk');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_library_items');
    }
};
