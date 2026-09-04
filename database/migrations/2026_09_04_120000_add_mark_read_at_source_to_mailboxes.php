<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether opening a message here also marks it read at the mail service.
 *
 * ── Per MAILBOX, not per organization ────────────────────────────
 *
 * Because the thing it depends on is a property of the mailbox rather than of
 * the company. One organization can hold `info@`, which three people share, and
 * `jose@`, which one person reads — and the right answer differs between them
 * for the same reason in both cases.
 *
 * ── Why the default is FALSE, and it is not timidity ─────────────
 *
 * Read state at a mail service is ONE GLOBAL FLAG. EzBizi's own `message_seen`
 * is per person, which is what lets a shared inbox say "seen by Jenny". Turning
 * this on collapses the two: Jenny opens a customer's mail here, Gmail marks it
 * read for everybody, and Bob — looking at Gmail directly — sees a read message
 * and leaves it, believing it is handled. The customer is then ignored by two
 * people who each thought the other had it.
 *
 * That is the failure the module exists to prevent, so it stays off unless
 * somebody chooses it for a mailbox where it cannot happen. Every mailbox that
 * exists today keeps behaving exactly as it does now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('mark_read_at_source')->default(false)->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('mark_read_at_source');
        });
    }
};
