<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A source can hold a place's books for a stretch of time only — a franchise
 * whose books moved to a new table on a given day. Null means open-ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_sources', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('markers');
            $table->date('valid_until')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('financial_sources', fn (Blueprint $table) => $table->dropColumn(['valid_from', 'valid_until']));
    }
};
