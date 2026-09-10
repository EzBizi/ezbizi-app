<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits SCREEN CONTENT out of the `specs` blob into a column of its own —
 * the title of the window in front, and every open browser tab.
 *
 * They arrived inside `specs` because the agent reads them in the same pass,
 * and that is a fact about the agent rather than about the data. Everything
 * else in `specs` says what the MACHINE is: processor, memory, drives, uptime.
 * These two say what a person is DOING, which is a different kind of thing and
 * is answered differently when somebody asks what is held about them.
 *
 * Three things follow from giving them a column, and the first is the reason:
 *
 *  1. §9 categories are per ENTRY, and an entry names real columns — so while
 *     these lived inside `specs` they were declared TECHNICAL along with the
 *     RAM. The inventory endpoint publishes that category (PersonalDataEntry
 *     ::toArray), so a person asking what is held about them would have been
 *     told their window titles are technical data about a machine. With their
 *     own column they are declared ACTIVITY, which is what they are.
 *  2. Erasure becomes separable: screen content can be dropped on its own
 *     without blanking a machine's hardware record.
 *  3. Gating stops being array surgery. Withholding screen content from a role
 *     without `monitoring.screenshots.view` is now leaving a column out rather
 *     than unsetting keys from a blob everybody else still needs.
 *
 * The wire is deliberately UNCHANGED: the agent still reports these keys
 * inside `specs` and the heartbeat lifts them out on the way in. So no client
 * has to be shipped in step with this, and an older agent keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->json('screen_context')->nullable()->after('specs');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->dropColumn('screen_context');
        });
    }
};
