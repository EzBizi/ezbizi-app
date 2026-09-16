<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tenant a registered WhatsApp number belongs to.
 *
 * ── Why this is not a social_connections row ─────────────────────
 *
 * Every other connected account in the product is connected BY THE TENANT,
 * through OAuth, and `social_connections` is shaped around that: a grant, a
 * refresh token, a scope, a `connectsBy()` that says how the tenant did it.
 *
 * A WhatsApp number is not that. Meta registers it against our app, we verify
 * it with a one-time code, and somebody here decides which tenant it serves.
 * There is no grant and no tenant-side flow, so a SocialProvider case would
 * have to answer `connectsBy()` with something untrue, and every one of the
 * seven match statements in that enum would gain an arm describing a flow that
 * does not exist.
 *
 * ── This is interim, and the successor has a name ────────────────
 *
 * Meta's Embedded Signup does let a tenant attach their own number through a
 * flow, and when that is built a WhatsApp connection becomes exactly like the
 * others and belongs in `social_connections` with the rest. Recorded here
 * rather than in a plan, because this table is what somebody will have open on
 * the day they wonder why it is separate.
 *
 * ── The id, not the number ───────────────────────────────────────
 *
 * `phone_number_id` is Meta's own id and is what every webhook is keyed by. The
 * displayed number can change - a business moves, a number is re-verified -
 * without the id changing, so routing on the number would lose messages at
 * exactly the moment somebody changed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Unique across the install: one number serves one tenant, and two
            // rows claiming it would make which tenant receives a customer's
            // message depend on row order.
            $table->string('phone_number_id', 32)->unique();

            $table->string('waba_id', 32);
            $table->string('display_number', 32)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numbers');
    }
};
