<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read and unread follow the mail service by default.
 *
 * Jose, 2026-10-06: "Sync email read/unread with gmail".
 *
 * The 2026-09-04 migration made this opt-in, for a real reason: read state at
 * a mail service is one global flag, so on a shared inbox one person reading
 * marks it read for everyone looking at Gmail directly. That reasoning was
 * mine, not a ruling, and the cost of the default it produced was worse than
 * the risk it guarded: the list draws its bold from GMAIL'S flag, so with the
 * switch off, opening a message here showed it read and the next refresh put
 * it straight back to unread. Every mailbox behaved that way.
 *
 * So: on for every mailbox that exists, and on for new ones. The per-mailbox
 * switch stays, and its wording says the shared-inbox cost while it is on.
 *
 * Measured before writing this: all 7 production mailboxes held the untouched
 * default `false`. Nobody had chosen off, so turning them on overrides nobody.
 *
 * Only Gmail and Google Workspace can act on it; Outlook and IMAP are
 * read-only grants and the controller does not ask them (`canMarkReadAtSource`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('mark_read_at_source')->default(true)->change();
        });

        DB::table('mailboxes')->update(['mark_read_at_source' => true]);
    }

    public function down(): void
    {
        // The default goes back. The rows do not: by rollback time somebody may
        // have turned one off, or on, deliberately, and flipping them all would
        // overwrite a choice. Same reasoning as the tab backfills.
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('mark_read_at_source')->default(false)->change();
        });
    }
};
