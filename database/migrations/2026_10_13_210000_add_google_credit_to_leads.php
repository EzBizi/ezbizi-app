<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Google Local Services lead AI thinks Google should credit (Jose,
 * 2026-10-13, Rodrigo's LSA triage): the reason in Google's own words
 * (SPAM, JOB_TYPE_MISMATCH ...), when a person sent it, and what Google
 * decided (creditIssuanceDecision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('credit_reason', 30)->nullable()->after('charge_status');
            $table->timestamp('credit_requested_at')->nullable()->after('credit_reason');
            $table->string('credit_decision', 40)->nullable()->after('credit_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['credit_reason', 'credit_requested_at', 'credit_decision']));
    }
};
