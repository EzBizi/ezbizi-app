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

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, self::INBOX, [
            '$select'   => self::LIST_SELECT,
            '$orderby'  => 'receivedDateTime desc',
            '$top'      => (string) max(1, min($limit, 100)),
            ...$this->cursorQuery($cursor),
        ]);
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, self::GRAPH.'/me/messages', [
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

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
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
