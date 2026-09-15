<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promise to ring somebody back, so they can stop holding.
 *
 * Asked for by Jose from RingCentral: press 1, hang up, keep your place, be
 * rung back. The queue's own docblock named it as deliberately not built,
 * because honouring a promise needs somewhere to keep it — this is that place.
 *
 * ── Why a table and not a queued job ─────────────────────────────
 *
 * A job holds the promise in Redis, where nobody can see it. The thing that
 * makes a callback different from a missed call is that somebody was TOLD it
 * would happen, so it has to be visible to the people who owe it — on screen,
 * countable, and still there after a worker restart. The job places the call;
 * the row is the promise.
 *
 * `taken_by` rather than a boolean: two desks becoming free in the same second
 * would otherwise both ring the same customer, and being rung twice by a
 * company you are waiting on reads worse than waiting a little longer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_callbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();

            // The line they rang, so the callback goes out from the number
            // they recognise rather than whichever one the desk happens to
            // have selected.
            $table->uuid('number_id')->nullable();

            // Which group owes it. A callback from the Sales queue should be
            // taken by Sales becoming free, not by anybody anywhere.
            $table->uuid('ring_group_id')->nullable();

            $table->string('to_e164', 32);
            $table->unsignedSmallInteger('position')->default(1);

            $table->timestamp('requested_at');
            $table->timestamp('taken_at')->nullable();
            $table->uuid('taken_by')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // The list is read on every presence change and on every poll of
            // the waiting strip: small, hot, and always filtered the same way.
            $table->index(['connection_id', 'placed_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_callbacks');
    }
};
