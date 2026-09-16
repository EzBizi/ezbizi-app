<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every WhatsApp message in or out, and what became of it.
 *
 * ── Why this is stored when Ads Performance is not ───────────────
 *
 * Google Ads spend is read live because it is true of a date range and Google
 * restates it. A message is the opposite: it happened once, at a moment, to a
 * person, and the only record that it happened is the one we keep. Meta will
 * not tell us later what we sent.
 *
 * ── Outbound rows exist BEFORE the send ──────────────────────────
 *
 * The row is written first and the provider's id written back afterwards. A row
 * with `status = queued` and no `wamid` is a send that failed between our
 * process and Meta - which is a thing somebody needs to see, and is invisible
 * if the row is only written on success.
 *
 * ── Statuses arrive later, by webhook, and out of order ──────────
 *
 * `sent`, `delivered`, `read` and `failed` come back minutes apart on a
 * separate HTTP request keyed by `wamid`, which is why that column is indexed
 * and unique rather than being a detail on the side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-owned like everything else. Nullable for nothing: a
            // message that belongs to no organization could not be shown to
            // anybody, and an inbound one from an unknown number is still
            // addressed to a phone number WE registered for a tenant.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Which registered number this went out from or arrived at. Meta's
            // own id for the number, not the number itself: the display number
            // can change without the id changing.
            $table->string('phone_number_id', 32)->index();

            $table->string('direction', 3);           // 'out' | 'in'
            $table->string('counterparty', 32);       // E.164, the other end

            // Meta's id. Null until the API answers, and the reason the row is
            // written before the call rather than after it.
            $table->string('wamid', 128)->nullable()->unique();

            // 'queued' before the call, then whatever Meta last said.
            $table->string('status', 16)->default('queued')->index();

            // Meta's own sentence when it refuses or a delivery fails. Kept
            // rather than a code: "this number is not on WhatsApp" and "your
            // template is not approved" are different problems for whoever is
            // looking, and both arrive as a failure.
            $table->text('status_reason')->nullable();

            // What was actually sent or received. `template` for a
            // business-initiated message outside the 24-hour window, `text`
            // inside it - the distinction Meta bills and polices on.
            $table->string('kind', 16)->default('text');
            $table->string('template_name', 128)->nullable();
            $table->text('body')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The list somebody actually asks for: this tenant's traffic,
            // newest first.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
