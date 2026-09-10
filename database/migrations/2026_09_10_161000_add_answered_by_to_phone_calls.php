<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the people it rang actually picked up.
 *
 * `user_id` is who PLACED a call, and on an inbound one it is nobody: the
 * call arrives at a line, the line rings a whole ring group, and the row said
 * only that somebody answered. So a customer ringing back and asking "who did
 * I speak to yesterday" had no answer anywhere in EzBizi.
 *
 * Twilio names no winner either — `<Dial>` reports that the dial completed,
 * not which `<Client>` took it. What it does offer is a per-noun `url`,
 * fetched on the leg that answers and only that one. That request is what
 * fills this column; see AnsweredWebhookController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->uuid('answered_by')->nullable()->after('user_id');
            $table->index(['organization_id', 'answered_by'], 'phone_calls_org_answered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropIndex('phone_calls_org_answered_idx');
            $table->dropColumn('answered_by');
        });
    }
};
