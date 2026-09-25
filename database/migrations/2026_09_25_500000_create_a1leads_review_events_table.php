<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per NEW Google review of A-1 Chimney's that EzBizi raised to
 * Automations (`a1leads_gbp.review_received`).
 *
 * The unique pair is the "once per review" rule made structural: the watcher
 * raises the event only when its insert creates the row, in the same
 * transaction, so no path - a re-installed snapshot, a rollback, two runs at
 * once - can answer one review twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a1leads_review_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('company_id');
            $table->string('google_review_name', 300);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('reviewer_name', 200)->nullable();
            $table->string('location_name', 200)->nullable();
            $table->string('location_address', 300)->nullable();
            $table->boolean('has_reply')->default(false);
            $table->timestamp('review_created_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'google_review_name'], 'a1leads_review_event_unique');
            $table->index(['organization_id', 'review_created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a1leads_review_events');
    }
};
