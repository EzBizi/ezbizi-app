<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-1's Workiz phone calls (GET /v2/calls) and the scores Workiz's own AI
 * gives them (Jose via Grok AI, 2026-10-12: "Yes, pull Workiz's scores"). v2
 * gives no recording and no transcript, only who, when, how long, and
 * `insights` on the branches Workiz scores (DMV, Illinois, New York).
 *
 * Its own table, not workiz_records: it holds phone numbers, so it is kept a
 * year like the other calls, where the money records are kept for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('franchise_id');
            $table->uuid('connection_id')->nullable();
            $table->string('remote_id', 64);

            $table->string('direction', 16)->nullable();
            $table->string('from_number', 32)->nullable();
            $table->string('to_number', 32)->nullable();
            $table->unsignedInteger('duration')->nullable();
            // answered, missed, voicemail, active, geniusAnswering.
            $table->string('status', 32)->nullable();
            $table->string('flow_name')->nullable();

            // CL-, JOB-, LD- as Workiz names them; job_id is the EzBizi job.
            $table->string('client_remote_id', 64)->nullable()->index();
            $table->string('job_remote_id', 64)->nullable()->index();
            $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('lead_remote_id', 64)->nullable();

            // Who answered it, or placed it (USR-), and their name in Workiz.
            $table->string('user_remote_id', 64)->nullable()->index();
            $table->string('user_name')->nullable();

            // Workiz's insights, where it scores the branch.
            $table->string('sentiment', 16)->nullable();
            $table->string('intent', 32)->nullable();
            $table->unsignedTinyInteger('dispatcher_score')->nullable();
            $table->string('objection', 32)->nullable();
            $table->boolean('pricing_discussed')->nullable();
            $table->boolean('booked')->nullable();

            // The account's own wall clock, as Workiz writes it.
            $table->dateTime('called_at')->nullable();
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique(['franchise_id', 'remote_id']);
            $table->index(['franchise_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_calls');
    }
};
