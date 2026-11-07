<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A service-area listing's places ("Warren, MI, USA"), its only "where" (2026-11-05). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->json('service_places')->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn('service_places');
        });
    }
};
