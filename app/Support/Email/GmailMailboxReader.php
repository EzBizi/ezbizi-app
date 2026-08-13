<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gmail, over OAuth — the provider a customer should be steered to.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT IS BUILT HERE, AND WHAT CANNOT BE.
 *
 *  BUILT: the whole shape. The preflight (is there an OAuth client
 *  on this installation, is there a refresh token on this mailbox),
 *  the refusals, the token exchange, the three REST calls, the
 *  mapping — {@see GmailMessageMapper}, which is pure and is tested —
 *  and the reply path.
 *
 *  NOT BUILT, AND NOT FAKED: a Google Cloud project with the Gmail
 *  API enabled, an OAuth client, a verified consent screen, and the
 *  browser flow that turns a person's consent into the refresh token
 *  this class spends. None of that is code; it is an account somebody
 *  has to create, and a verification Google has to grant.
 *
 *  So on this installation `config('services.google_mail')` is empty
 *  and every method below refuses with
 *  {@see CannotReadMailbox::notBuiltHere} — which says it is at our
 *  end, in a sentence, rather than sending an administrator to check
 *  a password that is not the problem.
 *
 *  IT DOES NOT RETURN AN EMPTY INBOX. That is the whole point and it
 *  is stated at length on CannotReadMailbox: "nothing to show" and
 *  "we cannot look" are indistinguishable on screen, and only one of
 *  them is a reason to ring somebody.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The scope this asks for, when somebody wires the consent screen ─
 *
 * `gmail.modify` and not `mail.google.com`. The broad scope includes deleting
 * mail and administering the account; this module reads, replies and marks
 * nothing at Google at all. Asking for less is also what makes Google's own
 * verification survivable — a restricted-scope app faces a security assessment
 * whose cost is measured in thousands.
 *
 * ── The failures it CAN tell apart, and the one it cannot ────────
 *
 * This is the only reader on the installation that talks to a real service, so
 * it is the only one that can classify a refusal at all. It separates: the
 * tenant's sign-in being rejected, EZBIZI'S OWN registration being rejected, the
 * consent no longer covering mail, a quota, a service fault, and nothing
 * answering — see {@see tokenFailure} and {@see apiFailure}, which say how each
 * is recognised.
 *
 * What it cannot separate, and does not pretend to: WHY the sign-in stopped
 * being accepted. Withdrawn, expired, and invalidated by a password change all
 * come back as `invalid_grant` with nothing to tell them apart. That is the
 * owner's "revoked or expired", and the honest answer is one sentence naming all
 * three — {@see MailboxFault::SignInNoLongerValid}.
 *
 * None of these can be produced here without a Google account, which is exactly
 * why the mapping is built from the SHAPE of the responses Google documents
 * rather than from invented error bodies: a fake `invalid_grant` proves the
 * `match` arm reads the string it was handed and nothing more.
 */
final class GmailMailboxReader implements MailboxReader
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** Seconds. A mail list is somebody waiting at a screen. */
    private const TIMEOUT = 10;

    public function __construct(
        private readonly GmailMessageMapper $mapper,
        private readonly ReplyComposer $composer,
    ) {}

    // ── is there anything behind this at all ─────────────────────

    public function isConfigured(Mailbox $mailbox): bool
    {
        return $this->unavailableBecause($mailbox) === null;
    }

    /** The installation's answer, said once and asked from two places. */
    public function notReadyBecause(): ?string
    {
        return $this->platformIsRegistered() ? null
            : 'EzBizi has no Google connection set up on this installation yet, so no '
                .'Google mailbox can be opened and signing in would not complete. Nothing '
                .'is wrong with this address — the missing piece is at EzBizi\'s end.';
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            return $notReady;
        }

        if (! $mailbox->hasCredential()) {
            return 'Nobody has signed in to Google for this address yet, so EzBizi has no '
                .'permission to read it.';
        }

        return null;
    }

    /**
     * Does this installation have an OAuth client at all?
     *
     * Read from config rather than hardcoded, and the key is absent from
     * `config/services.php` today — which is not an oversight to correct here:
     * an absent key reads as null, null means "no client", and "no client" is
     * the true state of this installation. When somebody registers one, adding
     * the block is the only change needed and this class starts working.
     */
    private function platformIsRegistered(): bool
    {
        return is_string(config('services.google_mail.client_id'))
            && is_string(config('services.google_mail.client_secret'));
    }

    // ── reading ──────────────────────────────────────────────────

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25): MessagePage
    {
        return $this->page($mailbox, ['labelIds' => 'INBOX'], $cursor, $limit);
    }

    /**
     * Google's own search, passed through.
     *
     * The query is Gmail's syntax — `from:`, `has:attachment`, `after:` — and it
     * is handed over as the person typed it rather than translated. Translating
     * would mean inventing a query language, documenting it, and then explaining
     * why it is worse than the one already printed in Gmail's own help.
     *
     * It is sent as a QUERY PARAMETER through Laravel's HTTP client, which
     * encodes it. There is no string concatenation into a URL anywhere in this
     * class, which is what stops a search term containing `&` becoming a second
     * parameter.
     */
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25): MessagePage
    {
        return $this->page($mailbox, ['q' => $query], $cursor, $limit);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function page(Mailbox $mailbox, array $parameters, ?string $cursor, int $limit): MessagePage
    {
        $token = $this->accessToken($mailbox);

        $listing = $this->get($mailbox, $token, self::API.'/messages', [
            ...$parameters,
            // Clamped here rather than trusted from the caller: a controller
            // validates it too, and a page size of 5000 against a third party's
            // API is a way of using somebody else's rate limit up.
            'maxResults' => max(1, min($limit, 100)),
            ...($cursor === null ? [] : ['pageToken' => $cursor]),
        ]);

        $messages = [];

        foreach ((array) ($listing['messages'] ?? []) as $stub) {
            $id = is_array($stub) ? (string) ($stub['id'] ?? '') : '';

            if ($id === '') {
                continue;
            }

            /*
             * `format=metadata`, and the headers named explicitly.
             *
             * A list must not download bodies: twenty-five messages with
             * attachments is megabytes over the wire to render twenty-five
             * lines of text. Naming the headers also means Google returns
             * roughly a kilobyte per message rather than every Received: hop.
             */
            $messages[] = $this->mapper->summary($this->get($mailbox, $token, self::API."/messages/{$id}", [
                'format'         => 'metadata',
                'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
            ]));
        }

        return new MessagePage(
            messages: $messages,
            nextCursor: isset($listing['nextPageToken']) ? (string) $listing['nextPageToken'] : null,
            approximateTotal: isset($listing['resultSizeEstimate'])
                ? (int) $listing['resultSizeEstimate']
                : null,
        );
    }

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        $token = $this->accessToken($mailbox);

        return $this->mapper->detail(
            $this->get($mailbox, $token, self::API.'/messages/'.rawurlencode($messageId), ['format' => 'full'])
        );
    }

    // ── replying ─────────────────────────────────────────────────

    /**
     * The reply goes out AS THE MAILBOX, and there is no way to make it not.
     *
     * The From line is `$mailbox->display_name <$mailbox->address>`, composed
     * here from the record. Nothing about the signed-in person reaches the
     * message: no From, no Reply-To, no Sender header. *"info replies as
     * info"*.
     *
     * ── Why this path has no SMTP host and no SSRF guard ─────────
     *
     * Because Gmail's API sends it. `messages.send` posts the message to
     * Google, over HTTPS, to a hostname compiled into this file — there is no
     * host anybody could choose and therefore nothing to guard. The IMAP path
     * is the one that dials a host somebody typed, and {@see ImapMailboxReader}
     * applies {@see RemoteHostGuard} to its SMTP side with the same ranges the
     * IMAP side uses.
     */
    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $token    = $this->accessToken($mailbox);
        $original = $this->get($mailbox, $token, self::API.'/messages/'.rawurlencode($messageId), [
            'format'          => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Message-ID', 'References'],
        ]);

        $summary = $this->mapper->summary($original);

        $this->post($mailbox, $token, self::API.'/messages/send', [
            'threadId' => $summary->threadId,

            /*
             * Composed by {@see ReplyComposer}, which is where the "info replies
             * as info" rule and the header-injection guard both live — and which
             * has no User parameter to pass, so this call could not put the
             * individual on the message even if it wanted to.
             *
             * Split out of this class rather than kept here because it is the
             * half of the reply path that can be exercised without a Google
             * account, and a header-injection hole in an untestable private
             * method is a hole nothing can be asserted about.
             */
            'raw' => $this->composer->composeForGmail(
                $mailbox,
                $summary,
                $this->headerMap($original),
                $body,
            ),
        ]);
    }

    /**
     * Gmail's header list as a lower-cased map, for threading.
     *
     * The same shape GmailMessageMapper builds internally, and duplicated here
     * rather than exposed from there on purpose: that class's job is to produce
     * this application's own objects, and a public "give me the raw headers"
     * method on it would be an invitation to bypass the mapping entirely.
     *
     * @param  array<string, mixed>  $original
     * @return array<string, string>
     */
    private function headerMap(array $original): array
    {
        $headers = [];

        foreach ((array) ($original['payload']['headers'] ?? []) as $header) {
            if (is_array($header) && isset($header['name'])) {
                $headers[strtolower((string) $header['name'])] ??= (string) ($header['value'] ?? '');
            }
        }

        return $headers;
    }

    // ── the wire ─────────────────────────────────────────────────

    /**
     * A refresh token spent for an access token.
     *
     * The refresh token is the stored secret and never leaves this method; the
     * access token it buys lives for an hour and is held for the length of one
     * request. Nothing caches it across requests deliberately — a cached OAuth
     * token is a copy of a credential in a second store with different rules
     * about who can read it, and saving one HTTP round trip does not pay for
     * that.
     */
    private function accessToken(Mailbox $mailbox): string
    {
        $this->assertUsable($mailbox);

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id'     => config('services.google_mail.client_id'),
                    'client_secret' => config('services.google_mail.client_secret'),
                    'refresh_token' => $mailbox->credential(),
                    'grant_type'    => 'refresh_token',
                ]);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }

        if ($response->failed()) {
            throw $this->tokenFailure($mailbox, $response);
        }

        if (! is_string($response->json('access_token'))) {
            // A 2xx with nothing usable in it. Not a refusal — the service
            // answered and answered wrongly — so it is the provider's fault and
            // must not be written onto the mailbox as "your sign-in is bad".
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status().' with no sign-in in it',
            );
        }

        return (string) $response->json('access_token');
    }

    /**
     * WHOSE sign-in the token endpoint just rejected — the tenant's, or ours.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE DISTINCTION THAT WAS MISSING, AND IT MATTERED.
     *
     *  400 and 401 were caught by one branch and both reported as the
     *  mailbox's credential being rejected. They are not the same
     *  thing at all. OAuth2 gives the token endpoint two failures that
     *  look alike and blame opposite parties:
     *
     *    `invalid_grant`     — the REFRESH TOKEN on this mailbox is no
     *                          longer good. The tenant's problem, and
     *                          reconnecting fixes it.
     *    `invalid_client` /
     *    `unauthorized_client` — the CLIENT ID AND SECRET are no
     *                          longer good. That is EzBizi's own
     *                          registration with Google, identical for
     *                          every tenant on this installation, and
     *                          nothing a customer can do touches it.
     *
     *  Lumped together, an installation whose Google registration had
     *  lapsed would have told every organization, individually and
     *  confidently, that somebody had changed their password — and a
     *  conscientious administrator would have gone and changed it.
     * ═══════════════════════════════════════════════════════════════
     *
     * Classified on the `error` field rather than on the status code, because
     * Google is not consistent about which of 400 and 401 carries
     * `invalid_client`. The code is the fallback for a body that says nothing.
     */
    private function tokenFailure(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): CannotReadMailbox
    {
        $error  = strtolower((string) ($response->json('error') ?? ''));
        $detail = (string) ($response->json('error_description') ?? $response->json('error') ?? '');

        if ($error === 'invalid_client' || $error === 'unauthorized_client') {
            return CannotReadMailbox::platformRejected($mailbox, $detail);
        }

        /*
         * The one the owner asked about, and all three of its meanings.
         *
         * `invalid_grant` is what Google says when the refresh token has been
         * withdrawn from the account's connected apps, when it has aged out
         * unused, and when the account password was changed. The reply is
         * identical in all three cases and there is nothing else in it to go on,
         * which is why the sentence names all three rather than picking one — see
         * MailboxFault::SignInNoLongerValid.
         */
        if ($error === 'invalid_grant') {
            return CannotReadMailbox::credentialRejected($mailbox, $detail);
        }

        return match (true) {
            $response->status() === 429 => CannotReadMailbox::tooManyRequests($mailbox, $detail),
            $response->serverError()    => CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status(),
            ),
            // A 401 with no `error` we recognise is still the endpoint refusing
            // to believe who WE are — nothing about a refresh token reaches an
            // unauthenticated client.
            $response->status() === 401 => CannotReadMailbox::platformRejected($mailbox, $detail),

            // Refused, and Google did not say enough to name a cause. Left
            // honest rather than folded into "your sign-in was revoked", which
            // is a guess wearing a diagnosis's clothes.
            default => CannotReadMailbox::providerRefused($mailbox, $detail),
        };
    }

    /** @param array<string, mixed> $query */
    private function get(Mailbox $mailbox, string $token, string $url, array $query): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::TIMEOUT)->get($url, $query);
        } catch (ConnectionException $e) {
            // No status code exists yet, which is itself the diagnosis: a
            // timeout, a refused connection and a name that will not resolve all
            // arrive here, and all three mean nothing answered.
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }

        return $this->body($mailbox, $response);
    }

    /** @param array<string, mixed> $payload */
    private function post(Mailbox $mailbox, string $token, string $url, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::TIMEOUT)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }

        return $this->body($mailbox, $response);
    }

    private function body(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): array
    {
        if ($response->failed()) {
            throw $this->apiFailure($mailbox, $response);
        }

        return (array) $response->json();
    }

    /**
     * What the Gmail API's refusal actually means.
     *
     * ── 403 is two completely different things ───────────────────
     *
     * It was being read as "your credential was rejected", and for one of its two
     * common meanings that is wrong in the most expensive direction. Gmail
     * answers 403 both for a consent that no longer covers reading mail
     * (`insufficientPermissions` — a real fault, fixed by reconnecting) and for
     * ASKING TOO OFTEN (`rateLimitExceeded`, `userRateLimitExceeded`,
     * `quotaExceeded` — nothing wrong at all, fixed by waiting a minute).
     *
     * Reported as one, the busy afternoon that trips a quota marks a perfectly
     * healthy inbox broken and sends somebody to reconnect it — which is real
     * work, done for nothing, ending in the same quota. Google names which it is
     * in `error.errors[].reason`, so this reads it instead of guessing.
     *
     * ── What still cannot be told apart ──────────────────────────
     *
     * A 401 here. The access token was minted seconds earlier, so a rejection now
     * means the grant behind it went away in between — withdrawn, expired, or
     * overtaken by a password change, and this response says no more about which
     * than the token endpoint did. It goes to the same three-in-one sentence, and
     * that sentence says so.
     */
    private function apiFailure(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): CannotReadMailbox
    {
        $detail = (string) ($response->json('error.message') ?? '');

        if ($response->status() === 403) {
            return $this->throttled($response)
                ? CannotReadMailbox::tooManyRequests($mailbox, $detail)
                : CannotReadMailbox::permissionNarrowed($mailbox, $detail);
        }

        return match (true) {
            $response->status() === 401 => CannotReadMailbox::credentialRejected($mailbox, $detail),
            $response->status() === 429 => CannotReadMailbox::tooManyRequests($mailbox, $detail),
            $response->serverError()    => CannotReadMailbox::providerFaulty(
                $mailbox,
                'the mail service answered '.$response->status(),
            ),
            default => CannotReadMailbox::providerRefused(
                $mailbox,
                $detail !== '' ? $detail : 'the mail service answered '.$response->status(),
            ),
        };
    }

    /**
     * Is this 403 a quota rather than a permission?
     *
     * Google's own reason strings, matched as a list rather than as a substring
     * search for "rate": `quotaExceeded` contains no such word and is the same
     * kind of answer. A reason this build has never heard of falls through to
     * "permission", which is the safer wrong answer of the two — it puts a
     * sentence in front of somebody, where treating a real permission failure as
     * a rate limit would quietly show a working-looking inbox that never loads.
     */
    private function throttled(\Illuminate\Http\Client\Response $response): bool
    {
        foreach ((array) ($response->json('error.errors') ?? []) as $error) {
            $reason = is_array($error) ? (string) ($error['reason'] ?? '') : '';

            if (in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The preflight, in one place, so no entry point can skip it.
     *
     * @throws CannotReadMailbox
     */
    private function assertUsable(Mailbox $mailbox): void
    {
        if (! $this->platformIsRegistered()) {
            throw CannotReadMailbox::notBuiltHere(
                MailboxProvider::GmailOAuth,
                'EzBizi has to be registered with Google before it can open a Google mailbox, '
                .'and that registration has not been done on this installation. Nothing about '
                .'this address or its password is wrong.',
            );
        }

        if (! $mailbox->hasCredential()) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::GmailOAuth);
        }
    }
}
