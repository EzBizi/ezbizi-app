<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a tenant's own mail comes FROM (plan §6's cascade, §2.1 category 5).
 *
 * The owner's need, in their words: "a company would prefer that this newsletter
 * came from their own SMTP's like info@company.com". A-1 Chimney sends as
 * info@a-1chimney.com, Everdoors as info@everdoors.com, and a Connecticut branch
 * that sets nothing sends as whichever company owns it.
 *
 * ── Why a table and not a settings-registry entry ────────────────
 *
 * The registry was the first choice and it is a strong one: a setting gets the
 * §6 cascade, the scoping and the "a franchise may not change its parent's
 * value" rule for free, all of it already written and already tested. Four
 * things decided against it, and the first is the one that would have been a
 * security hole rather than an inconvenience.
 *
 *   1. VERIFICATION STATE IS WRITTEN BY THE SYSTEM, NOT BY A PERSON. Every
 *      registry value is reachable through `PATCH /api/v1/settings/{key}` with
 *      whatever the definition will cast. Storing "verified" there means an
 *      administrator can set themselves verified, which is precisely the
 *      "verification that returns true because nothing checked" this feature
 *      exists to prevent. There is no API write path to the state below —
 *      DomainVerification is the only thing that moves it.
 *
 *   2. AN IDENTITY IS A RECORD, NOT A VALUE. From address, from name, reply-to
 *      and transport must move together: a franchise that sets nothing inherits
 *      its company's identity WHOLE, rather than inheriting an address from one
 *      ancestor and a display name from another. Expressing that in the registry
 *      needs a shaped type, and the shaped types live in SettingDefinition —
 *      a file this change does not own. What is left is TYPE_JSON, which
 *      castJson accepts any array at all for, and which the client renders as a
 *      raw JSON box. SettingDefinition's own docblock calls that out by name.
 *
 *   3. VERIFICATION IS ABOUT A DOMAIN, AND DOMAINS ARE SHARED. Proving
 *      everdoors.com once covers info@ and support@. A per-scope JSON blob would
 *      carry the proof twice and let the two copies disagree; a row can look
 *      sideways at its siblings, which is what reuseTokenFor does.
 *
 *   4. THE CREDENTIAL A LATER TRANSPORT WILL HOLD. `credentials` is encrypted
 *      per row and masked by HoldsSensitiveValues + MasksSensitiveValues. The
 *      registry's `isSensitive` is a per-DEFINITION boolean — every tenant's
 *      value or none — and there is no encryption behind it at all.
 *
 * What that costs is the cascade, the scoping and the permission model, which
 * now have to be written rather than inherited. They are not re-derived:
 * SendingIdentityResolver walks the same chain SettingsResolver does and
 * SendingIdentityCascadeTest asserts the two agree link for link, so the day one
 * of them learns about a new level and the other does not, the build says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');

            // The same three scopes settings use, for the same reason: these are
            // the levels §3 says exist, and an identity that could hang off
            // anything else would have nowhere in the cascade to sit.
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('from_address');
            $table->string('from_name');

            /*
             * Optional, and it is NOT proved.
             *
             * Reply-To is a header the recipient's mail client reads when they
             * press Reply; nothing is signed with it and no receiving server
             * authenticates it. Requiring a verified domain for it would refuse
             * the ordinary case — a branch sending as info@a-1chimney.com and
             * asking for replies at its own scheduler's mailbox — for no gain.
             * The FROM is the one the world checks, and that is the one this
             * table makes tenants prove.
             */
            $table->string('reply_to')->nullable();

            /*
             * A string rather than an enum column.
             *
             * The decision for this change is verified-domain-on-our-transport
             * and nothing else, because holding sixty customers' SMTP passwords
             * is a permanent operational tax and because our server dialling a
             * tenant-chosen host is an SSRF surface. Bring-your-own-SMTP is
             * expected later, though, and adding a value to a MySQL enum is a
             * table rebuild. MailTransport is where the permitted values live
             * and it is what validates on the way in.
             */
            $table->string('transport');

            /*
             * Set by DomainVerification and by nothing else.
             *
             * Not `boolean verified`, because "we looked and the records are
             * absent" and "nothing has looked yet" are different facts and only
             * one of them is the tenant's to fix. SendingState is the machine.
             */
            $table->string('state');

            /** A sentence about whatever state it is in — shown to the tenant. */
            $table->text('state_reason')->nullable();

            /*
             * What the tenant publishes in DNS to prove they own the domain.
             *
             * Stored rather than derived from the app key so it can be rotated
             * without re-keying the application, and REUSED across identities on
             * the same domain in the same organization — otherwise everdoors.com
             * would need one TXT record per address sending from it.
             */
            $table->string('verification_token')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            /*
             * Empty today, encrypted always.
             *
             * The transport above has no credential — we sign for a domain the
             * tenant has proved and send it ourselves. When a BYO-SMTP transport
             * arrives it will have a password, and the moment to decide it is
             * encrypted is before any exist rather than after: a cast is
             * per-column and cannot be turned on for the rows already written.
             * The same argument as OnboardingAnswer::$value, for the same reason.
             *
             * No API route writes this. See SendingIdentitiesController.
             */
            $table->text('credentials')->nullable();

            $table->timestamps();

            // One identity per scope. Two would make resolution order-dependent,
            // which is the same trap the settings table's unique key avoids.
            $table->unique(['scope_type', 'scope_id']);

            // The resolver's query: the identity, if any, at each link of a chain.
            $table->index(['organization_id', 'scope_type', 'scope_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sending_identities');
    }
};
