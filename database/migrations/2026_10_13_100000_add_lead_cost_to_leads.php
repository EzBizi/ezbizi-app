<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead cost, where the source says (Thumbtack's leadPrice), for the
 * Thumbtack financials in Reports (Jose, 2026-10-13). Minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('lead_cost_minor')->nullable()->after('charge_status');
            $table->char('lead_cost_currency', 3)->nullable()->after('lead_cost_minor');
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['lead_cost_minor', 'lead_cost_currency']));
    }
};
