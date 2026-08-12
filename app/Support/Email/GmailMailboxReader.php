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

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        if (! $this->platformIsRegistered()) {
            return 'EzBizi has no Google connection set up on this installation yet, so no '
                .'Google mailbox can be opened. Nothing is wrong with this address — the '
                .'missing piece is at EzBizi\'s end.';
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
            throw CannotReadMailbox::providerUnavailable($mailbox->address, $e->getMessage());
        }

        if ($response->status() === 400 || $response->status() === 401) {
            // Google says 400 `invalid_grant` for a revoked or expired refresh
            // token, which is the commonest real failure here: somebody removed
            // EzBizi from their Google account's connected apps.
            throw CannotReadMailbox::credentialRejected(
                $mailbox->address,
                (string) ($response->json('error_description') ?? $response->json('error') ?? ''),
            );
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw CannotReadMailbox::providerUnavailable(
                $mailbox->address,
                'the sign-in service answered '.$response->status(),
            );
        }

        return (string) $response->json('access_token');
    }

    /** @param array<string, mixed> $query */
    private function get(Mailbox $mailbox, string $token, string $url, array $query): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::TIMEOUT)->get($url, $query);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::providerUnavailable($mailbox->address, $e->getMessage());
        }

        return $this->body($mailbox, $response);
    }

    /** @param array<string, mixed> $payload */
    private function post(Mailbox $mailbox, string $token, string $url, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(self::TIMEOUT)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::providerUnavailable($mailbox->address, $e->getMessage());
        }

        return $this->body($mailbox, $response);
    }

    private function body(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw CannotReadMailbox::credentialRejected(
                $mailbox->address,
                (string) ($response->json('error.message') ?? ''),
            );
        }

        if ($response->failed()) {
            throw CannotReadMailbox::providerUnavailable(
                $mailbox->address,
                'the mail service answered '.$response->status(),
            );
        }

        return (array) $response->json();
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
