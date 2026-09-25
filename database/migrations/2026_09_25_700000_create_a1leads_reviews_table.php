<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live copy of A-1 Chimney's Google reviews, kept in step with A1 Leads'
 * change feed (`/api/v1/ezbizi/reviews/delta`) by `ezbizi:a1leads-delta-sync`.
 *
 * It replaces the once-a-day snapshot as what the A1Leads GBP tab reads, so a
 * review, a reply, a card change or a removal shows within a minute rather
 * than the next morning (Rodrigo's cutover checklist, check 3).
 *
 * One row per Google review, upserted by its exact name in feed order. The
 * columns are what the tab filters and sorts on; the rest of A1 Leads'
 * projection is kept whole in the JSON columns, so the screen draws what A1
 * Leads sent rather than what this table happened to pick out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a1leads_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('google_review_name', 300);
            $table->unsignedBigInteger('a1_review_id')->nullable();
            $table->string('gbp_location_name', 200)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('reviewer_name', 200)->nullable();
            $table->boolean('has_reply')->default(false);
            $table->string('match_tier', 20)->nullable();
            $table->boolean('removed')->default(false);
            $table->timestamp('review_created_at')->nullable();
            $table->unsignedBigInteger('seq');
            $table->json('review')->nullable();
            $table->json('card')->nullable();
            $table->json('reply')->nullable();
            $table->json('report')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'google_review_name'], 'a1leads_review_unique');
            $table->index(['organization_id', 'review_created_at']);
            $table->index(['organization_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a1leads_reviews');
    }
};
