<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer did with their review link (Jose, 2026-10-15): opened it,
 * tapped the green face or the sad one, sent the complaint form, went on to
 * Google. One row per link and kind, so a count is a count of customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_link_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('customer_id');
            $table->uuid('job_id')->nullable();
            $table->string('kind', 16);
            $table->timestamp('occurred_at');

            $table->unique(['customer_id', 'job_id', 'kind']);
            $table->index(['organization_id', 'occurred_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_link_events');
    }
};
