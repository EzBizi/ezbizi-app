<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triage marks on a lead: star, note, and who has read it.
 *
 * Jose, 2026-09-08, item 3 of the a1tools list: "Read, unread, star and
 * notes". Star and note are facts about the lead, shared by everybody who can
 * see it, so they are columns. Read is a fact about a PERSON and a lead - the
 * dispatcher who opened it has read it, the one who has not has not - so it
 * is a row per (lead, user), and "unread" means no row, or a row older than
 * the newest thing on the lead.
 *
 * No model for lead_reads on purpose: two ids and a timestamp, written and
 * read by the controller, cascading with both ends. A model would be a class
 * with nothing to say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('starred_at')->nullable()->after('status');
            $table->text('note')->nullable()->after('summary');
        });

        Schema::create('lead_reads', function (Blueprint $table) {
            $table->uuid('lead_id');
            $table->uuid('user_id');
            $table->timestamp('read_at');

            $table->primary(['lead_id', 'user_id']);

            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_reads');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['starred_at', 'note']);
        });
    }
};
