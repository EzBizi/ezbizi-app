<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google review a review link most likely produced (Jose, 2026-11-09: a
 * fifth funnel step, "Reviewed"). Google never says, so it is inferred by name,
 * place and time, scored, and a person may confirm or reject it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_link_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('review_id')->unique();
            $table->uuid('job_id');
            $table->uuid('customer_id');
            $table->string('confidence', 10); // exact | likely | possible
            $table->boolean('clicked');        // false: reviewed without clicking the link
            $table->string('status', 10)->default('auto'); // auto | confirmed | rejected
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['organization_id', 'sent_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_link_matches');
    }
};
