<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI's suggested category for a Facebook campaign, beside the real one, which
 * only a person's confirm fills from it (Jose, 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_campaigns', function (Blueprint $table) {
            $table->uuid('suggested_service_group_id')->nullable();
            $table->timestamp('suggested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_campaigns', fn (Blueprint $table) => $table->dropColumn(['suggested_service_group_id', 'suggested_at']));
    }
};
