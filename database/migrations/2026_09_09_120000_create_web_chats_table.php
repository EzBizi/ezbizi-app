<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One live-chat conversation held on a customer's website.
 *
 * ── Why this table is so thin ──
 *
 * The messages are not here. A website chat is a Lead like any other, and its
 * messages are `lead_conversations` rows written through `LeadThread`, exactly
 * as Google's calls and Thumbtack's messages are — so the Leads list, the
 * unreplied dot and the `lead.contact_received` trigger all mean the same thing
 * whatever a visitor used to get in touch.
 *
 * What Leads cannot hold is the one thing this table exists for: a secret the
 * VISITOR's browser presents to read its own thread back. Nobody is signed in
 * on the far end — there is no user, no session, no tenant — so a random token
 * per conversation is the only thing standing between a visitor and somebody
 * else's chat. It is stored hashed for the reason every credential is: the
 * column is a lookup key, not a copy of the secret.
 *
 * ── Why it is not on the leads table ──
 *
 * `leads` belongs to the Leads module. A column that only Web Management writes
 * and only Web Management reads would put a second module's private business in
 * a shared model, which is how two modules end up disagreeing about who owns a
 * migration. A join table is the seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('organization_id', 36);
            $table->char('site_id', 36);
            $table->char('lead_id', 36);

            // sha256, so 64 hex characters. Unique because it is the lookup.
            $table->char('token_hash', 64)->unique();

            /*
             * What the visitor typed about themselves, kept here as well as on
             * the lead so a chat that is still anonymous can show "you" without
             * writing a half-empty contact record into Leads.
             */
            $table->string('visitor_name', 191)->nullable();

            $table->string('page_url', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('web_sites')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();

            $table->index(['organization_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_chats');
    }
};
