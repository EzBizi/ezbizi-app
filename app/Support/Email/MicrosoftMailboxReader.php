<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365, over OAuth — fourth provider, per-inbox like Gmail.
 *
 * Graph reads. Nothing stored except tokens/metadata. Shared team inboxes only.
 */
final class MicrosoftMailboxReader implements MailboxReader
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const INBOX = self::GRAPH.'/me/mailFolders/inbox/messages';

    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const TIMEOUT = 10;

    private const MAX_DELTA_PAGES = 10;

    private const LIST_SELECT = 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,hasAttachments,isRead';

    public function __construct(
        private readonly MicrosoftMessageMapper $mapper,
    ) {}

    public function isConfigured(Mailbox $mailbox): bool
    {
        return $this->unavailableBecause($mailbox) === null;
    }

    public function notReadyBecause(): ?string
    {
        $id     = config('services.microsoft_mail.client_id');
        $secret = config('services.microsoft_mail.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            return 'EzBizi has no Microsoft 365 connection set up on this installation yet, '
                .'so no Outlook mailbox can be opened and signing in would not complete. '
                .'Nothing is wrong with this address — the missing piece is at EzBizi\'s end.';
        }

        return null;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            return $notReady;
        }

        if (! $mailbox->hasCredential()) {
            return 'Nobody has signed in to Microsoft for this address yet, so EzBizi has no '
                .'permission to read it.';
        }

        return null;
    }

    /**
     * Graph's well-known folder names, keyed by the label this module uses.
     *
     * ── A FIXED MAP, and that is the security property ───────────
     *
     * The label arrives from a query string. Interpolating it into a URL path
     * would let a caller address any folder — or any other Graph resource — by
     * writing one. A map means an unrecognised label produces a refusal rather
     * than a request, and there is no arrangement of characters that reaches
     * something not listed here.
     *
     * `STARRED` is deliberately absent. Graph has no starred folder; the
     * nearest thing is a `flag/flagStatus` filter over the inbox, which is a
     * different query rather than a different folder, and offering "Starred"
     * that quietly showed the inbox would be the lie this method exists to
     * stop.
     */
    private const FOLDERS = [
        'INBOX'   => 'inbox',
        'SENT'    => 'sentitems',
        'DRAFT'   => 'drafts',
        'DRAFTS'  => 'drafts',
        'TRASH'   => 'deleteditems',
        'SPAM'    => 'junkemail',
        'ARCHIVE' => 'archive',
    ];

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, $this->folderUrl($mailbox, $label), [
            '$select'   => self::LIST_SELECT,
            '$orderby'  => 'receivedDateTime desc',
            '$top'      => (string) max(1, min($limit, 100)),
            ...$this->cursorQuery($cursor),
        ]);
    }

    /**
     * The messages URL for one folder.
     *
     * ── Why an unknown label REFUSES rather than falling back ────
     *
     * Returning the inbox for a folder we cannot address is the failure this
     * module keeps finding in other people's code and its own: it succeeds, it
     * displays, and it is wrong. Somebody looking at "Sent" would be reading
     * received mail, and nothing anywhere would say so.
     *
     * This used to be a constant pointing at the inbox and the label was
     * ignored entirely, which is exactly that failure — reachable through the
     * API even while the client only offered folders on Gmail.
     *
     * @throws CannotReadMailbox
     */
    private function folderUrl(Mailbox $mailbox, string $label): string
    {
        $folder = self::FOLDERS[strtoupper($label)] ?? null;

        if ($folder === null) {
            throw CannotReadMailbox::providerRefused(
                $mailbox,
                sprintf(
                    'An Outlook inbox has no folder called "%s", so there is nothing to show. '
                    .'Outlook offers Inbox, Sent, Drafts, Trash, Spam and Archive.',
                    $label,
                ),
            );
        }

        return self::GRAPH.'/me/mailFolders/'.$folder.'/messages';
    }

    /**
     * Search WITHIN the folder somebody is standing in.
     *
     * ── It used to search the whole mailbox ──────────────────────
     *
     * `/me/messages` is every message in the account. So searching while
     * standing in Sent returned hits from the Inbox as well, under a heading
     * that said Sent — a smaller version of the same lie as the listing bug,
     * and one that would have been read as "search is a bit odd" rather than
     * as a fault.
     *
     * Scoped through the same map the listing uses, so an unknown folder is
     * refused here too rather than quietly searching everything.
     */
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, $this->folderUrl($mailbox, $label), [
            '$search' => '"'.$query.'"',
            '$select' => self::LIST_SELECT,
            '$top'    => (string) max(1, min($limit, 100)),
            ...$this->cursorQuery($cursor),
        ], ['ConsistencyLevel' => 'eventual']);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    private function page(
        Mailbox $mailbox,
        string $url,
        array $query,
        array $headers = [],
    ): MessagePage {
        $listing = $this->get($mailbox, $url, $query, $headers);

        $messages = [];

        foreach ((array) ($listing['value'] ?? []) as $row) {
            if (is_array($row)) {
                $messages[] = $this->mapper->summary($row);
            }
        }

        return new MessagePage(
            messages: $messages,
            nextCursor: $this->cursorFromLink($listing['@odata.nextLink'] ?? null),
        );
    }

    public function open(Mailbox $mailbox, string $messageId, ?string $threadId = null): MessageDetail
    {
        return $this->mapper->detail($this->get(
            $mailbox,
            self::GRAPH.'/me/messages/'.rawurlencode($messageId),
            [
                '$select' => self::LIST_SELECT.',body',
                '$expand' => 'attachments($select=name,contentType,size,isInline)',
            ],
        ));
    }

    public function currentMark(Mailbox $mailbox): string
    {
            $token = $this->walkDelta($mailbox);

        if ($token === null || $token === '') {
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the mail service did not say where the mailbox is up to',
            );
        }

        return $token;
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        $ids = [];
        $pageToken = $mark;
        $fresh = null;

        for ($page = 0; $page < self::MAX_DELTA_PAGES; $page++) {
            $response = $this->sendRaw($mailbox, fn (string $token) => Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(self::INBOX.'/delta', [
                    '$select'     => 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,hasAttachments,isRead',
                    '$deltatoken' => $pageToken,
                    '$top'        => (string) max(1, min($limit, 100)),
                ]));

            // 410: Graph's delta token has expired. Same polarity as Gmail 404 history.
            if ($response->status() === 410) {
                return new Arrivals([], $this->currentMark($mailbox), markWasStale: true);
            }

            $body = $this->body($mailbox, $response);

            foreach ((array) ($body['value'] ?? []) as $row) {
                if (! is_array($row) || isset($row['@removed'])) {
                    continue;
                }

                $id = (string) ($row['id'] ?? '');

                if ($id !== '') {
                    $ids[$id] = $row;
                }
            }

            $next = $this->cursorFromLink($body['@odata.nextLink'] ?? null);

            if ($next !== null) {
                $pageToken = $next;
                $fresh = $this->deltaTokenFromLink($body['@odata.deltaLink'] ?? null) ?? $fresh;

                continue;
            }

            $fresh = $this->deltaTokenFromLink($body['@odata.deltaLink'] ?? null) ?? $mark;

            return new Arrivals($this->summariesFor($ids), $fresh);
        }

        return new Arrivals([], $this->currentMark($mailbox), markWasStale: true);
    }

    /**
     * Walk Graph's inbox delta until a deltaLink, announcing nothing.
     */
    private function walkDelta(Mailbox $mailbox): ?string
    {
        $query = ['$select' => 'id', '$top' => '100'];
        $url = self::INBOX.'/delta';

        for ($page = 0; $page < self::MAX_DELTA_PAGES; $page++) {
            $body = $this->get($mailbox, $url, $query);

            $delta = $this->deltaTokenFromLink($body['@odata.deltaLink'] ?? null);

            if ($delta !== null) {
                return $delta;
            }

            $next = (string) ($body['@odata.nextLink'] ?? '');

            if ($next === '') {
                return $delta;
            }

            $query = $this->queryFromLink($next) ?? $query;
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return MessageSummary[]
     */
    private function summariesFor(array $rows): array
    {
        $summaries = [];

        foreach ($rows as $row) {
            $summaries[] = $this->mapper->summary($row);
        }

        return $summaries;
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->post(
            $mailbox,
            self::GRAPH.'/me/messages/'.rawurlencode($messageId).'/reply',
            ['comment' => $body],
        );
    }

    /**
     * A new message through Graph's own sender.
     *
     * Graph builds the MIME from these parts, which is why nothing here goes
     * near {@see ReplyComposer}: there is no header string to inject into. The
     * recipients travel as a structured list rather than as a joined header
     * line, so the guard that matters on the IMAP path has no equivalent hole
     * to guard here.
     *
     * `saveToSentItems` on purpose. A shared inbox where sent mail vanishes
     * leaves the next colleague unable to see that the customer was already
     * answered.
     */
    /**
     * Graph's well-known folders, which every Outlook mailbox has.
     *
     * Taken from the same map the reader addresses, so the offer and what can
     * be opened cannot drift apart. DRAFTS is filtered out because it is an
     * alias for DRAFT rather than a folder of its own.
     */
    public function availableFolders(Mailbox $mailbox): array
    {
        return array_values(array_filter(
            array_keys(self::FOLDERS),
            fn (string $label) => $label !== 'DRAFTS',
        ));
    }

    /**
     * No — and the reason is a scope decision, not a Graph limitation.
     *
     * Graph marks a message read with a PATCH on `isRead`, which needs
     * `Mail.ReadWrite`. We hold `Mail.Read` and `Mail.Send`, and
     * {@see MicrosoftOAuth} says plainly that ReadWrite is NOT asked for —
     * it also permits modifying and deleting mail, which is a much larger
     * thing to hold than "mark this one read".
     *
     * So the switch is not offered on Outlook rather than offered and broken.
     * Turning it on would mean fresh consent from every connected mailbox.
     */
    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        return false;
    }

    public function markReadAtSource(Mailbox $mailbox, string $messageId): void
    {
        throw new CannotChangeMail(
            'EzBizi cannot mark mail as read in Outlook. Doing so needs a wider permission '
            .'than this inbox was connected with - one that also allows changing and '
            .'deleting mail - so it is not asked for. The message is still marked as read '
            .'in EzBizi.',
        );
    }

    public function markUnreadAtSource(Mailbox $mailbox, string $messageId): void
    {
        throw new CannotChangeMail(
            'EzBizi cannot mark mail as unread in Outlook, for the same reason it cannot mark '
            .'it read: that needs a wider permission than this inbox was connected with. The '
            .'message is still marked as unread in EzBizi.',
        );
    }

    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void
    {
        $message = [
            'subject'      => $subject,
            'body'         => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => array_values(array_map(
                fn (string $address) => ['emailAddress' => ['address' => $address]],
                $to,
            )),
        ];

        if ($attachments !== []) {
            /*
             * Graph assembles the MIME itself, so files go as structured
             * objects rather than through {@see ReplyComposer::composeNew}.
             * That is the third different shape for the same three files —
             * raw MIME for IMAP, raw MIME base64url for Gmail, this for Graph
             * — and it is why the controller hands every reader the same
             * name/mime/bytes triple and lets each one do its own packing.
             *
             * `sendMail` carries attachments inline up to about 3MB of
             * request. Above that Graph wants an upload session against a
             * draft, which is a different flow entirely; the controller's size
             * limit keeps us the safe side of it rather than discovering the
             * boundary from a 413 in front of a customer.
             */
            $message['attachments'] = array_values(array_map(fn (array $file) => [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $file['name'],
                'contentType'  => $file['mime'],
                'contentBytes' => base64_encode($file['bytes']),
            ], $attachments));
        }

        $this->post($mailbox, self::GRAPH.'/me/sendMail', [
            'message'         => $message,
            'saveToSentItems' => true,
        ]);
    }

    public function trash(Mailbox $mailbox, string $messageId): void
    {
        throw new CannotChangeMail(
            'Deleting mail from an Outlook inbox is not available yet. Nothing has been deleted.',
        );
    }

    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        throw new PictureUnavailable('That file could not be loaded.');
    }

    /**
     * Graph's unread total for the well-known inbox folder — no message list.
     */
    public function unreadCount(Mailbox $mailbox): int
    {
        $folder = $this->get($mailbox, self::GRAPH.'/me/mailFolders/inbox', [
            '$select' => 'unreadItemCount',
        ]);

        if (! array_key_exists('unreadItemCount', $folder) || ! is_numeric($folder['unreadItemCount'])) {
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the mail service did not say how many unread messages there are',
            );
        }

        return (int) $folder['unreadItemCount'];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function get(Mailbox $mailbox, string $url, array $query, array $headers = []): array
    {
        return $this->json($mailbox, fn (string $token) => Http::withToken($token)
            ->withHeaders($headers)
            ->timeout(self::TIMEOUT)
            ->get($url, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(Mailbox $mailbox, string $url, array $payload): array
    {
        return $this->json($mailbox, fn (string $token) => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->post($url, $payload));
    }

    /**
     * Whether this mailbox could send, without sending: a token, and the
     * permissions written into it (`scp`). Null when it can.
     */
    public function sendProblem(Mailbox $mailbox): ?string
    {
        try {
            $token = $this->accessToken($mailbox);
        } catch (CannotReadMailbox $e) {
            return $e->getMessage();
        }

        // Only read, never trusted for anything else: Microsoft signed it and
        // Graph checks it on every call.
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);
        $granted = preg_split('/\s+/', (string) ($claims['scp'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(MicrosoftOAuth::SCOPE_SEND, $granted, true)
            ? null
            : 'Connected without permission to send. Reconnect it and allow sending.';
    }

    private function accessToken(Mailbox $mailbox): string
    {
        $this->assertUsable($mailbox);

        if (($cached = $this->cachedAccessToken($mailbox)) !== null) {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id'     => config('services.microsoft_mail.client_id'),
                    'client_secret' => config('services.microsoft_mail.client_secret'),
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
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status().' with no sign-in in it',
            );
        }

        $token = (string) $response->json('access_token');

        $this->cacheAccessToken($mailbox, $token, $response->json('expires_in'));

        return $token;
    }

    private function cacheKey(Mailbox $mailbox): string
    {
        return 'email.microsoft.access-token.'.$mailbox->getKey();
    }

    private function cachedAccessToken(Mailbox $mailbox): ?string
    {
        $stored = Cache::get($this->cacheKey($mailbox));

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }

        return $token === '' ? null : $token;
    }

    private function cacheAccessToken(Mailbox $mailbox, string $token, mixed $expiresIn): void
    {
        $seconds = is_numeric($expiresIn) ? (int) floor(((int) $expiresIn) * 0.75) : 0;

        if ($seconds < 30) {
            return;
        }

        Cache::put($this->cacheKey($mailbox), Crypt::encryptString($token), $seconds);
    }

    private function forgetAccessToken(Mailbox $mailbox): void
    {
        Cache::forget($this->cacheKey($mailbox));
    }

    private function tokenFailure(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): CannotReadMailbox
    {
        $error  = strtolower((string) ($response->json('error') ?? ''));
        $detail = (string) ($response->json('error_description') ?? $response->json('error') ?? '');

        if ($error === 'invalid_client' || $error === 'unauthorized_client') {
            return CannotReadMailbox::platformRejected($mailbox, $detail);
        }

        if ($error === 'invalid_grant') {
            return CannotReadMailbox::credentialRejected($mailbox, $detail);
        }

        return match (true) {
            $response->status() === 429 => CannotReadMailbox::tooManyRequests($mailbox, $detail),
            $response->serverError()    => CannotReadMailbox::providerFaulty(
                $mailbox,
                'the sign-in service answered '.$response->status(),
            ),
            $response->status() === 401 => CannotReadMailbox::platformRejected($mailbox, $detail),
            default => CannotReadMailbox::providerRefused($mailbox, $detail),
        };
    }

    /**
     * @param  callable(string): \Illuminate\Http\Client\Response  $call
     * @return array<string, mixed>
     */
    private function json(Mailbox $mailbox, callable $call): array
    {
        return $this->body($mailbox, $this->sendRaw($mailbox, $call));
    }

    /**
     * @param  callable(string): \Illuminate\Http\Client\Response  $call
     */
    private function sendRaw(Mailbox $mailbox, callable $call): \Illuminate\Http\Client\Response
    {
        $response = $this->attempt($mailbox, $call, $this->accessToken($mailbox));

        if ($response->status() === 401) {
            $this->forgetAccessToken($mailbox);

            $response = $this->attempt($mailbox, $call, $this->accessToken($mailbox));
        }

        return $response;
    }

    /**
     * @param  callable(string): \Illuminate\Http\Client\Response  $call
     */
    private function attempt(Mailbox $mailbox, callable $call, string $token): \Illuminate\Http\Client\Response
    {
        try {
            return $call($token);
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): array
    {
        if ($response->failed()) {
            throw $this->apiFailure($mailbox, $response);
        }

        return (array) $response->json();
    }

    private function apiFailure(Mailbox $mailbox, \Illuminate\Http\Client\Response $response): CannotReadMailbox
    {
        $detail = (string) ($response->json('error.message') ?? '');
        $code   = (string) ($response->json('error.code') ?? '');

        if ($response->status() === 403) {
            return in_array($code, ['ApplicationThrottled', 'TooManyRequests'], true)
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
     * @throws CannotReadMailbox
     */
    private function assertUsable(Mailbox $mailbox): void
    {
        if (($why = $this->notReadyBecause()) !== null) {
            throw CannotReadMailbox::notBuiltHere(MailboxProvider::MicrosoftOauth, $why);
        }

        if (! $mailbox->hasCredential()) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::MicrosoftOauth);
        }
    }

    /**
     * Opaque cursor from a Graph nextLink. The URL itself is never followed —
     * a cursor the client echoes back must not become an SSRF.
     */
    private function cursorFromLink(mixed $nextLink): ?string
    {
        if (! is_string($nextLink) || $nextLink === '') {
            return null;
        }

        $query = $this->queryFromLink($nextLink);

        if ($query === null) {
            return null;
        }

        foreach (['$skiptoken', 'skiptoken', '$skip', 'skip', '$deltatoken', 'deltatoken'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                return $key.':'.$query[$key];
            }
        }

        return null;
    }

    private function deltaTokenFromLink(mixed $deltaLink): ?string
    {
        if (! is_string($deltaLink) || $deltaLink === '') {
            return null;
        }

        $query = $this->queryFromLink($deltaLink);

        foreach (['$deltatoken', 'deltatoken'] as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                return $query[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function queryFromLink(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['host'] ?? '') !== 'graph.microsoft.com') {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return array_map(strval(...), $query);
    }

    /**
     * @return array<string, string>
     */
    private function cursorQuery(?string $cursor): array
    {
        if ($cursor === null || $cursor === '' || ! str_contains($cursor, ':')) {
            return [];
        }

        [$key, $value] = explode(':', $cursor, 2);

        if ($value === '' || ! in_array($key, ['$skiptoken', 'skiptoken', '$skip', 'skip', '$deltatoken', 'deltatoken'], true)) {
            return [];
        }

        $graphKey = str_starts_with($key, '$') ? $key : '$'.$key;

        return [$graphKey => $value];
    }
}
