<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General fields a site has no use for, so they stop counting as missing
 * (Jose, 2026-11-09: a-1chimney.com serves an area and has no street address).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->json('not_needed_fields')->nullable()->after('has_booking');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('not_needed_fields');
        });
    }
};
