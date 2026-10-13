<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per review, not per account alias (Rodrigo, suggestion 01a13160,
 * 2026-10-12): Google account ids are routing aliases, so a review is
 * business + location + review. Filled by ezbizi:a1leads-delta-sync, which
 * rebuilds once when it finds rows without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a1leads_reviews', function (Blueprint $table) {
            $table->string('review_key', 300)->nullable()->after('google_review_name');
            $table->unique(['organization_id', 'review_key']);
        });
    }

    public function down(): void
    {
        Schema::table('a1leads_reviews', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'review_key']);
            $table->dropColumn('review_key');
        });
    }
};
