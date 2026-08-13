<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a shared inbox last actually answered.
 *
 * ── The question this column exists to answer ────────────────────
 *
 * "This stopped working — when?" Until now that could not be said at all.
 * `status` says what the last attempt found and `status_reason` says why, but
 * neither carries a time, so a broken inbox looked exactly as broken whether it
 * failed a minute ago or in March. The difference decides what somebody does:
 * one is a blip worth retrying, the other is three months of customer mail
 * nobody has read.
 *
 * ── What it does NOT record, which is most of what could be ──────
 *
 * NOT when somebody last LOOKED. The settings screen asks every mailbox whether
 * it can be opened in order to draw the list, and if that question moved this
 * column then opening the screen would refresh every row and the field would
 * answer "when did you last open this tab" — a question nobody asked.
 *
 * NOT when a read was ATTEMPTED. A timestamp that moves on failure says an inbox
 * was reached at the exact moment it was not, which is worse than having no
 * column: it is a wrong answer wearing the clothes of a right one.
 *
 * So it moves in ONE place — App\Support\Email\RecordingMailboxReader, which
 * wraps every reader App\Support\Email\MailboxReaders hands out — and only after
 * a call to the provider has returned. That class's docblock states why there
 * rather than inside the readers.
 *
 * ── Nullable, and null means something ───────────────────────────
 *
 * Null is "EzBizi has never successfully opened this", which is the true state
 * of every row that exists today and of every mailbox added from now on until
 * somebody opens it. A default of `now()` would have claimed on the day of this
 * migration that every inbox in the system had just answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->timestamp('last_reached_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('last_reached_at');
        });
    }
};
