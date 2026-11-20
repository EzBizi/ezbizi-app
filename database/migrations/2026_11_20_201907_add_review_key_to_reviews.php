<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Google review (desk, 2026-11-20): a listing reachable through its own account AND a manager
 * account arrived twice, as accounts/X/locations/L/reviews/R and accounts/M/locations/L/reviews/R. The review
 * id R is the identity; review_key holds it. ezbizi:reviews-dedupe folds the pairs; the writer finds by key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('review_key', 191)->nullable()->after('remote_name');
            $table->index(['organization_id', 'source', 'review_key']);
        });
        DB::statement("UPDATE reviews SET review_key = SUBSTRING_INDEX(remote_name, '/', -1) WHERE source = 'google' AND remote_name LIKE '%/reviews/%'");
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'source', 'review_key']);
            $table->dropColumn('review_key');
        });
    }
};
