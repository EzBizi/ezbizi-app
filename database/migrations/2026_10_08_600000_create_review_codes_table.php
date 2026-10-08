<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The short review link's code (Jose, 2026-10-08): https://ma.a-1chimney.com/r/Ab3xK9m
 * instead of ~140 characters of signed token in a text message. Seven base62
 * characters, random, standing for a customer and a job until they expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_codes', function (Blueprint $table) {
            $table->char('code', 7)->primary()->collation('utf8mb4_bin');
            $table->uuid('organization_id');
            $table->uuid('customer_id');
            $table->uuid('job_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'job_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_codes');
    }
};
