<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the watcher has got to in each shared inbox.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  FOUR COLUMNS, AND NOT ONE OF THEM HOLDS A MESSAGE.
 *
 *  The owner's decision stands untouched: *"we don't want to store
 *  thousands and thousands of emails on our database."* There is no
 *  messages table and this migration does not start one. What is
 *  stored is a POSITION — one short string per mailbox saying where
 *  the reading got to — and three pieces of bookkeeping about when to
 *  look again.
 *
 *  A position is not a copy. Losing every value below costs the
 *  watcher one silent re-mark per mailbox; it loses no mail, exposes
 *  nothing, and could not be used to reconstruct a single subject.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why on `mailboxes` and not a table of its own ────────────────
 *
 * Because there is exactly one watch per mailbox and there can never be two:
 * the mark is a property of the connection, in the same way `status` and
 * `last_reached_at` already are. A `mailbox_watches` table would be a second row
 * that must exist for every first row, kept in step by hand, and the first bug
 * would be a mailbox connected while the watcher was switched off — one row, no
 * partner, and a null nobody expected.
 *
 * It also keeps the table's size a decision rather than a consequence, which is
 * what Mailbox::retention() promises: one row per address, for ever, still.
 *
 * ── Every one of them is nullable, and null means something ──────
 *
 * `watch_mark` null is "nothing has ever established a position here", which is
 * the true state of every mailbox that exists on the day this runs. It is what
 * makes the first poll of every one of them announce NOTHING — the single most
 * important requirement in this feature. A default of anything else would have
 * been a claim about mail nobody has looked at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            /*
             * The provider's own position, opaque to everything but the reader
             * that issued it: a Gmail history id, or `UIDVALIDITY:UID` for IMAP.
             *
             * 191 rather than 255 for the same reason `message_seen` uses it —
             * the largest a utf8mb4 column can be and still sit inside an index
             * at the default key length — and generous even so: a Gmail history
             * id is a decimal integer and an IMAP mark is two of them.
             */
            $table->string('watch_mark', 191)->nullable()->after('last_reached_at');

            /*
             * When the mark was last CONFIRMED against the mail service — not
             * when it last changed.
             *
             * The distinction is the whole point. A quiet inbox goes weeks
             * without its mark moving and is perfectly healthy; a mark nobody
             * has checked for two days belongs to a watcher that was switched
             * off, and everything that arrived meanwhile is invisible to it. So
             * this moves on every successful poll, and it is what
             * App\Support\Email\MailboxWatcher reads to decide that a mark has
             * gone stale and must be re-taken in silence.
             *
             * `watch_next_poll_at` cannot answer that question, which is why
             * both exist: it moves when a mailbox is DEFERRED as well, including
             * when it is skipped for being broken or unwatched, so it says when
             * somebody intends to look rather than when anybody last did.
             */
            $table->timestamp('watch_marked_at')->nullable()->after('watch_mark');

            /*
             * When this mailbox is next due — and, while a poll is in flight,
             * the claim on it.
             *
             * One column doing both jobs, deliberately. The sweep selects rows
             * whose due time has passed and CLAIMS each one by pushing this
             * forward in a conditional update, so two overlapping runs cannot
             * both dial the same mail server: the second one's update matches no
             * rows and it moves on. A lease and a due time as separate columns
             * would be two facts that must agree about one mailbox.
             *
             * Null is "never looked at", which sorts first under MySQL's
             * ascending order — so a newly connected inbox is marked before an
             * old one is re-polled, which is the order a person would expect.
             */
            $table->timestamp('watch_next_poll_at')->nullable()->after('watch_marked_at');

            /*
             * Consecutive failed polls, for the backing off.
             *
             * Small on purpose: the backoff stops doubling long before this
             * could overflow, and a column wide enough to count a million
             * failures would be a column implying somebody meant to.
             *
             * It counts the failures that do NOT mark a mailbox broken — a rate
             * limit, a mail server having a bad afternoon. The ones that do mark
             * it broken take it out of the sweep entirely, so they never get
             * here; see App\Support\Email\MailboxFault::marksBroken for where
             * that line is drawn.
             */
            $table->unsignedSmallInteger('watch_failures')->default(0)->after('watch_next_poll_at');

            /*
             * The sweep's one query: connected mailboxes that are due, oldest
             * first, across every organization.
             *
             * Deliberately NOT led by `organization_id`. This runs in a
             * scheduled command with no tenant — it has to find work in every
             * organization at once and enters each one afterwards — so an index
             * beginning with the tenant would be an index the only query that
             * matters cannot use.
             */
            $table->index(['status', 'watch_next_poll_at'], 'mailboxes_watch_due');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropIndex('mailboxes_watch_due');
            $table->dropColumn(['watch_mark', 'watch_marked_at', 'watch_next_poll_at', 'watch_failures']);
        });
    }
};
