<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider tells us about a lead beyond its phone (Jose, 2026-10-29,
 * design 159): where they are, their profile, and the ad or page that sent
 * them. `referral` is a small object because each provider names it
 * differently: {source, campaign_name, ad_name, headline, page_name, keyword, page_url}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('postal_code');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('handle', 120)->nullable()->after('contact_name');
            $table->string('avatar_url', 1000)->nullable()->after('handle');
            $table->json('referral')->nullable()->after('site_host');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['address', 'city', 'handle', 'avatar_url', 'referral']);
        });
    }
};
