<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether somebody who enquired agreed to be sent marketing email.
 *
 * Jose, 2026-09-17: "no, needs opt-in checkbox" — and 2026-09-24, "sure build
 * it". A person who sent an enquiry has asked for an answer, not a newsletter,
 * so adding them to a mailing list needs their agreement, given at the moment
 * they wrote in.
 *
 * TWO COLUMNS, because the time alone is not a record of consent. What they
 * agreed to is the WORDS beside the box when they ticked it — a business can
 * change that sentence next month, and "they agreed to something" is not an
 * answer anyone can defend. So the exact text is kept beside the moment.
 *
 * Null means they did not agree, or the form they used never asked. The two
 * are the same answer on purpose: nothing may be inferred from silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('marketing_consent_at')->nullable()->after('email');
            $table->string('marketing_consent_text', 500)->nullable()->after('marketing_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['marketing_consent_at', 'marketing_consent_text']);
        });
    }
};
