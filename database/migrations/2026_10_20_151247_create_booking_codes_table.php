<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The short booking link's code (Jose, 2026-10-20: "make the booking links
 * shorter like we did with the review links"): https://www.angisweep.com/b/Ab3xK9m
 * instead of ~130 characters of signed token. Seven base62 characters, random,
 * standing for a lead until they expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_codes', function (Blueprint $table) {
            $table->char('code', 7)->primary()->collation('utf8mb4_bin');
            $table->uuid('organization_id');
            $table->uuid('lead_id');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index('lead_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_codes');
    }
};
