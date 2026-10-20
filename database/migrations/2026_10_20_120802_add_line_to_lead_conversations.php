<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the business's own numbers a call was on - dialled by the customer,
 * or the line we called from - and that line's name (Jose, 2026-10-20: "when a
 * customer calls, can we show What number they called as well?"). Filled for
 * RingCentral and EzBizi calls; Google's Local Services calls do not say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->string('line_number', 20)->nullable()->after('duration_ms');
            $table->string('line_name', 120)->nullable()->after('line_number');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->dropColumn(['line_number', 'line_name']));
    }
};
