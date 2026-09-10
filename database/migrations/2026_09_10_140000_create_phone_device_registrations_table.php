<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a person's devices are live, so a call can ring all of them.
 *
 * ── The defect this exists for ──────────────────────────────────
 *
 * A Voice identity was one per PERSON, so `<Dial>` emitted one `<Client>` per
 * person and Twilio made exactly one call leg from it. Measured on 2026-09-10:
 * three inbound calls, three legs each — one per member of the ring group,
 * never two for the same person. Jose had the desk open on his PC and the app
 * on his iPhone, both registered, and only the iPhone rang.
 *
 * Twilio's `<Client>` documentation says nothing about fanning one identity
 * out to several endpoints, and the "ten devices all ring" material is about
 * SIP registration, a different mechanism. So the answer is not to hope: emit
 * one `<Client>` per DEVICE, which is documented — up to ten nouns in a Dial,
 * first to accept wins and the rest are cancelled.
 *
 * ── Why not a column on phone_presences ─────────────────────────
 *
 * Presence answers "is this person available" — Do Not Disturb, on a call,
 * wrapping up. That is a fact about the PERSON and must stay one row: somebody
 * stepping away steps away from every device at once. Which endpoints to ring
 * is a different question with a different lifetime, and folding them together
 * would make "I am away" a per-device setting nobody asked for.
 *
 * ── Why a deadline rather than a clean unregister ───────────────
 *
 * A browser tab closed without warning never says goodbye, and a device that
 * stays in this table forever takes one of the ten `<Client>` slots and rings
 * nothing. `last_seen_at` is refreshed whenever the device asks for a token,
 * and a device not seen recently is simply not dialled — so a stale row costs
 * nothing and nothing has to run to clean it up. Same shape as the wrap-up
 * deadline: read from a time, never from a state something must clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_device_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')
                ->constrained('phone_connections')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // The app's own id for this install. Hex, because it becomes half
            // of a Twilio client identity and those are alphanumeric only.
            $table->string('device_id', 64);

            // What it is, for the transfer list: "Windows", "iPhone". Shown to
            // a person choosing where to send a live call, so it has to be a
            // word they recognise rather than an id they do not.
            $table->string('label', 64)->nullable();
            $table->string('platform', 16)->nullable();

            $table->timestamp('last_seen_at');
            $table->timestamps();

            // NAMED, and short. Laravel derives an index name from the table
            // and every column, and MySQL refuses an identifier over 64
            // characters — the generated one here is 65. It fails at migrate
            // time, so every test re-ran the whole migration and a 20-second
            // file took 281 seconds before anything said why.
            $table->unique(['connection_id', 'user_id', 'device_id'], 'phone_device_regs_unique');
            $table->index(['connection_id', 'last_seen_at'], 'phone_device_regs_live_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_device_registrations');
    }
};
