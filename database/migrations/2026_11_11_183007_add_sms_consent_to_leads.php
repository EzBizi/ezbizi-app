<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The booking widget's SMS consent box (Jose, 2026-11-11): when it was ticked,
 * the exact words beside it, and from which site and address. Apart from
 * marketing_consent_*, which is email and drives the newsletter rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('sms_consent_at')->nullable()->after('marketing_consent_text');
            $table->text('sms_consent_text')->nullable()->after('sms_consent_at');
            $table->string('sms_consent_site', 191)->nullable()->after('sms_consent_text');
            $table->string('sms_consent_ip', 45)->nullable()->after('sms_consent_site');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['sms_consent_at', 'sms_consent_text', 'sms_consent_site', 'sms_consent_ip']);
        });
    }
};
