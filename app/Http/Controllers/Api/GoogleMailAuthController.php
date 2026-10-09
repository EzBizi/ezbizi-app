<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\GoogleMailSignIn;
use App\Models\GoogleWorkspaceConnection;
use App\Models\GoogleWorkspaceSignIn;
use App\Models\Mailbox;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Email\GoogleOAuth;
use App\Support\Email\GoogleOAuthGrant;
use App\Support\Email\GoogleOAuthSignIns;
use App\Support\Email\GoogleWorkspace;
use App\Support\Email\GoogleWorkspaceRefused;
use App\Support\Email\GoogleWorkspaceSignIns;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxProvider;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MailOAuthRefused;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Signing in to Google, so a shared address becomes a readable mailbox.
 *
 * Three steps, and they are deliberately unlike each other.
 *
 *   {@see start} is an ORDINARY AUTHENTICATED ENDPOINT. It asks the same
 *   questions every other management route asks — permission, reach — and
 *   answers JSON with a URL for the client to open.
 *
 *   {@see callback} is where Google's redirect lands. It is public, it answers
 *   HTML, and the `state` it is handed is the only thing identifying anybody.
 *
 *   {@see status} is what the screen that opened the consent window polls,
 *   because that window stops being the client's the moment it navigates and a
 *   browser will not let an opener read across origins. The server is the only
 *   party that saw the token arrive.
 *
 * ── The two authenticated routes are PROVIDER-NEUTRAL, and this is not ─
 *
 * They are `oauth/start` and `oauth/status` rather than `google/…`, because
 * "send somebody to their mail service and wait" is the same shape for every
 * provider that will ever have a sign-in — Microsoft 365 is the obvious next
 * one — and the screen that calls them deliberately never spells "Google": it
 * prints the provider label the server publishes.
 *
 * What is Google-specific is this controller and the callback path, and that
 * pairing is right rather than an oversight. A redirect URI is registered at ONE
 * provider, character for character, so a second provider needs a second
 * callback rather than a shared one — while `start` on a mailbox whose provider
 * has no sign-in flow refuses in a sentence naming the mailbox. When the second
 * provider arrives, what goes in front of these routes is a resolver like
 * App\Support\Email\MailboxReaders, and the client does not change.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHY THE CALLBACK CANNOT BE AUTHENTICATED, AND WHAT REPLACES IT.
 *
 *  Google finishes a flow by redirecting a BROWSER. A redirect sends
 *  no Authorization header, so `auth:sanctum` would refuse every
 *  legitimate callback there will ever be — there is nothing to fix
 *  by adding middleware.
 *
 *  What stands in its place is the state row, and it is stricter than
 *  a session in the one way that matters here: a session says who is
 *  asking, whereas the row says who, in which organization, for which
 *  inbox, and that this exact sign-in was started once and has not
 *  been finished. See App\Support\Email\GoogleOAuthSignIns.
 *
 *  Nothing else in this request is believed. The mailbox is not in
 *  the query string. The organization is not in the query string. The
 *  redirect URI is not in the query string — it is config, because a
 *  callback that reflected one would be an open redirector, which is
 *  how authorization codes leak. What arrives from outside is exactly
 *  two values: `state`, which names a row, and `code`, which is
 *  useless without the PKCE verifier on that row.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why this one endpoint answers HTML ───────────────────────────
 *
 * Every other route in this application answers JSON to a Flutter client. This
 * one is the single place where a BROWSER lands directly, sent by somebody
 * else's server, and there is no client screen behind it — the person is looking
 * at whatever this returns. Raw JSON on that page is a dead end (R8, and "no
 * dead ends"), so it returns a small page that says in a sentence what happened
 * and what to do next.
 *
 * The page is self-contained: no script, no stylesheet, no image, no font, and
 * every value in it escaped. It has to render on a phone that has just come back
 * from a Google consent screen (R7), which is why it is one column with a
 * max-width rather than a layout.
 *
 * ── This module's automations, and why this adds none ────────────
 *
 * The Email module already declares its triggers, and the one that matters for
 * a connection is `email.mailbox_broken` — raised by
 * App\Support\Email\RecordingMailboxReader when a sign-in stops being accepted,
 * which includes the refresh token minted here being withdrawn later. A
 * successful sign-in raises nothing new, and that is a decision rather than an
 * omission: the trigger catalogue is declared in ModuleServiceProvider, and a
 * `email.mailbox_connected` trigger belongs there rather than being emitted from
 * here — App\Support\Automations\EventEmitter refuses an unregistered key
 * outright, so emitting one without declaring it would fail loudly rather than
 * silently, and declaring it is an edit to a file this change does not own. What
 * IS recorded, here and now, is the audit entry: who connected which address,
 * when.
 */
class GoogleMailAuthController extends Controller
{
    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
        private readonly GoogleOAuth $google,
        private readonly GoogleOAuthSignIns $signIns,
        private readonly GoogleWorkspace $workspace,
        private readonly GoogleWorkspaceSignIns $workspaceSignIns,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/google/authorize
     *
     * Where to send somebody so they can hand EzBizi permission to read this
     * address.
     *
     * ── The same authority as connecting it by password ──────────
     *
     * `email.edit` plus §4 reach, asked of {@see MailboxAccess} — the one place
     * that question is answered — so pressing "Sign in with Google" needs
     * exactly what typing an IMAP password into
     * {@see MailboxesController::connect} needs. A second, looser rule on a
     * second connect path is how the strict one ends up being the one nobody
     * calls.
     *
     * A POST rather than a GET, and not for REST's sake: this WRITES a pending
     * sign-in row, and a GET that writes is a GET something will eventually
     * prefetch.
     */
    public function start(Request $request, Mailbox $mailbox): JsonResponse
    {
        $user    = $request->user();
        $refusal = $this->access->manageRefusal($user, $mailbox, 'connect this inbox');

        abort_if($refusal !== null, Status::HTTP_FORBIDDEN, $refusal);

        if ($mailbox->providerEnum() === MailboxProvider::MicrosoftOauth) {
            return app(MicrosoftMailAuthController::class)->start($request, $mailbox);
        }

        if ($mailbox->providerEnum() !== MailboxProvider::GmailOAuth) {
            // Not a permission failure and not a fault — the wrong door for this
            // inbox. 422 names the state of the record, the way the guard's
            // refusal does.
            return response()->json([
                'message' => sprintf(
                    '%s is not set up as a Google mailbox, so there is no Google sign-in to '
                    .'do. It is connected the way its own mail service expects. If it should '
                    .'be a Google mailbox, disconnect it and add the address again choosing '
                    .'Google.',
                    $mailbox->address,
                ),
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * The installation's own answer, asked of the reader rather than of
         * config here.
         *
         * `notReadyBecause()` is the sentence the mailbox catalogue already
         * publishes as `unusable_because`, so the screen that hides the button
         * and the endpoint that refuses the request are reading the same fact
         * from the same place. Duplicating the config check here is how the two
         * come to disagree — and the one that would be wrong is always this one,
         * because it is not the one anybody looks at.
         */
        $notReady = $this->readers->forProvider(MailboxProvider::GmailOAuth)->notReadyBecause();

        if ($notReady !== null) {
            return response()->json(['message' => $notReady], Status::HTTP_NOT_IMPLEMENTED);
        }

        try {
            $begun = $this->signIns->begin($user, $mailbox);
        } catch (MailOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // That a sign-in was STARTED, never the state or the URL. The URL
        // carries the state, and an audit table is read by more people than a
        // credential store is.
        $this->audit->log('mailbox.google_sign_in_started', $mailbox, [
            'address' => $mailbox->address,
        ]);

        return response()->json(['data' => [
            'authorization_url' => $begun['url'],

            /*
             * Which attempt this is, so {@see status} can answer about THIS one.
             *
             * The screen opens a consent window it cannot see into and then
             * polls. Without a flow the only question the server could answer is
             * "does this inbox have a credential yet", which is also true of the
             * attempt somebody abandoned ten minutes ago — so a second press
             * would report success it had nothing to do with.
             */
            'flow' => $begun['flow'],

            'expires_at' => $begun['expires_at']->toIso8601String(),

            // What the screen should say before sending somebody off, so the
            // consent screen is not the first place they learn which account
            // to choose. The mismatch this prevents is the expensive one — see
            // MailOAuthRefused::wrongAccount.
            'sign_in_as' => $mailbox->address,

            // Published rather than described, so a client never has to hard-code
            // a list of permissions that could drift from what is actually asked.
            'scopes' => [GoogleOAuth::SCOPE_READ, GoogleOAuth::SCOPE_SEND],
        ]]);
    }

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/oauth/status — did it land?
     *
     * ── Why this endpoint has to exist ───────────────────────────
     *
     * The consent window stops belonging to the client the moment it navigates
     * to Google, and a browser will not let an opener read across origins. So
     * the screen that started the sign-in cannot see it finish. The server is
     * the only party that saw the token arrive, which is why the server is what
     * gets asked — and it means the answer is the same whether the callback
     * closes the window, renders a page, or does nothing at all.
     *
     * ── Polled, so it does the least it can ──────────────────────
     *
     * A screen asks every couple of seconds for up to five minutes. It reads one
     * cache entry and writes nothing: no audit row per poll (which would bury
     * the entry that matters under a hundred that do not), no provider call, and
     * nothing that touches `last_reached_at`.
     *
     * The permission is asked every time all the same. A polling endpoint that
     * checked authority once at the start is an endpoint that keeps answering
     * for somebody whose access was withdrawn while they watched.
     */
    public function status(Request $request, Mailbox $mailbox): JsonResponse
    {
        $refusal = $this->access->manageRefusal($request->user(), $mailbox, 'connect this inbox');

        abort_if($refusal !== null, Status::HTTP_FORBIDDEN, $refusal);

        if ($mailbox->providerEnum() === MailboxProvider::MicrosoftOauth) {
            return app(MicrosoftMailAuthController::class)->status($request, $mailbox);
        }

        $flow = $request->query('flow');

        return response()->json([
            'data' => $this->signIns->outcome($mailbox, is_string($flow) ? $flow : null),
        ]);
    }

    /**
     * GET /api/v1/email/google/callback — where Google sends the browser back.
     *
     * Public, unauthenticated, and the whole security surface of this feature.
     * The order below is the order it has to be in:
     *
     *   1. BURN THE STATE FIRST, before anything is exchanged or read. A replay
     *      has to die at the first step whatever else is wrong with it, and the
     *      row is what says which organization the rest of this may touch.
     *   2. Only then look at Google's `error`, because a declined consent
     *      arrives with a state too and that state must not survive it.
     *   3. Enter the organization named ON THE ROW, and resolve everything else
     *      inside it.
     *   4. Re-ask whether this person may still connect this inbox.
     *   5. Exchange, identify, compare, and only then store.
     */
    public function callback(Request $request): Response
    {
        $state = (string) $request->query('state', '');

        /*
         * Held outside the try so the refusal can be reported to the screen that
         * is polling. A sign-in that fails at Google is otherwise visible only
         * to whoever is looking at the browser tab — and that is very often not
         * the same window as the one waiting, because a consent screen opens
         * beside the app rather than inside it.
         *
         * Null while there is no row, which is the "unknown state" case: there
         * is nothing to key an outcome to, and nothing that could legitimately
         * be waiting on it.
         */
        $signIn          = null;
        $workspaceSignIn = null;

        try {
            if ($state === '') {
                throw MailOAuthRefused::noOpenSignIn('Google');
            }

            $workspaceSignIn = $this->workspaceSignIns->completeIfPresent($state);

            if ($workspaceSignIn !== null) {
                if ($request->query('error') !== null) {
                    throw MailOAuthRefused::declined('Google', 'Allow');
                }

                $code = (string) $request->query('code', '');

                if ($code === '') {
                    throw MailOAuthRefused::exchangeFailed('Google');
                }

                return $this->finishWorkspace($workspaceSignIn, $code);
            }

            $signIn = $this->signIns->complete($state);

            /*
             * Google's own refusal — `access_denied` when somebody presses
             * Cancel, and a handful of others.
             *
             * Checked AFTER the burn on purpose: this arrives with a valid
             * state, and leaving that state alive because the flow failed would
             * mean a link that can be tried again — which is the one property
             * single use exists to remove.
             *
             * The parameter is not echoed. It is Google's vocabulary, it says
             * nothing a person can act on, and it is a value an attacker
             * controls in a page this server renders.
             */
            if ($request->query('error') !== null) {
                throw MailOAuthRefused::declined('Google', 'Allow');
            }

            $code = (string) $request->query('code', '');

            if ($code === '') {
                throw MailOAuthRefused::exchangeFailed('Google');
            }

            return $this->finish($signIn, $code);
        } catch (MailOAuthRefused $e) {
            if ($workspaceSignIn !== null) {
                $this->workspaceSignIns->record($workspaceSignIn, GoogleWorkspaceSignIns::REFUSED, $e->getMessage());
            }

            if ($signIn !== null) {
                // The same sentence the browser is about to read, so the screen
                // that was waiting says exactly what the tab says. The likeliest
                // one names the account that was signed in with and the address
                // it does not match, and that pair is the whole of what somebody
                // can act on.
                $this->signIns->record($signIn, GoogleOAuthSignIns::REFUSED, $e->getMessage());
            }

            return $this->page('That did not work', $e->getMessage(), $e->status);
        } catch (GoogleWorkspaceRefused $e) {
            if ($workspaceSignIn !== null) {
                $this->workspaceSignIns->record($workspaceSignIn, GoogleWorkspaceSignIns::REFUSED, $e->getMessage());
            }

            return $this->page('That did not work', $e->getMessage(), $e->status);
        }
    }

    /**
     * Prove a Workspace administrator runs the domain named on the sign-in row.
     *
     * @throws MailOAuthRefused
     * @throws GoogleWorkspaceRefused
     */
    private function finishWorkspace(GoogleWorkspaceSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;

        if ($organization === null) {
            throw MailOAuthRefused::noLongerPermitted(
                'The business this sign-in belongs to is no longer set up in EzBizi.',
            );
        }

        return $this->tenant->run($organization, function () use ($signIn, $code, $organization) {
            $user = User::find($signIn->user_id);

            if ($user === null || ! $user->is_active
                || ! $user->hasPermission('email.edit')
                || ! $user->canActOnOrganization($organization)) {
                throw MailOAuthRefused::noLongerPermitted(
                    'The person who started it is no longer able to connect Google Workspace. '
                    .'Somebody who can manage Email at the organization needs to connect it.',
                );
            }

            $identity = $this->google->identifyWorkspaceAccount($code, $signIn->code_verifier);

            if ($identity['domain'] !== $signIn->domain) {
                throw GoogleWorkspaceRefused::wrongDomain($signIn->domain, $identity['domain']);
            }

            $connection = $this->workspace->bind(
                $organization,
                $identity['domain'],
                $identity['email'],
                $signIn->scope_type ?: 'organization',
                $signIn->scope_id ?: $organization->getKey(),
            );

            $said = $connection->status === GoogleWorkspaceConnection::STATUS_CONNECTED
                ? $identity['domain'].' is connected to EzBizi. You can close this window and '
                    .'pick shared addresses like info@ from the directory under Email → Settings. '
                    .'EzBizi will not import anyone\'s personal mailbox — only the addresses you pick.'
                : (string) $connection->status_reason;

            $this->workspaceSignIns->record($signIn, GoogleWorkspaceSignIns::CONNECTED, $said);

            $this->audit->log('workspace.connected', $organization, [
                'domain'      => $identity['domain'],
                'admin_email' => $identity['email'],
                'status'      => $connection->status,
            ], actor: $user);

            $heading = $connection->status === GoogleWorkspaceConnection::STATUS_CONNECTED
                ? 'Connected'
                : 'Almost connected';

            return $this->page($heading, $said, Status::HTTP_OK);
        });
    }

    /**
     * Everything that happens inside the organization the state row names.
     *
     * @throws MailOAuthRefused
     */
    private function finish(GoogleMailSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;

        if ($organization === null) {
            throw MailOAuthRefused::noLongerPermitted(
                'The business this sign-in belongs to is no longer set up in EzBizi.',
            );
        }

        /*
         * TenantContext::run, exactly as InvitationAcceptanceController does it
         * and for the reason recorded there: `set()` would leave this
         * organization in place for whatever the process handles next, and this
         * request arrived with no tenant of its own to restore.
         *
         * It is also the cross-tenant lock. Every read below happens inside this
         * organization, so the mailbox id on the row can only ever resolve to a
         * mailbox in it — a state minted in one tenant cannot reach another's
         * inbox even if somebody knew its id.
         */
        return $this->tenant->run($organization, function () use ($signIn, $code) {
            $mailbox = Mailbox::find($signIn->mailbox_id);

            if ($mailbox === null) {
                throw MailOAuthRefused::noLongerPermitted(
                    'That inbox is no longer connected to EzBizi — somebody disconnected it '
                    .'while this sign-in was open. Add the address again if it is still '
                    .'wanted.',
                );
            }

            $user = User::find($signIn->user_id);

            /*
             * ASKED AGAIN, not merely recorded.
             *
             * Ten minutes is long enough for somebody to be deactivated, moved
             * to a franchise below this inbox, or have their role's permissions
             * changed — and the request that finishes this flow carries no
             * session for any of that to be re-evaluated against. So the
             * question MailboxAccess answered at the start is put to it again
             * with the same words, and a person who may no longer connect this
             * inbox does not get to finish connecting it.
             */
            if ($user === null || ! $user->is_active
                || $this->access->manageRefusal($user, $mailbox, 'connect this inbox') !== null) {
                throw MailOAuthRefused::noLongerPermitted(
                    'The person who started it is no longer able to connect this inbox. '
                    .'Somebody who can manage mailboxes needs to connect the address.',
                );
            }

            $grant = $this->google->exchange($code, $signIn->code_verifier);

            $this->assertItIsTheRightAccount($grant, $mailbox->address);

            $this->store($mailbox, $user, $grant);

            $said = sprintf(
                '%s is connected to EzBizi and can be opened from the Email tab by everybody '
                .'whose role has been given this inbox. You can close this window. EzBizi '
                .'reads and replies to this mailbox only; it does not delete anything and it '
                .'does not mark anything as read in Gmail.',
                $mailbox->address,
            );

            // Recorded before the page is built, because the screen that is
            // polling is the one that will act on it — the browser tab this
            // returns to is very often not the window somebody is looking at.
            $this->signIns->record($signIn, GoogleOAuthSignIns::CONNECTED, $said);

            return $this->page('Connected', $said, Status::HTTP_OK);
        });
    }

    /**
     * THE CHECK THAT STOPS A PERSON'S PRIVATE MAIL BECOMING A SHARED INBOX.
     *
     * ═══════════════════════════════════════════════════════════════
     *  The failure it prevents is silent and looks exactly like
     *  success. An administrator adds info@a-1chimney.com, is sent to
     *  Google, and signs in with whichever account the browser was
     *  already signed in to — their own. Without this, the
     *  organization has just connected that person's private mail to
     *  an inbox everybody with the grant can read, and the screen says
     *  "connected".
     *
     *  `login_hint` on the authorize URL makes it less likely and
     *  proves nothing: it is a suggestion somebody can click straight
     *  past on the account chooser.
     *
     *  Google would not say WHICH account it is — the scopes are still
     *  what was granted — is treated as a mismatch, not as a pass. "We
     *  could not check" and "it matched" must never be the same
     *  outcome, because the first one silently becomes the second the
     *  day Google's profile endpoint has an outage.
     * ═══════════════════════════════════════════════════════════════
     *
     * Whatever happens after this point, the grant is handed back to Google
     * rather than merely dropped — this application briefly holds lasting
     * permission to a private mailbox and must not be the reason it stays alive.
     *
     * @throws MailOAuthRefused
     */
    private function assertItIsTheRightAccount(GoogleOAuthGrant $grant, string $address): void
    {
        $signedInAs = $this->google->identify($grant->accessToken);

        if ($signedInAs !== strtolower($address)) {
            $this->google->revoke($grant->refreshToken);

            throw MailOAuthRefused::wrongAccount(
                'Google',
                $address,
                // Trimmed, and never trusted as markup: it is rendered through
                // the escaping in the mail-auth page view, and it is an account name
                // somebody else chose.
                $signedInAs === null ? 'a Google account EzBizi could not identify' : $signedInAs,
            );
        }

        /*
         * The right account, and not enough permission to be worth connecting.
         *
         * Google's consent screen lets somebody clear a tick box. Storing the
         * credential anyway would produce a mailbox reading "connected" that
         * fails the first time anybody opens it, with a permission message
         * nobody could trace back to a box they cleared ten minutes earlier.
         */
        if (! $grant->covers(GoogleOAuth::SCOPE_READ, GoogleOAuth::SCOPE_SEND)) {
            $this->google->revoke($grant->refreshToken);

            throw MailOAuthRefused::permissionNotGiven('Google', $address);
        }
    }

    /**
     * Write the refresh token, the way every other mailbox credential is written.
     *
     * `Mailbox::connect()` and nothing else — the only method in the application
     * that assigns to `credentials`, which is encrypted at rest, masked on every
     * serialisation, and unrevealable through any API path (see
     * `Mailbox::mayReveal`, which answers false for everybody). This flow adds no
     * second storage path for the highest-value secret in the system; it feeds
     * the existing one.
     *
     * @throws MailOAuthRefused
     */
    private function store(Mailbox $mailbox, User $user, GoogleOAuthGrant $grant): void
    {
        try {
            DB::transaction(function () use ($mailbox, $user, $grant) {
                $mailbox->connect($grant->refreshToken);
                $mailbox->save();

                /*
                 * What happened, never what was handed over — the same rule
                 * MailboxesController::connect follows.
                 *
                 * The GOOGLE ACCOUNT is not recorded either, and that is a
                 * separate decision worth stating: on the success path it is the
                 * mailbox address and already known, and on the mismatch path it
                 * is a private individual's own address. Writing it into a table
                 * colleagues can read would turn a refusal that protected
                 * somebody's privacy into the thing that published it.
                 */
                $this->audit->log('mailbox.connected', $mailbox, [
                    'address'  => $mailbox->address,
                    'provider' => $mailbox->provider,
                    'how'      => 'google_sign_in',
                ], actor: $user);
            });
        } catch (SubscriptionLapsedException) {
            /*
             * §13's read-only floor, reached with no middleware in front of it.
             *
             * This route is outside the `subscription` middleware — it has to
             * be, there is no authenticated user for it to read — so the refusal
             * arrives from the model layer as a 402 that would otherwise escape
             * as an unexplained error page. Caught and said plainly instead. The
             * credential is not stored, and the transaction has already rolled
             * back.
             */
            throw MailOAuthRefused::noLongerPermitted(
                'This EzBizi subscription has ended, so nothing new can be saved until it is '
                .'renewed. Mail is still arriving at the address in the meantime.',
            );
        }
    }

    /** One page for both providers' sign-in callbacks: resources/views/mail-auth/page.blade.php. */
    private function page(string $heading, string $sentence, int $status): Response
    {
        return response()->view('mail-auth.page', compact('heading', 'sentence'), $status);
    }
}
