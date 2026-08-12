<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared team inboxes — an access-controlled window onto an address several
 * people answer (§3's levels, §4's grants).
 *
 * The owner's words for what this is: *"the capacity to let people answer emails
 * they don't regularly have access to — like company wide emails that receive
 * customer emails or forms"*. info@a-1chimney.com is answered by four people,
 * none of whom has the password, and none of whom should have to.
 *
 * ── Three tables, and why none of them holds a message ───────────
 *
 * *"we don't want to store thousands and thousands of emails on our database."*
 * So there is no `messages` table here and there must not be one. What is stored
 * is the CONNECTION (`mailboxes`), WHO MAY OPEN IT (`mailbox_grants`) and WHO
 * HAS LOOKED (`message_seen`). Bodies and attachments are fetched from the
 * provider while somebody is looking at them and are never written down; headers
 * may be cached, briefly, in the cache store rather than in a table — see
 * App\Support\Email\HeaderCache, which states the TTL and why a cache is not a
 * mirror.
 *
 * `message_seen` is the one table whose size follows mail volume, and it holds
 * an opaque provider id rather than anything about the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── The connection, and the secret behind it ─────────────
         *
         * Scoped the way a sending identity is, to one of §3's three levels: a
         * company-wide info@ hangs off the company, a branch's own off the
         * franchise. Unlike a sending identity there is NO CASCADE — a franchise
         * does not inherit its company's inbox, because inheriting an inbox
         * would mean everybody at every branch could read the company's customer
         * mail the moment somebody connected it. Reach is necessary and not
         * sufficient here; see `mailbox_grants` below.
         */
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            /*
             * The address people write to, and what to call it on screen.
             *
             * `address` rather than `email`, and that is not only taste:
             * DataInventoryTest treats a column literally named `email` as a
             * tell that the row is about a person, and this one is not. It is
             * the business's own shop sign — the same argument SendingIdentity
             * makes for `from_address`, recorded there at length.
             */
            $table->string('address');
            $table->string('display_name');

            /*
             * `gmail_oauth` or `imap`, as a string rather than a MySQL enum.
             *
             * Adding a value to an enum column is a table rebuild, and a third
             * provider (Microsoft 365 is the obvious one) is a question of when.
             * App\Support\Email\MailboxProvider is where the permitted values
             * live and what validates on the way in.
             */
            $table->string('provider');

            /*
             * How to reach the host — and NOT how to authenticate to it.
             *
             * Host, port and encryption are configuration: they are shown back
             * on the screen, they are what an administrator debugs with, and
             * they are not a secret. They are also the SSRF surface, because
             * this is a host the TENANT chose and our server dials it — see
             * App\Support\Email\RemoteHostGuard, which is what validates them
             * before anything connects.
             *
             * Null for a Gmail mailbox, which has no host to name.
             *
             * ── Named `mail_server` and NOT `connection` ─────
             *
             * `connection` was the first name and it is a trap, found by a test
             * rather than by reading: Eloquent's Model already has a protected
             * `$connection` property — the DATABASE connection name — so inside
             * the model `$this->connection` returns that property instead of
             * going through `__get` to the attribute. It reads as null, silently,
             * with no error anywhere.
             *
             * What that cost here was not cosmetic. `Mailbox::host()` returned
             * null, so the SSRF guard in MailboxesController::connect had no host
             * to judge and a mailbox pointed at the cloud metadata endpoint was
             * accepted. The column is named for what it holds instead, and it no
             * longer collides with anything the framework owns.
             */
            $table->json('mail_server')->nullable();

            /*
             * The highest-value secret in the system.
             *
             * An OAuth refresh token or an IMAP password unlocks somebody's
             * entire email — every message they have ever received, and the
             * ability to send as them. It is `encrypted` on the model, masked on
             * every serialisation by MasksSensitiveValues, and there is no API
             * path that reads it back: see Mailbox::mayReveal, which answers
             * false for everybody including the administrator who typed it.
             *
             * Encrypted from the first row rather than from the first row that
             * needs it, because a cast is per-column and cannot be switched on
             * for rows already written. Same argument as OnboardingAnswer::$value.
             */
            $table->text('credentials')->nullable();

            /*
             * Connected, or connected and then broken.
             *
             * A mailbox whose credential has been revoked at the provider is not
             * deleted — somebody has to be told, and a row that vanished says
             * nothing. `status_reason` is the sentence they read.
             */
            $table->string('status')->default('unconfigured');
            $table->text('status_reason')->nullable();

            $table->timestamps();

            // One connection per address per tenant. Two would make "which
            // mailbox is info@" order-dependent, and the grants would then hang
            // off whichever one happened to be found first.
            $table->unique(['organization_id', 'address']);

            // The listing query: every mailbox at the levels somebody reaches.
            $table->index(['organization_id', 'scope_type', 'scope_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        /*
         * ── Per-inbox access, as a TENANT record ─────────────────
         *
         * The owner: *"the organization can grant access to the email module to
         * a role, but not necessary to all the emails connected to the module."*
         * On screen that is a nested list under the Email module in the roles
         * editor — Email, then Email 1, Email 2 beneath it.
         *
         * WHY IT IS NOT A PERMISSION ROW. `permissions` is a PLATFORM table,
         * populated from the module registry and shared by every organization on
         * the installation. A row per mailbox would put one tenant's inbox
         * ADDRESSES into a table every other tenant's roles editor reads — a
         * tenant-boundary break, and a permanent one, since nothing about the
         * permissions table is organization-scoped. So this is a grant against
         * the mailbox record, carrying `organization_id` like every other tenant
         * row, and the roles editor COMPOSES the static `email.view` permission
         * with this organization's own inboxes. Same menu on screen; boundary
         * intact.
         *
         * Against a ROLE rather than a user, because that is where the
         * organization already expresses "who may reach what" and the editor is
         * the roles editor. A person's grants are their role's grants.
         */
        Schema::create('mailbox_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mailbox_id');
            $table->uuid('role_id');
            $table->timestamps();

            // Granting twice is granting once. Without this the roles editor
            // could double-write on a fast second save and the revoke would then
            // remove one of two rows, leaving the access in place.
            $table->unique(['mailbox_id', 'role_id']);

            $table->index(['organization_id', 'role_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        /*
         * ── Who has looked ───────────────────────────────────────
         *
         * The owner asked for a plain shared view with a *"Seen by X"*
         * indicator, rather than claiming or assignment. This is that indicator,
         * and it is also what a PRIVATE read state is built from — whether a
         * mark is everybody's or only yours is the cascading setting
         * `email.read_state`, and the rows are the same either way. Storing one
         * shape and reading it two ways is what lets an organization change its
         * mind without losing what people have already read.
         *
         * ── It holds an opaque id and nothing else ───────────────
         *
         * `provider_message_id` is Gmail's id or an IMAP UID. No subject, no
         * sender, no date: this table must not become the mirror decision 2
         * forbids by accretion, and a row here is meaningless without the
         * provider, which is exactly right.
         */
        Schema::create('message_seen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mailbox_id');

            /*
             * Long, because a provider decides how long.
             *
             * Gmail's ids are short hex; an IMAP server's UID is a number, but
             * the id this stores is qualified by folder so that a UID reused
             * after a folder is recreated cannot collide with an old mark. 191
             * is the largest a utf8mb4 column can be and still fit in a
             * composite unique key under the default index length.
             */
            $table->string('provider_message_id', 191);

            $table->uuid('user_id');
            $table->timestamp('seen_at');

            /*
             * `created_at`/`updated_at` are deliberately absent — `seen_at` IS
             * the time, and a second timestamp saying the same thing is one that
             * can disagree. The model switches timestamps off to match.
             */

            // One mark per person per message. `firstOrCreate` on this key is
            // what makes marking idempotent, and the unique index is what makes
            // that true under two tabs racing rather than only in the happy path.
            $table->unique(['mailbox_id', 'provider_message_id', 'user_id'], 'message_seen_unique');

            // "Who has seen these twenty messages" — the list screen's one query.
            $table->index(['mailbox_id', 'provider_message_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_seen');
        Schema::dropIfExists('mailbox_grants');
        Schema::dropIfExists('mailboxes');
    }
};
