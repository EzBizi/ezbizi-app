<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing > QR Codes (Jose, 2026-10-05): a code printed once — on a van — whose
 * destination can change for ever after.
 *
 * The printed thing is `code`, through /q/{code}; `destination_url` is the only
 * part that moves. XOR-scoped like newsletters and social connections (§3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            // Unique across ALL tenants: the public redirect has no tenant to
            // narrow by, only the code in the URL.
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('destination_url', 2048);

            $table->unsignedBigInteger('scans')->default(0);
            $table->timestamp('last_scanned_at')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
