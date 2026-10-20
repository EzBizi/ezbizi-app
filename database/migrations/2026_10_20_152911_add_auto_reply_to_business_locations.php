<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a rule may publish a reply on this listing's reviews by itself
 * (A1 Leads takeover, 2026-10-20). Off for every listing until a person turns
 * it on for that listing: only one system may answer a listing's reviews, and
 * the switch is how that is kept true one location at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->boolean('auto_reply')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn('auto_reply');
        });
    }
};
