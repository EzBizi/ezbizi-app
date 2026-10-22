<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's catch-all area: it takes any ZIP no area of that branch lists.
 * Companies without drawn areas still book online (Jose via Bar, 2026-10-22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->boolean('covers_all_zips')->default(false)->after('shares_with_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn('covers_all_zips');
        });
    }
};
