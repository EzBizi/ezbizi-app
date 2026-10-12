<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook Ads spend and results, for the Meta tab in Reports (Jose, 2026-10-12:
 * "Facebook Financial report + Retro filter by state and category").
 *
 * meta_ad_campaigns: one row per campaign, with EzBizi's own answers to the two
 * questions Meta cannot - which service (service_group_id) and which state or
 * branch the campaign is FOR. Seeded from the campaign name, then edited by
 * staff; a re-sync never overwrites them.
 *
 * meta_ad_insights: one row per ad set per day. region '' is the day's total;
 * any other value is Meta's `region` breakdown - where the people who saw the
 * ad were, not which branch the campaign belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->string('campaign_id', 32);
            $table->string('name');
            $table->string('objective', 64)->nullable();
            $table->foreignUuid('service_group_id')->nullable()->constrained('service_groups')->nullOnDelete();
            $table->string('state', 2)->nullable();        // US state the campaign is for
            $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'campaign_id'], 'meta_ad_campaigns_once');
        });

        Schema::create('meta_ad_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->date('date');
            $table->string('campaign_id', 32);
            $table->string('adset_id', 32);
            $table->string('adset_name')->nullable();
            $table->string('region', 64)->default('');      // '' = the day's total
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->char('currency', 3)->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'date', 'adset_id', 'region'], 'meta_ad_insights_once');
            $table->index(['organization_id', 'date'], 'meta_ad_insights_by_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights');
        Schema::dropIfExists('meta_ad_campaigns');
    }
};
