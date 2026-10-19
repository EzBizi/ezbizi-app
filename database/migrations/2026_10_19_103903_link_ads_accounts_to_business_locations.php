<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Local Services (Google Ads) account's Business Profile listing - the
 * office it serves from (A1 Leads takeover, 2026-10-19). The listing is the
 * source of the office address. business_location_by_user_id marks a person's
 * choice, which the automatic link never replaces; business_location_city is
 * the city when linked, so a listing that moves renames the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->foreignUuid('business_location_id')->nullable()->after('leads_franchise_id')
                ->constrained('business_locations')->nullOnDelete();
            $table->foreignUuid('business_location_by_user_id')->nullable()->after('business_location_id')
                ->constrained('users')->nullOnDelete();
            $table->string('business_location_city', 120)->nullable()->after('business_location_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_location_by_user_id');
            $table->dropConstrainedForeignId('business_location_id');
            $table->dropColumn('business_location_city');
        });
    }
};
