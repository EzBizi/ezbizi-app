<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Pkce;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issuing and burning the `state` that holds a mail sign-in (Google,
 * Microsoft) together. Each subclass names its row model, its provider and
 * how to build the consent URL; everything below is shared.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT `state` IS FOR HERE, WHICH IS MORE THAN CSRF.
 *
 *  In an ordinary "sign in with Google" button, `state` protects
 *  against one thing: somebody being tricked into completing a flow
 *  they did not start. That matters here too, and it is not the
 *  dangerous half.
 *
 *  The dangerous half is that this callback ATTACHES A CREDENTIAL TO A
 *  MAILBOX, and the callback is unauthenticated — a browser redirect
 *  carries no bearer token. So `state` is not a nonce beside the real
 *  identification; it IS the identification. Everything the callback
 *  is allowed to know comes out of the row it names:
 *
 *    - which organization the credential may be written into
 *    - which person is allowed to have started this
 *    - which mailbox it may be attached to
 *    - the PKCE verifier that proves the code belongs to this flow
 *
 *  Take any one of those from the request instead and the flow breaks
 *  in a specific, exploitable way. Take the mailbox id from the query
 *  and anybody who completes a Google sign-in can attach their own
 *  account to another organization's shared inbox — or attach a
 *  tenant's inbox to a flow they started. Take the organization from
 *  the mailbox rather than from the row and a state minted in one
 *  tenant reaches a mailbox in another. Neither is hypothetical; both
 *  are what an unbound `state` buys.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Single use, enforced by the DELETE and not by a flag ─────────
 *
 * {@see burn} deletes the row and requires the delete to have affected exactly
 * one, which is the whole of the single-use guarantee. A `used_at` column read
 * and then written would have a gap between the two — two browsers, or one
 * double-click, land in it — and the loser of that race would still complete an
 * exchange. One statement, one affected row, no gap.
 *
 * Deleting rather than tombstoning also means "already used", "never issued",
 * "expired" and "pointing at something since removed" are the same answer to
 * whoever is asking, which is the answer they should get: see
 * {@see MailOAuthRefused::noOpenSignIn}.
 */
abstract class MailOAuthSignIns
{
    /**
     * How long somebody has to finish signing in.
     *
     * Long enough to find the right account, type a password and answer a second
     * factor; short enough that a link left in a browser tab is dead by the time
     * anybody comes back to it. This is a credential in an address bar, and its
     * lifetime is the window in which one lifted from a shared screen or a
     * history file is worth anything.
     */
    public const MINUTES = 10;

    /**
     * The three answers the screen that is waiting can act on.
     *
     * `waiting` covers everything that is not yet an outcome, which the client
     * ends by watching the consent window and by giving up after a few minutes
     * — so an answer this server cannot give does not become a screen that
     * spins for ever.
     */
    public const WAITING = 'waiting';

    public const CONNECTED = 'connected';

    public const REFUSED = 'refused';

    /**
     * How long an outcome is worth handing back.
     *
     * Longer than a sign-in lives, because it has to survive the gap between
     * the callback landing and the next poll from a screen that may have been
     * left alone for a couple of minutes. Fifteen is comfortably past the five
     * the client waits.
     */
    private const OUTCOME_MINUTES = 15;

    abstract protected function authorizeUrl(string $state, string $verifier, string $loginHint): string;

    /**
     * Start one, and hand back where to send the browser.
     *
     * The URL is built BEFORE the row is written, so a misconfigured redirect
     * URI refuses without leaving a pending sign-in behind that nothing will
     * ever finish. The provider's `redirectUri` is what throws.
     *
     * @return array{url: string, flow: string, expires_at: Carbon}
     *
     * @throws MailOAuthRefused
     */
    public function begin(User $user, Mailbox $mailbox): array
    {
        /*
         * 64 characters of CSPRNG output — `Str::random` wraps `random_bytes`.
         *
         * The size is the point. This value is the only thing standing between a
         * stranger and a credential write, so it has to be unguessable within
         * the ten minutes it lives; a counter, a uuid of the mailbox, or
         * anything derived from data an attacker can see would make the binding
         * above decorative.
         */
        $state = Str::random(64);
        $verifier = Pkce::verifier();

        $url = $this->authorizeUrl($state, $verifier, $mailbox->address);

        $this->clearExpired();

        $row = new (static::MODEL);

        // `forceFill` because nothing on this model is fillable — see the model,
        // where an empty `$fillable` is what stops any future mass assignment
        // reaching a security binding.
        $row->forceFill([
            'organization_id' => $mailbox->organization_id,
            'user_id' => $user->getKey(),
            'mailbox_id' => $mailbox->getKey(),
            // Hashed at rest, like every other credential in this codebase. The
            // raw value only ever exists in the URL below and in the query
            // string Google sends back.
            'state_hash' => $this->fingerprint($state),
            'code_verifier' => $verifier,
            'expires_at' => now()->addMinutes(self::MINUTES),
        ])->save();

        /*
         * `flow` is the ROW'S ID, and deliberately not the state.
         *
         * The screen that has just opened a consent window has to be able to
         * ask "did MY attempt land?", and it cannot use the state to ask: that
         * value is the credential, it goes to Google, and handing a copy to a
         * polling loop would put it in a second place it does not need to be.
         *
         * The row id is safe to hand over — it opens nothing, and the endpoint
         * that reads it re-asks permission and checks the flow belongs to the
         * mailbox in the path. What it buys is precision: without it, "has this
         * inbox got a credential yet" is the only question the server can
         * answer, and that confuses this attempt with the one somebody
         * abandoned ten minutes ago.
         */
        return ['url' => $url, 'flow' => (string) $row->getKey(), 'expires_at' => $row->expires_at];
    }

    // ── telling the screen that is waiting what happened ─────────

    /**
     * Write down how a sign-in ended, for the screen that is polling.
     *
     * ── The cache, and why that is the right home ────────────────
     *
     * This is a message between two requests seconds apart, not a record. It is
     * read once by a loop that is already running, it is worthless a few minutes
     * later, and losing the whole store costs somebody one press of a button.
     * Everything that must SURVIVE is already elsewhere and in better shape: the
     * credential is on the mailbox, and who connected what and when is in the
     * audit log, which is built to be read.
     *
     * A table would mean a migration, a retention policy and a sweep, for rows
     * whose entire purpose expires in fifteen minutes — the same argument
     * {@see HeaderCache} makes for not being a mirror.
     *
     * THE MAILBOX IS STORED BESIDE THE OUTCOME so that {@see outcome} can refuse
     * to answer about a flow belonging to a different inbox. Without it, a flow
     * id would be a key into any organization's outcomes, and "was that sign-in
     * refused, and with what sentence" is not a question a stranger may ask.
     */
    public function record(Model $signIn, string $state, string $message): void
    {
        Cache::put($this->outcomeKey((string) $signIn->getKey()), [
            'mailbox_id' => (string) $signIn->mailbox_id,
            'state' => $state,
            'message' => $message,
        ], now()->addMinutes(self::OUTCOME_MINUTES));
    }

    /**
     * What happened to this flow, for a screen that cannot see the callback.
     *
     * ── Why the server is asked at all ───────────────────────────
     *
     * The consent window stops belonging to the client the moment it navigates
     * to Google, and an opener may not read across origins. The server is the
     * only party that saw the token arrive, so the server is what gets asked.
     *
     * ── Without a flow, the answer is about the INBOX, not the attempt ─
     *
     * And that is a weaker answer, stated as such rather than dressed up. All it
     * can say is whether this mailbox has a credential now — which is true of an
     * inbox connected last March as much as of one connected four seconds ago.
     * The client sends the flow it was given, so this is the fallback for a
     * caller that did not, and it is honest about being one.
     *
     * @return array{state: string, message: ?string}
     */
    public function outcome(Mailbox $mailbox, ?string $flow): array
    {
        if ($flow !== null && $flow !== '') {
            $recorded = Cache::get($this->outcomeKey($flow));

            // Belongs to another inbox, or to nothing: answered as "still
            // going" rather than as "no such flow", so a flow id cannot be used
            // to find out what exists elsewhere.
            if (! is_array($recorded) || ($recorded['mailbox_id'] ?? null) !== (string) $mailbox->getKey()) {
                return ['state' => self::WAITING, 'message' => null];
            }

            return [
                'state' => (string) $recorded['state'],
                'message' => (string) $recorded['message'],
            ];
        }

        return $mailbox->hasCredential()
            ? ['state' => self::CONNECTED, 'message' => null]
            : ['state' => self::WAITING, 'message' => null];
    }

    private function outcomeKey(string $flow): string
    {
        return static::OUTCOME_KEY.$flow;
    }

    /**
     * Find the sign-in this `state` names, and destroy it in the same breath.
     *
     * ── The order is load bearing ────────────────────────────────
     *
     * Burn first, judge afterwards. An expired row is deleted rather than left
     * to be presented again, and a row whose delete lost a race is refused
     * before its verifier is ever handed to a caller. Checking expiry first and
     * deleting second would leave a stale row behind on the one path that
     * already knows it is stale.
     *
     * @throws MailOAuthRefused when nothing open answers to this value
     */
    public function complete(string $state): Model
    {
        $row = $this->rows()->where('state_hash', $this->fingerprint($state))->first();

        if ($row === null || ! $this->burn($row)) {
            throw MailOAuthRefused::noOpenSignIn(static::PROVIDER);
        }

        if ($row->hasExpired()) {
            throw MailOAuthRefused::noOpenSignIn(static::PROVIDER);
        }

        return $row;
    }

    /**
     * Destroy it, and say whether THIS call is the one that did.
     *
     * The return value is the single-use guarantee. `delete()` on a query
     * builder answers the number of rows the database actually removed, so two
     * simultaneous callbacks holding the same state both run this and exactly
     * one of them gets 1 back — the other gets 0 and is refused. There is no
     * read-then-write window for the second one to slip through.
     */
    private function burn(Model $row): bool
    {
        return $this->rows()->whereKey($row->getKey())->delete() === 1;
    }

    /**
     * Clear out sign-ins nobody finished.
     *
     * Every organization's, not only the caller's, and that is deliberate: what
     * is being deleted is rows whose `expires_at` has already passed, which name
     * no one and open nothing. Restricting it to the current tenant would leave
     * an organization that stopped connecting mailboxes with abandoned rows
     * nothing ever visits — a table that only ever grows, holding encrypted
     * secrets that stopped meaning anything ten minutes after they were written.
     *
     * Run on `begin` rather than from the nightly sweep because the sweep's
     * shortest honest window is a month (see RetentionPolicy::window) and this
     * lives ten minutes. The index on `expires_at` is what keeps it cheap.
     */
    private function clearExpired(): void
    {
        $this->rows()->where('expires_at', '<', now())->delete();
    }

    /**
     * What is stored in place of the state.
     *
     * SHA-256 with no salt and no work factor, matching
     * App\Services\Hr\Invitations and App\Services\Auth\PasswordResets — and the
     * reasoning is theirs: this is not a password. It is 64 characters of
     * CSPRNG output with no structure to guess at and a ten-minute life, so
     * there is no dictionary to slow down, and a bcrypt round on the lookup path
     * would buy nothing while making a callback measurably slower.
     */
    private function fingerprint(string $state): string
    {
        return hash('sha256', $state);
    }

    private function rows(): Builder
    {
        return (static::MODEL)::query();
    }
}
