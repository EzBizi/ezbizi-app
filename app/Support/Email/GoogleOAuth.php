<?php

namespace App\Support\Email;

use App\Support\Pkce;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talking to Google about a sign-in — and nothing else.
 *
 * Everything in this class is the OAuth 2.0 authorization-code flow with PKCE
 * (RFC 6749 + RFC 7636) as Google implements it. It knows nothing about
 * mailboxes, organizations or permissions: {@see GoogleOAuthSignIns} owns the
 * state row, {@see \App\Http\Controllers\Api\GoogleMailAuthController} owns the
 * decisions, and this owns the wire. Split that way because the wire is the part
 * with four hard-coded endpoints and no branches worth arguing about, and the
 * decisions are the part worth arguing about at length.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE SCOPES, NAMED, AND WHY EACH ONE IS THE SMALLEST THAT WORKS.
 *
 *  `gmail.readonly` — read messages, list them, and search them.
 *    Every read path in {@see GmailMailboxReader} needs it: the
 *    listing, `format=metadata` header fetches, `format=full` when a
 *    message is opened, and the provider-side search. It also covers
 *    `users.getProfile`, which is what {@see identify} uses to find
 *    out which account actually signed in — so the identity check
 *    below costs no extra permission at all.
 *
 *  `gmail.send` — send a message as this mailbox, and nothing else.
 *    The reply path, and only the reply path. This scope cannot read,
 *    cannot label, cannot delete, and cannot see the mailbox at all.
 *
 *  WHAT IS DELIBERATELY NOT ASKED FOR, and what each would have cost:
 *
 *    `https://mail.google.com/` — the full-account scope. It includes
 *      permanently deleting mail and administering the account. Holding
 *      it would mean a stolen refresh token could empty somebody's
 *      mailbox. `gmail.modify` (Trash, labels) is asked for instead.
 *
 *    `openid` / `email` / `profile` — the conventional way to learn
 *      who signed in. Not asked for, because `users.getProfile`
 *      already answers a BETTER question with a scope we need anyway:
 *      `email` reports the account that authenticated, whereas
 *      getProfile reports the address of the mailbox the token
 *      actually opens. Those can differ — a delegated mailbox, an
 *      alias — and it is the second one that has to match.
 *
 *    Drive, Contacts, Calendar — nothing here touches any of them.
 *
 *  Both Gmail scopes are RESTRICTED at Google, which means the OAuth
 *  client needs verification and, for an app used outside one Google
 *  Workspace organization, a security assessment. That is a cost the
 *  installation's owner pays once and cannot be avoided by asking for
 *  less — `gmail.metadata` is restricted too, and cannot read a body.
 *  A Workspace organization connecting only its own addresses can use
 *  an INTERNAL consent screen and skip verification entirely, which is
 *  the ordinary case for this module.
 * ═══════════════════════════════════════════════════════════════════
 */
final class GoogleOAuth
{
    /** Read the mail. Also covers `users.getProfile` — see {@see identify}. */
    public const SCOPE_READ = 'https://www.googleapis.com/auth/gmail.readonly';

    /** Send as this mailbox. Cannot read, label or delete anything. */
    public const SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * Move mail to Trash and change labels. Not permanent delete.
     *
     * Asked for because the owner wanted Gmail's delete control on a
     * shared inbox. A stolen refresh token can bin mail; it cannot empty
     * the account the way `mail.google.com` could.
     */
    public const SCOPE_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    /**
     * The path Google redirects back to, as a LITERAL rather than a route
     * lookup.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE ONE THAT MUST NOT BE CLEVER.
     *
     *  `route('email.google.callback')` is the idiomatic Laravel way
     *  to build this and it is WRONG HERE, because the URL generator
     *  builds absolute URLs from the CURRENT REQUEST's host when one
     *  exists. The Host header is a request parameter. An attacker who
     *  can get a request through with `Host: evil.example` would move
     *  the `redirect_uri` this class sends to Google — which is the
     *  textbook open redirector in an OAuth callback, and the way
     *  authorization codes leak.
     *
     *  So the origin comes from config, the path is this constant, and
     *  nothing about either can be influenced by a request.
     *  GoogleMailOAuthTest asserts exactly that with a spoofed Host.
     *
     *  The cost of a literal is that it can drift from the registered
     *  route. That is closed by a test too — the callback path is
     *  resolved against the router, so renaming the route without
     *  changing this constant fails the build rather than the flow.
     * ═══════════════════════════════════════════════════════════════
     */
    public const CALLBACK_PATH = '/api/v1/email/google/callback';

    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    private const PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Seconds. Somebody is waiting at a screen for all three of these. */
    private const TIMEOUT = 10;

    // ── where Google is told to come back to ─────────────────────

    /**
     * The exact redirect URI, from config, checked before it is used.
     *
     * ── Why it is checked at all, when it comes from config ──────
     *
     * Because "it came from config" is a statement about who can change it, not
     * about whether it is right. The default is composed from `APP_URL`, which
     * is set once at deploy time and is wrong on more installations than anybody
     * would like to admit — and a `redirect_uri` that does not point at this
     * server's callback either fails at Google with a message about a
     * mismatched URI, or, in the version of this bug worth being afraid of,
     * succeeds and sends an authorization code somewhere else.
     *
     * So the value is required to be this application's own callback path, on an
     * absolute origin, with no query and no fragment. Anything else refuses in a
     * sentence naming what to fix, which is a better failure than an
     * unrecognisable Google error page — and it means "allow-listed" is
     * something this code does rather than something a comment claims.
     *
     * @throws MailOAuthRefused
     */
    public function redirectUri(): string
    {
        $configured = trim((string) config('services.google_mail.redirect'));
        $parts      = $configured === '' ? false : parse_url($configured);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw MailOAuthRefused::notConfigured(
                'Google',
                'The web address Google is meant to return people to has not been set up, so '
                .'there is nowhere for the sign-in to come back to.',
            );
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);

        // Google's own rule, applied here so the refusal names the problem
        // rather than arriving as an error page at Google. Plain HTTP is
        // accepted for a redirect URI only on localhost, and for a good reason:
        // an authorization code travelling over an unencrypted connection is a
        // code anybody on the network has.
        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw MailOAuthRefused::notConfigured(
                'Google',
                'The web address Google is meant to return people to is not a secure (https) '
                .'address, and Google will not send anybody to an insecure one.',
            );
        }

        if (($parts['path'] ?? '') !== self::CALLBACK_PATH
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw MailOAuthRefused::notConfigured(
                'Google',
                'The web address Google is meant to return people to does not point at this '
                .'EzBizi installation\'s sign-in page, so a sign-in would end up somewhere '
                .'else.',
            );
        }

        return $configured;
    }

    // ── step one: send somebody to Google ────────────────────────

    /**
     * Where to send the browser, with PKCE and the state already on it.
     *
     * @param  string  $state      the raw value whose hash names the stored row
     * @param  string  $verifier   the PKCE verifier; only its S256 hash goes out
     * @param  string  $loginHint  the mailbox address, so Google offers the right
     *                             account first — a nudge, never a check. The
     *                             check is {@see identify} and it happens after
     *                             the fact, because a hint is something a person
     *                             can click straight past.
     *
     * @throws MailOAuthRefused
     */
    public function authorizeUrl(string $state, string $verifier, string $loginHint): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id'     => (string) config('services.google_mail.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            // gmail.modify alone: it reads, sends and changes labels, so asking
            // for readonly and send beside it was more than least privilege
            // allows (Google's OAuth review, 2026-10-25).
            'scope'         => self::SCOPE_MODIFY,

            /*
             * The two parameters without which this whole module cannot work
             * unattended.
             *
             * `access_type=offline` is what makes Google issue a REFRESH token
             * rather than only an hour-long access token — an inbox that only
             * works while somebody is standing at a browser is not a shared
             * inbox.
             *
             * `prompt=consent` forces the consent screen even for an account
             * that has approved this application before. Without it, Google
             * issues a refresh token on the FIRST consent only, and every
             * reconnection after that comes back with an access token and
             * nothing lasting — which is exactly the state somebody reconnecting
             * a broken mailbox is trying to get out of.
             */
            'access_type' => 'offline',
            'prompt'      => 'consent',

            'state' => $state,

            // PKCE, S256 — RFC 7636. The code comes back through a browser
            // redirect, so it passes through an address bar, a history file and
            // this server's access log on its way here. The challenge is what
            // makes a code lifted from any of those useless without the verifier
            // that never left this server.
            'code_challenge'        => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',

            'login_hint' => $loginHint,
        ]);
    }

    // ── step two: turn the code into a lasting permission ────────

    /**
     * Exchange the authorization code, proving PKCE.
     *
     * The `redirect_uri` sent here is {@see redirectUri} again rather than
     * anything remembered from the authorize step, and that is deliberate: it
     * comes from config, config did not change while a consent screen was open,
     * and re-deriving it means there is exactly one place in this class that
     * decides what that value is. RFC 6749 §4.1.3 requires the two to match, and
     * they cannot fail to.
     *
     * @throws MailOAuthRefused
     */
    public function exchange(string $code, string $verifier): GoogleOAuthGrant
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_ENDPOINT, [
                'client_id'     => (string) config('services.google_mail.client_id'),
                'client_secret' => (string) config('services.google_mail.client_secret'),
                'code'          => $code,
                'code_verifier' => $verifier,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri(),
            ]);
        } catch (ConnectionException) {
            // Nothing answered. Deliberately not carrying the client's message
            // into the sentence: it is a cURL string written for a developer,
            // and the person reading this is looking at a web page.
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        if ($response->failed()) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        $refresh = $response->json('refresh_token');
        $access  = $response->json('access_token');

        if (! is_string($access) || $access === '') {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        // An access token without a refresh token is a connection that dies
        // within the hour. Refused rather than stored — see
        // MailOAuthRefused::noLastingPermission for why this is an anomaly
        // here rather than the ordinary second-consent case.
        if (! is_string($refresh) || $refresh === '') {
            throw MailOAuthRefused::noLastingPermission('Google');
        }

        return new GoogleOAuthGrant(
            refreshToken: $refresh,
            accessToken: $access,
            // Google returns the granted scopes space-separated, and they are
            // not necessarily the ones that were asked for.
            scopes: preg_split('/\s+/', trim((string) $response->json('scope')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    // ── step three: WHICH ACCOUNT WAS THAT ───────────────────────

    /**
     * The address of the mailbox this token actually opens.
     *
     * ── Why `users.getProfile` and not an id token ───────────────
     *
     * The conventional answer is to add `openid email` and read the address out
     * of the id token, and it answers a subtly different question: WHO
     * authenticated. What has to be compared against the mailbox is WHICH
     * MAILBOX THE TOKEN READS, and those come apart in exactly the cases a
     * business runs into — a delegated mailbox, an alias, a Workspace account
     * with several addresses.
     *
     * getProfile answers the second question, needs no scope beyond the read
     * scope this flow already asks for, and cannot be satisfied by an account
     * that has no Gmail mailbox at all. Two fewer scopes and a stronger check.
     *
     * @return string|null the address, or null when Google would not say —
     *                     which the caller must treat as a mismatch rather than
     *                     as a match, because "we could not check" and "it
     *                     matched" must never be the same outcome
     */
    public function identify(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(self::TIMEOUT)
                ->get(self::PROFILE_ENDPOINT);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $address = $response->json('emailAddress');

        return is_string($address) && trim($address) !== '' ? strtolower(trim($address)) : null;
    }

    /**
     * Where to send a Workspace administrator so they can prove they run a domain.
     *
     * `openid` and `email` only — this flow must not receive a Gmail refresh
     * token. `hd` is a hint Google honours for Workspace accounts; the check
     * after the fact is {@see identifyWorkspaceAccount}, because a hint is
     * something a person can click past.
     *
     * No `access_type=offline`: a lasting mail grant is the mailbox flow's job,
     * and this one must not mint one by accident.
     *
     * @throws MailOAuthRefused
     */
    public function workspaceAuthorizeUrl(string $state, string $verifier, string $hostedDomain): string
    {
        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id'             => (string) config('services.google_mail.client_id'),
            'redirect_uri'          => $this->redirectUri(),
            'response_type'         => 'code',
            'scope'                 => 'openid email',
            'hd'                    => strtolower($hostedDomain),
            'prompt'                => 'select_account',
            'state'                 => $state,
            'code_challenge'        => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Which Workspace domain the administrator actually signed in as.
     *
     * `hd` is Google's hosted-domain claim. A personal Gmail account has none,
     * and "we could not check" is treated as not a Workspace — same polarity as
     * {@see identify}: absence is never a pass.
     *
     * @return array{email: string, domain: string}
     *
     * @throws MailOAuthRefused
     * @throws GoogleWorkspaceRefused
     */
    public function identifyWorkspaceAccount(string $code, string $verifier): array
    {
        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_ENDPOINT, [
                'client_id'     => (string) config('services.google_mail.client_id'),
                'client_secret' => (string) config('services.google_mail.client_secret'),
                'code'          => $code,
                'code_verifier' => $verifier,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri(),
            ]);
        } catch (ConnectionException) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        $access  = (string) $response->json('access_token');
        $refresh = $response->json('refresh_token');

        // This flow asked for identity only. A refresh token here is leftover
        // permission we did not want; hand it back rather than hold it.
        if (is_string($refresh) && $refresh !== '') {
            $this->revoke($refresh);
        }

        try {
            $info = Http::withToken($access)
                ->timeout(self::TIMEOUT)
                ->get(self::USERINFO_ENDPOINT);
        } catch (ConnectionException) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        if ($info->failed()) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        $email = strtolower(trim((string) $info->json('email')));
        $hd    = strtolower(trim((string) $info->json('hd')));

        if ($email === '' || ! str_contains($email, '@')) {
            throw MailOAuthRefused::exchangeFailed('Google');
        }

        if ($hd === '') {
            throw GoogleWorkspaceRefused::notAWorkspace();
        }

        return ['email' => $email, 'domain' => $hd];
    }

    // ── the disposal path ────────────────────────────────────────

    /**
     * Hand a token back to Google, because we should not be holding it.
     *
     * Called when the sign-in is refused AFTER the exchange has already
     * succeeded — the mismatched-account case above all, where this application
     * momentarily holds lasting permission to a private mailbox it has just
     * decided it must not have. Dropping the variable would leave that
     * permission alive on the person's account until somebody noticed it in
     * their security settings, which is not the same as not having taken it.
     *
     * BEST EFFORT, AND SILENT. Every failure here is swallowed, and that is the
     * right trade rather than laziness: the caller is already on its way to
     * telling somebody the sign-in was refused, and turning "we could not also
     * revoke it" into a different, more alarming message would replace a clear
     * refusal with a confusing one. The token is never stored either way, so the
     * worst case is a dormant grant the person can remove themselves — which is
     * what the mismatch sentence tells them how to do.
     */
    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(self::TIMEOUT)->post(self::REVOKE_ENDPOINT, ['token' => $token]);
        } catch (\Throwable) {
            // Deliberately nothing. See above.
        }
    }
}
