<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colleagues messaging each other inside EzBizi.
 *
 * ── Why this is not a Phone table ───────────────────────────────
 *
 * It was asked for on the Phone Team panel, which is where somebody already
 * sees who is at a desk. But nothing about a colleague asking another
 * colleague a question belongs to telephony, and naming it `phone_*` would
 * trap it there — the second surface that wants it (a job, a task, the
 * dashboard) would either reach into Phone or start a second table.
 *
 * ── Why sender and recipient rather than a thread row ───────────
 *
 * A thread between two people is derivable from the pair, and a threads table
 * would need creating, finding and de-duplicating before a single message
 * could be written. Two ids and a body is the smallest thing that works. If
 * group conversations are ever wanted, THAT is when a thread row earns its
 * place — inventing one now would be building for a conversation nobody has
 * asked for.
 *
 * ── read_at, not a boolean ──────────────────────────────────────
 *
 * There is no realtime transport in this app: no websocket, no push. Delivery
 * is polling, so "delivered" is not a state we can honestly report. Sent and
 * seen are, and a timestamp says WHEN seen, which a boolean cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The two reads this table has: one person's whole inbox, and the
            // thread between a specific pair.
            $table->index(['organization_id', 'recipient_id', 'read_at']);
            $table->index(['organization_id', 'sender_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
