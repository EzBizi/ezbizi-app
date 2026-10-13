<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 2-letter state the work is in, where the source says (Thumbtack's
 * request.location.state), for Reports' Thumbtack tab (2026-10-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->char('service_state', 2)->nullable()->after('postal_code'));
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('service_state'));
    }
};
