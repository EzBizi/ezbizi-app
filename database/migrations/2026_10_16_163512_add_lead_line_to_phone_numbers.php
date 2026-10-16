<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line whose inbound calls and texts are leads (Jose, 2026-10-16: "EZ Calls"
 * and "EZ Texts" in Leads, beside LS Calls and RC Calls). Off by default, so
 * no line becomes a lead source by accident; set in Communication › Settings ›
 * Numbers, read by the Leads importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('lead_line')->default(false)->after('sms_outbound_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('lead_line');
        });
    }
};
