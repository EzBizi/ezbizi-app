<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Gmail, over OAuth — the provider a customer should be steered to.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT IS BUILT HERE, AND WHAT STILL CANNOT BE.
 *
 *  BUILT: the whole shape. The preflight (is there an OAuth client
 *  on this installation, is there a refresh token on this mailbox),
 *  the refusals, the token exchange, the three REST calls, the
 *  mapping — {@see GmailMessageMapper}, which is pure and is tested —
 *  and the reply path.
 *
 *  ALSO BUILT NOW, AND IT WAS THE MISSING HALF: the browser flow that
 *  turns a person's consent into the refresh token this class spends.
 *  {@see GoogleOAuth}, {@see GoogleOAuthSignIns} and
 *  App\Http\Controllers\Api\GoogleMailAuthController are the authorize
 *  step, the single-use state that binds it to one person, one
 *  organization and one mailbox, and the callback that checks the
 *  account signed in with is actually this address before storing
 *  anything. This paragraph used to say that flow was absent, and the
 *  line about it not being code was the reason nobody expected it —
 *  it is code, and only the account is not.
 *
 *  NOT BUILT, AND NOT FAKEABLE: a Google Cloud project with the Gmail
 *  API enabled, an OAuth client id and secret, a consent screen, and
 *  the redirect URI registered on that client. None of that is code;
 *  it is an account somebody has to create in a console, and — for an
 *  installation serving organizations outside one Google Workspace —
 *  a verification Google has to grant.
 *
 *  So on an installation where `config('services.google_mail')` has
 *  no client id and secret, every method below refuses with
 *  {@see CannotReadMailbox::notBuiltHere} — which says it is at our
 *  end, in a sentence, rather than sending an administrator to check
 *  a password that is not the problem. {@see notReadyBecause} is the
 *  same fact for the screen that would otherwise offer the button,
 *  and the moment those two values exist it answers null and the flow
 *  works without another line changing.
 *
 *  IT DOES NOT RETURN AN EMPTY INBOX. That is the whole point and it
 *  is stated at length on CannotReadMailbox: "nothing to show" and
 *  "we cannot look" are indistinguishable on screen, and only one of
 *  them is a reason to ring somebody.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The scopes this spends, and where they are argued ────────────
 *
 * `gmail.readonly` for the three read calls, `gmail.send` for the reply, and
 * nothing else — named and justified one by one on {@see GoogleOAuth}, which is
 * where they are actually requested. Two points belong here as well, because
 * this is the class whose behaviour they decide:
 *
 * NOT `mail.google.com`, the full-account scope: it includes permanently
 * deleting mail and administering the account, and nothing below deletes
 * anything.
 *
 * `gmail.modify` IS requested — see GoogleOAuth:204, which lists it beside the
 * other two. This paragraph used to say it was not, and used to say that this
 * class "marks NOTHING at Google". Both were true when written and neither is
 * now:
 *
 *   - {@see trash} moves a thread to Google's bin, which needs modify.
 *   - {@see markReadAtSource} removes the UNREAD label, which needs it too.
 *
 * The claim decayed TWICE before anybody noticed, which is the argument for
 * writing what a scope is spent ON rather than which scopes are refused: the
 * first list goes stale when behaviour is added, the second only when the
 * consent screen changes.
 *
 * What is still true, and is the part that matters: read state is EzBizi's own
 * `message_seen` table, per person, and opening a shared inbox here does NOT
 * mark a message read in Gmail — UNLESS that mailbox has been switched to
 * `mark_read_at_source`, which is off by default and off on every mailbox that
 * has not chosen it. The default is what keeps "seen by Jenny" meaningful on an
 * inbox several people answer; the switch exists for the inbox one person
 * reads, where the two ideas of "read" are the same idea.
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
final class GmailMailboxReader implements LabelsThreads, MailboxReader, RepliesWithCopies
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** Seconds. A mail list is somebody waiting at a screen. */
    private const TIMEOUT = 10;

    /**
     * How many pages of history one poll will follow before giving up.
     *
     * Ten pages of five hundred records is five thousand things happening to one
     * mailbox between two polls minutes apart. Nothing a business does produces
     * that; a migration, a mass import or a rule gone wrong does. Reaching this
     * is treated as an unreadable position rather than as five thousand
     * arrivals — see {@see arrivalsSince}.
     */
    private const MAX_HISTORY_PAGES = 10;

    /** Google's own ceiling for `maxResults` on the history endpoint. */
    private const MAX_HISTORY_PAGE_SIZE = 500;

    public function __construct(
        private readonly GmailMessageMapper $mapper,
        private readonly ReplyComposer $composer,
        private readonly GoogleWorkspace $workspace,
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
        if ($mailbox->providerEnum() === MailboxProvider::GmailWorkspace) {
            return $this->workspace->unavailableBecause($mailbox);
        }

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
     * `config/services.php` now carries the `google_mail` block, and the block
     * existing changes NOTHING about the answer here — which is the point of
     * reading it with `is_string()`. Both values come from environment
     * variables, `env()` answers null for one nobody has set, and null is not a
     * string. So a box with no Google registration still says so, in a sentence,
     * everywhere it matters.
     *
     * What the block bought is that registering becomes two environment
     * variables rather than an edit to a config file — and the day they are set,
     * this returns true and the reader, the catalogue and the sign-in flow all
     * start working without another line changing.
     *
     * ── The redirect URI is deliberately NOT checked here ────────
     *
     * It has a working default (this application's own callback path on
     * APP_URL), so there is nothing for its absence to mean — and reading it
     * here would make it a condition of READING MAIL, which does not use it. A
     * refresh-token exchange has no redirect URI in it. Where it does matter is
     * starting a sign-in, and {@see GoogleOAuth::redirectUri} checks it there,
     * against this application's own callback path, and refuses in a sentence.
     */
    private function platformIsRegistered(): bool
    {
        return is_string(config('services.google_mail.client_id'))
            && is_string(config('services.google_mail.client_secret'));
    }

    // ── reading ──────────────────────────────────────────────────

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, $this->labelFilter($mailbox, $label), $cursor, $limit);
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
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        return $this->page($mailbox, ['q' => $query, ...$this->labelFilter($mailbox, $label)], $cursor, $limit);
    }

    /**
     * Gmail's own folder ids, and a refusal for anything else.
     *
     * ── This used to answer INBOX, and that was the bug ──────────
     *
     * The old comment read "anything else is Inbox, not a guessed label", and
     * the instinct behind it was right — do not guess at a label Gmail has
     * never heard of. The chosen safe default was not. Returning the Inbox
     * means `?label=ARCHIVE` answers 200 with received mail while the screen
     * says Archive, which is the failure this module found in Graph and IMAP
     * and fixed in both, sitting untouched in the provider that was said to
     * honour the label.
     *
     * It was never reachable from the screen, because the client only offers
     * the six below. That is the same "latent, not harmless" as the others: a
     * public endpoint, one client change from being a real wrong answer.
     *
     * @throws CannotReadMailbox
     */
    /** `ALL` is every conversation outside Spam and Trash: no label at all. */
    private function labelFilter(Mailbox $mailbox, string $label): array
    {
        return strtoupper($label) === 'ALL' ? [] : ['labelIds' => $this->folder($mailbox, $label)];
    }

    private function folder(Mailbox $mailbox, string $label): string
    {
        $name = strtoupper($label);

        if (in_array($name, ['INBOX', 'STARRED', 'SENT', 'DRAFT', 'TRASH', 'SPAM'], true)) {
            return $name;
        }

        throw CannotReadMailbox::providerRefused(
            $mailbox,
            sprintf(
                'A Gmail inbox has no folder called "%s", so there is nothing to show. '
                .'Gmail offers Inbox, Starred, Sent, Drafts, Trash and Spam.',
                $label,
            ),
        );
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function page(Mailbox $mailbox, array $parameters, ?string $cursor, int $limit): MessagePage
    {
        /*
         * Threads, not messages. Gmail's inbox is one row per conversation;
         * `users.messages.list` is every message, so five WhatsApp templates
         * in one thread become five rows. `users.threads.list` is the call
         * the Gmail UI itself makes.
         */
        $listing = $this->get($mailbox, self::API.'/threads', [
            ...$parameters,
            // Clamped here rather than trusted from the caller: a controller
            // validates it too, and a page size of 5000 against a third party's
            // API is a way of using somebody else's rate limit up.
            'maxResults' => max(1, min($limit, 100)),
            ...($cursor === null ? [] : ['pageToken' => $cursor]),
        ]);

        $ids = [];

        foreach ((array) ($listing['threads'] ?? []) as $stub) {
            $id = is_array($stub) ? (string) ($stub['id'] ?? '') : '';

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        /*
         * `format=metadata`, and the headers named explicitly.
         *
         * A list must not download bodies: twenty-five conversations with
         * attachments is megabytes over the wire to render twenty-five
         * lines of text. Naming the headers also means Google returns
         * roughly a kilobyte per message rather than every Received: hop.
         *
         * Fetched together. One round trip per conversation, done one
         * after another, is why opening Email felt slow — twenty-five
         * waits for the same headers Gmail's own inbox loads as one
         * screen. The access token is still spent once; only the
         * conversation gets overlap.
         */
        $fetched = $this->threadMetadata($mailbox, $ids);

        $messages = [];

        foreach ($ids as $id) {
            if (isset($fetched[$id])) {
                $messages[] = $this->mapper->threadSummary($fetched[$id]);
            }
        }

        return new MessagePage(
            messages: $messages,
            nextCursor: isset($listing['nextPageToken']) ? (string) $listing['nextPageToken'] : null,
            approximateTotal: isset($listing['resultSizeEstimate'])
                ? (int) $listing['resultSizeEstimate']
                : null,
        );
    }

        /**
     * ONE fetch when the list said which conversation this is.
     *
     * This always fetched the message in full, read its thread id off it, and
     * then fetched the whole thread in full -- which contains that same
     * message again. Measured on production 2026-10-06: every open was those
     * two sequential full fetches, ~165 ms each. The list row already carries
     * the thread id, so the client sends it back and the thread alone does.
     *
     * The hint is checked, not trusted: if the message is not in the thread
     * it names (stale list, moved message, anybody's hand-typed URL), this
     * opens it the old way. The token is this mailbox's, so a thread id can
     * only ever name a conversation in an inbox the caller may already read.
     */
    public function open(Mailbox $mailbox, string $messageId, ?string $threadId = null): MessageDetail
    {
        if (is_string($threadId) && $threadId !== '') {
            $opened = $this->fromThread($mailbox, $messageId, $this->fullThread($mailbox, $threadId));

            if ($opened !== null) {
                return $opened;
            }
        }

        $detail = $this->mapper->detail(
            $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), ['format' => 'full'])
        );

        $threadId = $detail->summary->threadId;

        if ($threadId === null || $threadId === '') {
            return $detail;
        }

        return $this->fromThread($mailbox, $messageId, $this->fullThread($mailbox, $threadId)) ?? $detail;
    }

    /** @return array<string, mixed> */
    private function fullThread(Mailbox $mailbox, string $threadId): array
    {
        return $this->get($mailbox, self::API.'/threads/'.rawurlencode($threadId), ['format' => 'full']);
    }

    /**
     * The message and its conversation, or null when it is not in this thread.
     *
     * @param  array<string, mixed>  $thread
     */
    private function fromThread(Mailbox $mailbox, string $messageId, array $thread): ?MessageDetail
    {
        $parts = [];
        $detail = null;

        foreach ((array) ($thread['messages'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $part = $this->mapper->detail($row);
            $parts[] = $part;

            if ((string) ($row['id'] ?? '') === $messageId) {
                $detail = $part;
            }
        }

        if ($detail === null) {
            return null;
        }

        return count($parts) <= 1 ? $detail : $detail->withThread($parts);
    }

    /**
     * A CID picture attached to this message, as bytes.
     *
     * Not on {@see MailboxReader}: a missing logo is not a dead inbox, and
     * putting it on the interface would force every provider to pretend. IMAP
     * and Microsoft 404 until they implement this; Gmail already has the part.
     */
    public function inlineImage(Mailbox $mailbox, string $messageId, string $contentId): EmailPicture
    {
        $payload = $this->get(
            $mailbox,
            self::API.'/messages/'.rawurlencode($messageId),
            ['format' => 'full'],
        );

        $part = $this->mapper->partByContentId($payload['payload'] ?? [], $contentId);

        if ($part === null) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $mime = strtolower((string) ($part['mimeType'] ?? 'application/octet-stream'));
        $data = $part['body']['data'] ?? null;

        if (is_string($data) && $data !== '') {
            return new EmailPicture($this->decodeAttachment($data), $mime);
        }

        $attachmentId = $part['body']['attachmentId'] ?? null;

        if (! is_string($attachmentId) || $attachmentId === '') {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        $attachment = $this->get(
            $mailbox,
            self::API.'/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId),
            [],
        );

        $bytes = $attachment['data'] ?? null;

        if (! is_string($bytes) || $bytes === '') {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        return new EmailPicture($this->decodeAttachment($bytes), $mime);
    }

    public const MAX_ATTACHMENT_BYTES = 15_728_640;

    /**
     * An attached file, fetched live and not stored.
     *
     * Same Gmail attachment endpoint as CID pictures. The filename lives on
     * the sealed token the client already holds, so this returns bytes + mime.
     */
    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        $attachment = $this->get(
            $mailbox,
            self::API.'/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId),
            [],
        );

        $bytes = $attachment['data'] ?? null;

        if (! is_string($bytes) || $bytes === '') {
            throw new PictureUnavailable('That file could not be loaded.');
        }

        $decoded = $this->decodeAttachment($bytes);

        if (strlen($decoded) > self::MAX_ATTACHMENT_BYTES) {
            throw new PictureUnavailable('That file is too large to open here.');
        }

        return new EmailPicture($decoded, 'application/octet-stream');
    }

    private function decodeAttachment(string $data): string
    {
        $translated = strtr($data, '-_', '+/');
        $padding = strlen($translated) % 4;

        if ($padding > 0) {
            $translated .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($translated, strict: false);

        if ($decoded === false || $decoded === '') {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        return $decoded;
    }

    // ── watching ─────────────────────────────────────────────────

    /**
     * The account's own history id — one call, and it reads no mail at all.
     *
     * `users.getProfile` answers with the address, the message count and the
     * history id, which is Gmail's sequence number for the mailbox as a whole.
     * That is precisely what a first poll needs: a position, taken without
     * listing anything, so an inbox with four thousand messages in it can be
     * marked and then stay silent until something actually arrives.
     *
     * It is also the cheapest call in the API. Listing one message to read its
     * history id would cost a list and a get, and would still be the position of
     * a MESSAGE rather than of the mailbox — so a label change with no new mail
     * would leave the mark behind the truth.
     */
    public function currentMark(Mailbox $mailbox): string
    {
        $profile = $this->get($mailbox, self::API.'/profile', []);

        $historyId = (string) ($profile['historyId'] ?? '');

        if ($historyId === '') {
            /*
             * A 2xx with no position in it. Not a refusal and not the tenant's
             * fault — the service answered and answered uselessly — so it is
             * reported as the provider's fault rather than written onto the
             * mailbox as a broken sign-in.
             */
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the mail service did not say where the mailbox is up to',
            );
        }

        return $historyId;
    }

    /**
     * Gmail's own unread total for the mailbox — `users.getProfile`, no list.
     *
     * `messagesUnread` is the account-level unread count. Asking for it does
     * not download a message; the same call already supplies {@see currentMark}.
     */
    public function unreadCount(Mailbox $mailbox): int
    {
        // The INBOX label, not /profile: a profile has no unread count, so every
        // Gmail inbox failed here (Dashboard, 11-02).
        $profile = $this->get($mailbox, self::API.'/labels/INBOX', []);

        if (! array_key_exists('messagesUnread', $profile) || ! is_numeric($profile['messagesUnread'])) {
            throw CannotReadMailbox::providerFaulty(
                $mailbox,
                'the mail service did not say how many unread messages there are',
            );
        }

        return (int) $profile['messagesUnread'];
    }

    /**
     * Everything added to the INBOX since a history id.
     *
     * ═══════════════════════════════════════════════════════════════
     *  GMAIL'S POSITION CAN ONLY BE TAKEN AT A PAGE BOUNDARY, AND
     *  THAT DECIDES THE SHAPE OF THIS METHOD.
     *
     *  The response carries a top-level `historyId` meaning "where
     *  the mailbox is now" — not "where the records I just gave you
     *  end". So stopping half way through a paged history and storing
     *  that value would skip every message on the pages not read: the
     *  worst possible failure here, because it is silent and
     *  permanent.
     *
     *  There is no safe half-way mark to store instead. Individual
     *  history records have ids, but Google does not promise that
     *  re-supplying one excludes the record it names, and a mark that
     *  MIGHT re-deliver breaks the one rule this whole feature has.
     *
     *  So the pages are followed to the end and only then is the mark
     *  advanced. `$limit` becomes the page size rather than a cap on
     *  the batch, and {@see MailboxReader::arrivalsSince} says so.
     *  The thundering herd that a cap would otherwise prevent is
     *  already handled a layer down: these become outbox ROWS, and
     *  `ezbizi:dispatch-automation-events` is what meters them onto
     *  the queue.
     * ═══════════════════════════════════════════════════════════════
     *
     * ── What happens when the history is longer than the cap ─────
     *
     * The same thing as an expired history id, and for the same reason: at that
     * point EzBizi cannot tell which of those messages are genuinely new
     * arrivals rather than a mailbox being reorganised, so it re-marks and
     * announces NOTHING. A gap in the announcements is a disappointment; a
     * thousand wrong events is an automation nobody trusts again.
     */
    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        $ids       = [];
        $pageToken = null;
        $historyId = null;

        for ($page = 0; $page < self::MAX_HISTORY_PAGES; $page++) {
            $response = $this->sendRaw($mailbox, fn (string $token) => Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(self::API.'/history', [
                    'startHistoryId' => $mark,
                    // Only mail ARRIVING. Without this the history also carries
                    // labels added and removed and messages deleted, and a rule
                    // saying "an email arrived" would fire when somebody
                    // archived one.
                    'historyTypes'   => 'messageAdded',
                    // And only in the inbox: a reply this application sends
                    // through `messages.send` is added to the mailbox too, and
                    // announcing our own outgoing mail as an arrival would make
                    // every auto-reply rule a loop.
                    'labelId'        => 'INBOX',
                    'maxResults'     => max(1, min($limit, self::MAX_HISTORY_PAGE_SIZE)),
                    ...($pageToken === null ? [] : ['pageToken' => $pageToken]),
                ]));

            /*
             * 404 means the history id is older than Google keeps. It is not a
             * fault — mailboxes go quiet for a week — so it is not thrown, and
             * it must NOT be recovered from by listing the inbox. See
             * {@see Arrivals}.
             */
            if ($response->status() === 404) {
                return new Arrivals([], $this->currentMark($mailbox), markWasStale: true);
            }

            $body = $this->body($mailbox, $response);

            foreach ((array) ($body['history'] ?? []) as $record) {
                foreach ((array) (is_array($record) ? ($record['messagesAdded'] ?? []) : []) as $added) {
                    $id = $this->arrival(is_array($added) ? $added : []);

                    if ($id !== null) {
                        // Keyed, not appended. One message can appear in more
                        // than one history record, and Google's own guidance is
                        // that records may repeat across a resumed listing —
                        // both of which would otherwise be a duplicate event.
                        $ids[$id] = true;
                    }
                }
            }

            $historyId = (string) ($body['historyId'] ?? $mark);
            $pageToken = isset($body['nextPageToken']) ? (string) $body['nextPageToken'] : null;

            if ($pageToken === null) {
                return new Arrivals($this->summariesFor($mailbox, array_keys($ids)), $historyId);
            }
        }

        // The cap, reached. See the note above: re-mark, announce nothing.
        return new Arrivals([], $this->currentMark($mailbox), markWasStale: true);
    }

    /**
     * The id of one `messagesAdded` entry, or null when it is not an arrival.
     *
     * The `labelId=INBOX` parameter filters the RECORDS; this checks the message
     * itself, because a record can carry more than one message and because a
     * draft being saved is an addition to the mailbox that nobody would call an
     * email arriving. Absent labels are accepted — the parameter already did the
     * filtering, and refusing what we cannot see would silently drop real mail.
     *
     * @param  array<string, mixed>  $added
     */
    private function arrival(array $added): ?string
    {
        $message = is_array($added['message'] ?? null) ? $added['message'] : [];
        $id      = (string) ($message['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $labels = array_map('strval', (array) ($message['labelIds'] ?? []));

        if ($labels === []) {
            return $id;
        }

        if (! in_array('INBOX', $labels, true)) {
            return null;
        }

        return in_array('DRAFT', $labels, true) || in_array('SENT', $labels, true) ? null : $id;
    }

    /**
     * Headers for each new message, oldest first.
     *
     * ── One call per new message, and why that is the right price ──
     *
     * The history gives ids and nothing else, so the sender and the subject have
     * to be asked for. `format=metadata` with the four headers named is the same
     * request the list screen makes, and it is roughly a kilobyte rather than the
     * whole message — no body is fetched, which matters here beyond the bandwidth:
     * this module holds no message content, and a watcher that downloaded bodies
     * to throw them away would be one refactor from caching them.
     *
     * Gmail ids sort lexicographically in the order the messages arrived, because
     * they are fixed-width hex of an increasing internal id. That is what puts
     * the batch oldest-first without a second round trip to read the dates —
     * and {@see Arrivals} explains why oldest-first is the order that matters.
     *
     * @param  string[]  $ids
     * @return MessageSummary[]
     */
    private function summariesFor(Mailbox $mailbox, array $ids): array
    {
        sort($ids);

        $summaries = [];

        foreach ($ids as $id) {
            $summaries[] = $this->mapper->summary($this->get($mailbox, self::API."/messages/{$id}", [
                'format'          => 'metadata',
                // The last three are what `is_bulk` reads (GmailMessageMapper::isBulk).
                // Headers only -- still no body.
                'metadataHeaders' => [
                    'From', 'To', 'Subject', 'Date', 'Authentication-Results',
                    'List-Unsubscribe', 'Precedence', 'Auto-Submitted', 'Message-ID',
                ],
            ]));
        }

        return $summaries;
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
        $original = $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), [
            'format'          => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Message-ID', 'References'],
        ]);

        $summary = $this->mapper->summary($original);

        $this->post($mailbox, self::API.'/messages/send', [
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

    /** Gmail's are fixed, and Gmail is the same everywhere. */
    public function availableFolders(Mailbox $mailbox): array
    {
        return ['INBOX', 'STARRED', 'SENT', 'DRAFT', 'TRASH', 'SPAM'];
    }

    /**
     * Gmail can, because the modify scope is already held for Trash.
     *
     * Not "Gmail can" in the abstract — the whole reason this takes a Mailbox
     * is that the answer is about the CONNECTION. {@see unavailableBecause}
     * covers the installation having no OAuth client and the mailbox having no
     * refresh token, both of which make the switch a promise we cannot keep.
     */
    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        return $this->unavailableBecause($mailbox) === null;
    }

    /**
     * Remove the UNREAD label — which is what "read" IS in Gmail.
     *
     * No new scope: `gmail.modify` is already requested and already spent by
     * {@see trash}. That is the entire reason this arrived on Gmail first and
     * not on the other two — Graph would need `Mail.ReadWrite` and a fresh
     * consent from every connected mailbox, and IMAP would mean opening the
     * folder writable instead of with EXAMINE.
     *
     * Only the UNREAD label, and nothing else on the message.
     *
     * ── The THREAD, for the same reason trash() uses it ──────────
     *
     * Gmail's UNREAD is per message, but a conversation shows as unread if ANY
     * message in it is. EzBizi marks every part of an opened thread seen
     * locally — see the loop in MailboxMessagesController::show — so marking
     * only the one message would leave the conversation bold in Gmail and the
     * setting would look broken on exactly the threads people notice.
     *
     * It is also one call rather than one per part, and it is what Gmail's own
     * interface does when you open a conversation.
     */
    public function markReadAtSource(Mailbox $mailbox, string $messageId): void
    {
        try {
            $meta = $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), [
                'format'          => 'metadata',
                'metadataHeaders' => ['From'],
            ]);

            $threadId = (string) ($meta['threadId'] ?? $messageId);

            $this->postModify(
                $mailbox,
                self::API.'/threads/'.rawurlencode($threadId).'/modify',
                ['removeLabelIds' => ['UNREAD']],
            );
        } catch (CannotReadMailbox) {
            throw new CannotChangeMail(
                'Google would not mark that message as read. Nothing else has changed, and '
                .'it is still marked as read in EzBizi.',
            );
        }
    }

    /**
     * Unread is the MESSAGE, not the thread.
     *
     * Reading marks the whole conversation, for the reason above. Unread is
     * the opposite case: Gmail's own "Mark as unread" flags the message, and a
     * conversation shows unread if ANY message in it is -- so one message is
     * enough to make the thread bold again, without falsely claiming nobody
     * has read the earlier replies. One call, no metadata lookup.
     */
    public function markUnreadAtSource(Mailbox $mailbox, string $messageId): void
    {
        try {
            $this->postModify(
                $mailbox,
                self::API.'/messages/'.rawurlencode($messageId).'/modify',
                ['addLabelIds' => ['UNREAD']],
            );
        } catch (CannotReadMailbox) {
            throw new CannotChangeMail(
                'Google would not mark that message as unread. Nothing else has changed, and '
                .'it is still marked as unread in EzBizi.',
            );
        }
    }

    /**
     * A new message — the same endpoint as a reply, without the thread.
     *
     * No `threadId`, so Gmail files it as a new conversation rather than under
     * whatever was last opened. That omission is the whole difference.
     */
    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void
    {
        $this->post($mailbox, self::API.'/messages/send', [
            'raw' => $this->composer->composeNewForGmail($mailbox, $to, $subject, $body, $attachments),
        ]);
    }

    /**
     * Move the thread to Trash — Gmail's own bin, not a local hide.
     *
     * The whole conversation goes, because the list is threads. Binning one
     * message and leaving the other four WhatsApp templates in the inbox
     * would look like delete had failed.
     */
    public function trash(Mailbox $mailbox, string $messageId): void
    {
        try {
            $meta = $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), [
                'format'          => 'metadata',
                'metadataHeaders' => ['From'],
            ]);
            $threadId = (string) ($meta['threadId'] ?? $messageId);

            $this->postModify(
                $mailbox,
                self::API.'/threads/'.rawurlencode($threadId).'/trash',
                [],
            );
        } catch (CannotReadMailbox) {
            throw new CannotChangeMail(
                'Mail cannot be deleted from here until Google allows EzBizi to '
                .'move messages to Trash (gmail.modify). Add that scope in Google '
                .'Admin domain-wide delegation, or reconnect this inbox and allow '
                .'it on the consent screen. Nothing has been deleted.',
            );
        }
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
     * A refresh token spent for an access token — with nobody present.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE PART THAT MAKES A SHARED INBOX UNATTENDED.
     *
     *  The refresh token is the stored secret and never leaves this
     *  method. What it buys lives about an hour, and every read below
     *  needs one — so the whole of "the sign-in keeps working after
     *  the person who made it has gone home" is this method being able
     *  to run with no browser, no consent screen and no interaction.
     *  There is no path here that asks anybody for anything: it
     *  either mints a token or produces a fault that says what went
     *  wrong.
     *
     *  A refresh that comes back `invalid_grant` is the sign-in no
     *  longer being accepted, and it goes to the fault this module
     *  already has for exactly that — see {@see tokenFailure}, which
     *  argues why `invalid_grant` and `invalid_client` must not share
     *  a branch.
     * ═══════════════════════════════════════════════════════════════
     *
     * ── It is cached now, and this paragraph used to say it was not ─
     *
     * The previous note said nothing cached the access token deliberately, on
     * the grounds that a cached OAuth token is a copy of a credential in a
     * second store. That reasoning was sound and the conclusion was wrong once
     * a real sign-in existed: opening one page of an inbox makes a token request
     * plus one call per message, so twenty-five messages meant twenty-six round
     * trips to Google of which twenty-five were avoidable — and Google rate
     * limits token minting separately from the API, so the cost lands as a quota
     * failure that looks like a broken mailbox.
     *
     * What made the objection answerable is HOW it is cached:
     *
     *   ENCRYPTED. {@see Crypt} on the way in and out, so the value in the cache
     *   store is not a bearer token anybody who can read that store may spend.
     *   The refresh token gets the same treatment in the database, and an hour's
     *   access to somebody's mail is not a small enough prize to treat casually.
     *
     *   FOR LESS THAN ITS LIFE. Three quarters of what Google says it has, so a
     *   token is always replaced with a quarter of its life left — enough to
     *   cover a slow request and a clock several minutes out. If Google does not
     *   say how long it lives, NOTHING IS CACHED: a guessed lifetime is the one
     *   version of this that produces expired tokens.
     *
     *   NEVER AUTHORITATIVE. {@see send} treats a 401 as ordinary, throws the
     *   cached value away and mints a fresh one before deciding anything is
     *   wrong. So the worst a stale entry can cost is one extra round trip.
     */
    private function accessToken(Mailbox $mailbox, bool $modify = false): string
    {
        if ($mailbox->providerEnum() === MailboxProvider::GmailWorkspace) {
            return $this->workspace->gmailAccessToken($mailbox, $modify);
        }

        $this->assertUsable($mailbox);

        if (($cached = $this->cachedAccessToken($mailbox)) !== null) {
            return $cached;
        }

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

        $token = (string) $response->json('access_token');

        $this->cacheAccessToken($mailbox, $token, $response->json('expires_in'));

        return $token;
    }

    /**
     * Where an access token is parked between requests.
     *
     * Keyed on the MAILBOX id — a uuid — rather than on the organization or the
     * address. A token opens exactly one mailbox, so anything broader would be a
     * key two mailboxes could share, and the failure of sharing one here is one
     * organization's inbox being read with another's credential. The uuid also
     * means the key cannot be constructed by anybody who does not already hold
     * the row.
     */
    private function cacheKey(Mailbox $mailbox): string
    {
        return 'email.google.access-token.'.$mailbox->getKey();
    }

    /**
     * The parked token, or null — and null for anything that is not exactly
     * right.
     *
     * A cache entry that cannot be decrypted is treated as absent rather than as
     * an error. That is not defensive padding: rotating `APP_KEY` leaves entries
     * behind that were written under the old one, and the alternative to
     * shrugging is every shared inbox on the installation failing to open until
     * the cache is flushed by hand.
     */
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

    /**
     * Park it, encrypted, for less time than it is good for.
     *
     * Three quarters of the lifetime Google states — so a token is always
     * replaced with a quarter of its life in hand, which covers a slow request
     * and a server clock several minutes out without ever needing to guess at
     * either.
     *
     * NOTHING IS CACHED when Google does not say how long the token lives, and
     * that is the important branch rather than an edge case. The alternative is
     * assuming an hour, and an assumed lifetime that is too long produces
     * expired tokens on every call until the entry ages out — the exact failure
     * this is supposed to avoid, arriving as a mailbox that mysteriously works
     * for a while after each reconnect.
     */
    private function cacheAccessToken(Mailbox $mailbox, string $token, mixed $expiresIn): void
    {
        $seconds = is_numeric($expiresIn) ? (int) floor(((int) $expiresIn) * 0.75) : 0;

        if ($seconds < 30) {
            return;
        }

        Cache::put($this->cacheKey($mailbox), Crypt::encryptString($token), $seconds);
    }

    /** Forget it, because the provider has just said it is no good. */
    private function forgetAccessToken(Mailbox $mailbox): void
    {
        Cache::forget($this->cacheKey($mailbox));

        // A Workspace inbox's token lives in GoogleWorkspace's cache, not this
        // one. Without this the retry below a 401 would be handed the same
        // rejected token, and a revoked delegation would keep failing until
        // the entry aged out.
        if ($mailbox->providerEnum() === MailboxProvider::GmailWorkspace) {
            $this->workspace->forgetGmailAccessToken($mailbox);
        }
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

    /**
     * Headers for one page of conversations, in one wait.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function threadMetadata(Mailbox $mailbox, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $responses = $this->poolThreadGets($mailbox, $ids, $this->accessToken($mailbox));

        if ($this->anyUnauthorized($responses)) {
            $this->forgetAccessToken($mailbox);
            $responses = $this->poolThreadGets($mailbox, $ids, $this->accessToken($mailbox));
        }

        $out = [];

        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;

            if (! $response instanceof Response) {
                throw CannotReadMailbox::hostNotAnswering(
                    $mailbox,
                    'the mail service did not answer for a conversation',
                );
            }

            $out[$id] = $this->body($mailbox, $response);
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function poolThreadGets(Mailbox $mailbox, array $ids, string $token): array
    {
        try {
            return Http::pool(function (Pool $pool) use ($ids, $token) {
                $pending = [];

                foreach ($ids as $id) {
                    $pending[$id] = $pool->as($id)
                        ->withToken($token)
                        ->timeout(self::TIMEOUT)
                        ->get(self::API.'/threads/'.rawurlencode($id), [
                            'format'          => 'metadata',
                            'metadataHeaders' => [
                                'From', 'To', 'Subject', 'Date', 'Authentication-Results', 'Message-ID',
                                'List-Unsubscribe', 'Precedence', 'Auto-Submitted',
                            ],
                        ]);
                }

                return $pending;
            });
        } catch (ConnectionException $e) {
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $responses */
    private function anyUnauthorized(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === 401) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $query */
    private function get(Mailbox $mailbox, string $url, array $query): array
    {
        return $this->json($mailbox, fn (string $token) => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->get($url, $query));
    }

    /** @param array<string, mixed> $payload */
    private function post(Mailbox $mailbox, string $url, array $payload): array
    {
        return $this->json($mailbox, fn (string $token) => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->post($url, $payload));
    }

    /**
     * A write — Trash — with a token that includes gmail.modify.
     *
     * Listing still uses the read+send token so a missing modify scope
     * cannot take the inbox down. Only this path asks for it.
     *
     * @param  array<string, mixed>  $payload
     */
    /**
     * Reply with copies, or to everyone (design 137). Reply-all is the sender
     * plus every To and Cc on the original, less this inbox itself.
     */
    public function replyWith(Mailbox $mailbox, string $messageId, string $body, array $cc, bool $all): void
    {
        $original = $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), [
            'format'          => 'metadata',
            'metadataHeaders' => ['From', 'To', 'Cc', 'Subject', 'Message-ID', 'References'],
        ]);

        $summary = $this->mapper->summary($original);
        $headers = $this->headerMap($original);
        $self    = mb_strtolower($mailbox->address);
        $list    = fn (string $raw) => array_map('mb_strtolower', array_filter(array_map(
            fn (string $part) => preg_match('/<([^>]+)>/', $part, $m) ? trim($m[1]) : trim($part),
            explode(',', $raw),
        )));

        $to = [mb_strtolower($summary->fromAddress)];

        if ($all) {
            $to = [...$to, ...$list($headers['to'] ?? '')];
            $cc = [...$list($headers['cc'] ?? ''), ...$cc];
        }

        $to = array_values(array_unique(array_diff($to, [$self])));
        $cc = array_values(array_diff(array_unique(array_map('mb_strtolower', $cc)), [$self], $to));

        $this->post($mailbox, self::API.'/messages/send', [
            'threadId' => $summary->threadId,
            'raw'      => $this->composer->composeForGmail($mailbox, $summary, $headers, $body, $to, $cc),
        ]);
    }

    /**
     * Add and remove labels on the conversation a message is in: star,
     * archive (remove INBOX), move, return from snooze (add INBOX).
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function modifyThread(Mailbox $mailbox, string $messageId, array $add, array $remove): void
    {
        try {
            $meta = $this->get($mailbox, self::API.'/messages/'.rawurlencode($messageId), [
                'format' => 'metadata', 'metadataHeaders' => ['From'],
            ]);

            $this->postModify(
                $mailbox,
                self::API.'/threads/'.rawurlencode((string) ($meta['threadId'] ?? $messageId)).'/modify',
                array_filter(['addLabelIds' => $add, 'removeLabelIds' => $remove]),
            );
        } catch (CannotReadMailbox) {
            throw new CannotChangeMail('Google would not change that conversation. Nothing has changed.');
        }
    }

    private function postModify(Mailbox $mailbox, string $url, array $payload): array
    {
        $token = $this->accessToken($mailbox, modify: true);

        $response = Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->post($url, $payload);

        if ($response->failed()) {
            throw $this->apiFailure($mailbox, $response);
        }

        return $response->json() ?? [];
    }

    /**
     * One call to Gmail, with an expired access token treated as ORDINARY.
     *
     * ═══════════════════════════════════════════════════════════════
     *  A 401 HERE USED TO BE A DIAGNOSIS. NOW IT IS A RETRY FIRST.
     *
     *  When every call minted its own token, a 401 could only mean the
     *  grant behind a seconds-old token had gone away — so it was read
     *  as the sign-in no longer being valid, and that was right.
     *
     *  Caching makes it ambiguous, and in the commonest direction. A
     *  parked token can be a few seconds past its usefulness because
     *  Google's clock and ours disagree, or because it was revoked and
     *  reissued elsewhere. Reporting that as "somebody withdrew
     *  EzBizi's access" would mark a perfectly healthy mailbox broken,
     *  raise `email.mailbox_broken` to whoever asked to be told, and
     *  send an administrator to reconnect an inbox that needed nothing
     *  — and it would do it intermittently, which is the worst kind of
     *  wrong to be told about.
     *
     *  So the first 401 throws the cached token away and mints a fresh
     *  one, with nobody present. That refresh is where a genuinely
     *  dead sign-in surfaces, as `invalid_grant` from the token
     *  endpoint — {@see tokenFailure}, the fault this module already
     *  had. If the SECOND call is refused too, the token is a minute
     *  old and the grant really has gone: that is the existing
     *  diagnosis, unchanged.
     *
     *  EXACTLY ONE RETRY. A loop would turn a revoked mailbox into a
     *  request that hammers Google's token endpoint until something
     *  times out, and the quota that follows would then be reported as
     *  a rate limit — a second wrong answer sitting on top of the
     *  first.
     * ═══════════════════════════════════════════════════════════════
     *
     * @param  callable(string): \Illuminate\Http\Client\Response  $call
     * @return array<string, mixed>
     */
    private function json(Mailbox $mailbox, callable $call): array
    {
        return $this->body($mailbox, $this->sendRaw($mailbox, $call));
    }

    /**
     * The same call, with the response handed back unjudged.
     *
     * For the one caller that has to read a status code before deciding anything
     * is wrong: {@see arrivalsSince} treats a 404 as an expired position rather
     * than as a failure, and {@see body} would have turned it into a refusal
     * before that could be seen.
     *
     * Split out rather than duplicated, so the 401-retry above stays the one
     * implementation. A second copy of it is how the two paths come to disagree
     * about whether an expired access token is news.
     *
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
            // No status code exists yet, which is itself the diagnosis: a
            // timeout, a refused connection and a name that will not resolve all
            // arrive here, and all three mean nothing answered.
            throw CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage());
        }
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
     * A 401 that has ALREADY BEEN RETRIED. {@see send} treats the first one as an
     * expired access token, discards it and mints a fresh one; only a rejection
     * of that second, seconds-old token reaches here. So by this point the grant
     * behind it has genuinely gone away — withdrawn, expired, or overtaken by a
     * password change — and this response says no more about which than the token
     * endpoint did. It goes to the same three-in-one sentence, and that sentence
     * says so.
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
        if ($mailbox->providerEnum() === MailboxProvider::GmailWorkspace) {
            $this->workspace->assertCanOpen($mailbox);

            return;
        }

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
