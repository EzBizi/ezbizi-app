<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the site's published pages carry the booking widget, as its plugin
 * reports on each fetch (Leads' Website panel, 2026-10-29). Null until a
 * plugin new enough to count it has reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->boolean('has_booking')->nullable()->after('plugin_version');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('has_booking');
        });
    }
};
