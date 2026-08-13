<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sign-in to Google that has been STARTED and not yet finished.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THIS TABLE IS THE ONLY THING AUTHENTICATING THE CALLBACK.
 *
 *  Google finishes an OAuth flow by redirecting a BROWSER to a URL on
 *  this server. A redirect carries no bearer token, so the callback
 *  cannot sit behind `auth:sanctum` — which means the request arriving
 *  at it is, as far as the framework is concerned, a stranger holding
 *  a code and a `state`.
 *
 *  That callback then attaches a credential to a mailbox. If `state`
 *  were guessable, or were merely "some string we issued" rather than
 *  a row naming WHO started this and WHICH inbox for WHICH
 *  organization, then completing a flow with somebody else's mailbox
 *  id would attach an attacker's Google account to a tenant's shared
 *  inbox — or attach a tenant's mailbox to a flow started elsewhere.
 *
 *  So every row here binds four things together and the callback
 *  believes none of them from the request:
 *
 *    organization_id  which tenant the credential may be written into
 *    user_id          who is allowed to have started this
 *    mailbox_id       which inbox it may be attached to
 *    state_hash       the unguessable value that names this row
 *
 *  The request supplies `state` and `code`. Everything else — the
 *  mailbox, the organization, the person, the redirect URI, the PKCE
 *  verifier — comes from this row or from config, and never from a
 *  query parameter.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── One row's life is ten minutes, and usually much less ─────────
 *
 * A row is written when somebody presses "Sign in with Google" and DELETED the
 * moment the callback reads it — see App\Support\Email\GoogleOAuthSignIns,
 * where the delete is the burn and its affected-row count is what makes the
 * single use atomic under a double-click or a replayed link. Nothing here is
 * kept as a record: who connected a mailbox and when is the audit log, which is
 * built for that and expires on its own schedule.
 *
 * Expired rows are cleared whenever another sign-in begins, so the table's
 * steady state is "the flows currently in flight" — which is why the model
 * states its retention as `elsewhere` rather than asking the nightly sweep for
 * a window measured in months. A ten-minute credential does not need a sweep;
 * it needs deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_mail_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * The tenant the finished credential belongs to.
             *
             * Carried on the row rather than looked up from the mailbox at
             * callback time, and that is not redundancy. The callback has no
             * tenant context at all — no session, no user, no `tenant`
             * middleware — so it has to ESTABLISH tenancy before it may read a
             * tenant-scoped model. This column is what it establishes it from,
             * and the mailbox is then loaded inside that organization: a row
             * naming organization A can only ever reach a mailbox in A, because
             * the scope is already closed before the lookup happens.
             */
            $table->uuid('organization_id');

            /*
             * Who started it. Re-checked at the callback, not merely recorded.
             *
             * Somebody may be demoted, deactivated or removed in the ten
             * minutes a consent screen is open, and the callback asks again
             * whether this person may still manage this inbox. Recording the
             * actor is the smaller half of why this is here.
             */
            $table->uuid('user_id');

            /** Which inbox the resulting refresh token may be attached to. */
            $table->uuid('mailbox_id');

            /*
             * The `state` parameter, hashed — never stored as issued.
             *
             * It travels through Google and back through a browser's address
             * bar, so it is a credential in transit and is treated like every
             * other one in this codebase (App\Services\Hr\Invitations,
             * App\Services\Auth\PasswordResets both hash at rest). A database
             * dump therefore contains no value anybody could complete a pending
             * flow with.
             *
             * Unique, so two rows can never answer to one state and the
             * single-use delete can never remove the wrong one.
             */
            $table->char('state_hash', 64)->unique();

            /*
             * The PKCE verifier (RFC 7636), encrypted on the model.
             *
             * PKCE is here because the authorization code is delivered through
             * a BROWSER REDIRECT: it lands in an address bar, in browser
             * history, and in this server's access log. The verifier is the
             * proof that whoever presents the code is the same party that
             * started the flow, and it is the only part of the exchange that
             * never leaves this server — so a code lifted out of a log is worth
             * nothing without the row it belongs to.
             *
             * Which is exactly why it is encrypted rather than stored plainly:
             * an attacker holding a leaked code AND a database dump would
             * otherwise have both halves.
             */
            $table->text('code_verifier');

            /*
             * When it stops being accepted, whether or not anything deletes it.
             *
             * Ten minutes is generous for "sign in to Google and press allow"
             * and short enough that a link left in a browser tab overnight is
             * dead. The callback checks this AFTER burning the row, so an
             * expired flow is removed rather than left to be tried again.
             */
            $table->timestamp('expires_at');

            $table->timestamps();

            /*
             * Both cascades matter, and they are what make an abandoned flow
             * harmless. Delete the organization, or disconnect the mailbox, and
             * any half-finished sign-in pointing at it goes with it — so a
             * callback can never resurrect a credential onto an inbox somebody
             * has already removed.
             */
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();

            // The pruning predicate. Every `begin` clears what has expired, and
            // without this that sweep is a full scan on the one table every
            // sign-in touches.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_mail_sign_ins');
    }
};
