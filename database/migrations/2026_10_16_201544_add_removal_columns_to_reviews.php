<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review Google stopped showing (A1 Leads takeover P0, 2026-10-16). Missing
 * from one list is not proof; three complete lists without it, each confirmed
 * by a 404 on the review itself and two hours apart, is. These hold the count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('missing_checks')->default(0);
            $table->timestamp('missing_since')->nullable();
            $table->timestamp('missing_checked_at')->nullable();
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['missing_checks', 'missing_since', 'missing_checked_at', 'removed_at']);
        });
    }
};
