<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Google's own count and average per listing, read on each review sync (desk, 2026-11-20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->unsignedInteger('google_review_count')->nullable();
            $table->decimal('google_rating', 3, 2)->nullable();
            $table->timestamp('google_totals_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn(['google_review_count', 'google_rating', 'google_totals_at']);
        });
    }
};
