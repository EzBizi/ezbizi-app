<?php

namespace App\Http\Controllers\Api;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Email\CannotChangeMail;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\EmailPictureLoader;
use App\Support\Email\HeaderCache;
use App\Support\Email\HtmlEmailSanitiser;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use App\Support\Email\PictureTokens;
use App\Support\Email\PictureUnavailable;
use App\Support\Email\ReadState;
use App\Support\Email\ReplyComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reading a shared inbox — live, from the provider, every time.
 *
 * ── Three gates on every route here ──────────────────────────────
 *
 * `email.view`, a grant for THIS inbox, and §4 reach over the level it hangs
 * off. All three are asked by {@see MailboxAccess} in one call, and the refusal
 * that comes back names which one is missing, because the remedies are different
 * people: a missing grant is fixed in the roles editor, a missing reach by an
 * assignment.
 *
 * ── Nothing here writes a message down ───────────────────────────
 *
 * The owner: *"we don't want to store thousands and thousands of emails on our
 * database."* No route in this file persists a message, a body or an attachment.
 * The only rows written are {@see \App\Models\MessageSeen} — a mailbox, an
 * opaque provider id, a person and a time — and the header cache, which is in
 * the cache store with a sixty-second life and is described in full on
 * {@see HeaderCache}.
 *
 * ── A failure is never an empty list ─────────────────────────────
 *
 * {@see CannotReadMailbox} is caught here and answered with its own status and
 * its own sentence. It is the reason a blank message list in this module always
 * means an empty mailbox: "we cannot look" cannot reach the screen looking like
 * "nobody wrote to you". See that class for the argument in full.
 */
class MailboxMessagesController extends Controller
{
    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
        private readonly ReadState $readState,
        private readonly HeaderCache $cache,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly PictureTokens $pictures,
        private readonly EmailPictureLoader $pictureLoader,
        private readonly HtmlEmailSanitiser $htmlMail,
    ) {}

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/messages — a page of headers.
     *
     * `?search=` runs the PROVIDER'S search over the whole mailbox rather than
     * filtering what is on this page. There is no local index to filter and
     * building one is the sync engine that was ruled out — and searching only
     * the current page would be the worse kind of wrong, since it looks like it
     * works.
     */
    public function index(Request $request, Mailbox $mailbox): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'open this inbox');

        $input = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
            'label'  => ['sometimes', 'nullable', 'string', 'max:32'],
            'unread' => ['sometimes', 'boolean'],
        ]);

        $reader = $this->readers->for($mailbox);
        $limit  = (int) ($input['limit'] ?? 25);
        $search = trim((string) ($input['search'] ?? ''));
        $cursor = $input['cursor'] ?? null;
        $label  = strtoupper((string) ($input['label'] ?? 'INBOX'));
        if ($request->boolean('unread')) {
            $search = trim('is:unread '.$search);
        }

        try {
            $page = $search === '' && $label === 'INBOX'
                /*
                 * Only the plain Inbox listing is cached.
                 *
                 * A search is a question whose answer changes with every message
                 * that arrives, and somebody searching a mailbox has usually
                 * just failed to find something — which is the worst possible
                 * moment to be handed a minute-old answer. A folder other than
                 * Inbox is the same kind of question.
                 */
                ? $this->cache->remember(
                    $mailbox,
                    $cursor,
                    $limit,
                    fn () => $reader->list($mailbox, $cursor, $limit, $label),
                )
                : ($search === ''
                    ? $reader->list($mailbox, $cursor, $limit, $label)
                    : $reader->search($mailbox, $search, $cursor, $limit, $label));
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        }

        return response()->json([
            'data' => $this->withSeen($page, $mailbox, $user),
            'meta' => [
                'mailbox' => $this->mailboxHeader($mailbox),

                /*
                 * How long a cached page may be stale, published rather than
                 * assumed. A client that knows the number can say "as of a
                 * moment ago" honestly instead of implying the list is live.
                 */
                'cache_seconds' => HeaderCache::TTL_SECONDS,
            ],
        ]);
    }

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/messages/{message} — open one.
     *
     * The body arrives as BLOCKS. There is no route in this application that
     * returns email HTML, because there is no shape in the response that could
     * carry it — see App\Support\Email\BodySanitiser for why a block structure
     * is a stronger promise than sanitised markup.
     *
     * Opening MARKS IT SEEN, which is the act somebody performed: a shared inbox
     * exists so colleagues can tell what has been picked up, and requiring a
     * second deliberate click to say "yes, I really looked at it" is a step
     * everybody would forget and the indicator would then be worthless.
     */
    public function show(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'open this inbox');

        try {
            $detail = $this->readers->for($mailbox)->open($mailbox, $message);
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        }

        $detail = $detail->withBlocks($this->pictures->seal($detail->blocks));

        $source = $detail->sourceHtml ?? $detail->html;

        if (is_string($source) && $source !== '') {
            $detail = $detail->withHtml(
                $this->htmlMail->prepare($source, $mailbox, $message),
            );
        }

        if ($detail->thread !== []) {
            $prepared = [];

            foreach ($detail->thread as $part) {
                $part = $part->withBlocks($this->pictures->seal($part->blocks));
                $partSource = $part->sourceHtml ?? $part->html;

                if (is_string($partSource) && $partSource !== '') {
                    $part = $part->withHtml(
                        $this->htmlMail->prepare($partSource, $mailbox, $part->summary->id),
                    );
                }

                $prepared[] = $part;
            }

            $detail = $detail->withThread($prepared);
        }

        $this->readState->markSeen($mailbox, $detail->summary->id, $user);

        foreach ($detail->thread as $part) {
            $this->readState->markSeen($mailbox, $part->summary->id, $user);
        }

        $seen = $this->readState->seenBy($mailbox, [$detail->summary->id], $user);

        return response()->json([
            'data' => [
                ...$detail->toArray(),
                'seen_by' => $seen[$detail->summary->id] ?? [],
                /*
                 * The address a reply will go out AS, stated here so the client
                 * never has to guess. "info replies as info" is a property of
                 * the mailbox, not of whichever inbox the screen thinks is
                 * selected — a stale selection is how a customer gets an
                 * answer from bookings@.
                 */
                'reply' => [
                    'as' => [
                        'address' => $mailbox->address,
                        'name'    => $mailbox->display_name,
                    ],
                    'to' => array_values(array_filter([$detail->summary->fromAddress])),
                    'subject' => app(ReplyComposer::class)
                        ->subjectForReply($detail->summary->subject),
                ],
            ],
            'meta' => ['mailbox' => $this->mailboxHeader($mailbox)],
        ]);
    }

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/messages/{message}/reply
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE REPLY GOES OUT AS THE INBOX. *"info replies as info."*
     *
     *  Never as the individual who typed it, and there is no option
     *  to. The From line is composed by the reader from the MAILBOX
     *  record — see MailboxReader::reply — and nothing about the
     *  signed-in person reaches the message: no From, no Reply-To, no
     *  Sender header, no signature appended here.
     *
     *  Two reasons, and the second is the one that decides it. A
     *  customer who wrote to info@ and gets an answer from
     *  jenny.smith@ has been handed a person's address they did not
     *  ask for — and their next reply then goes to a mailbox only
     *  Jenny can see, which quietly undoes the whole point of a
     *  shared inbox the first time she takes a week off.
     *
     *  Who actually typed it is recorded in the audit log and carried
     *  on the automation event, which is where an internal question
     *  about who said what belongs.
     * ═══════════════════════════════════════════════════════════════
     */
    public function reply(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        // Replying is reading plus sending, and the read gate is the one that
        // decides: somebody who may not open this inbox may certainly not
        // answer from it. There is no separate "may reply" permission, because
        // an inbox somebody can read and not answer is not a shared inbox.
        $this->assertMayRead($user, $mailbox, 'reply from this inbox');

        $input = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
        ]);

        try {
            $this->readers->for($mailbox)->reply($mailbox, $message, $input['body']);
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotReachHost $e) {
            // The SSRF guard refused the SMTP host. A 422 naming the value,
            // never a 403 — nobody is being denied anything.
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        $this->cache->forget($mailbox);

        $this->audit->log('mailbox.replied', $mailbox, [
            'address'    => $mailbox->address,
            'message_id' => $message,
            // The words are NOT recorded. An audit log holding the text of every
            // reply is a second copy of the correspondence — exactly the store
            // decision 2 forbids, arriving through a side door.
            'characters' => strlen($input['body']),
        ]);

        $this->events->emit('email.replied', $mailbox, [
            'address'    => $mailbox->address,
            'message_id' => $message,
        ]);

        return response()->json([
            'message' => sprintf(
                'Sent, from %s. The customer sees it as coming from the inbox rather than from '
                .'you, so their answer comes back here where everybody can see it.',
                $mailbox->address,
            ),
        ]);
    }

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/messages
     *
     * A new message, not an answer to one.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE SAME "info sends as info" RULE AS reply(), AND THE SAME
     *  READ GATE.
     *
     *  (See also `folders()` below, which is the same read gate for
     *  the same reason.)
     *
     *  There is deliberately no `email.send` permission. Replying
     *  already decided this: an inbox somebody can read and not
     *  answer is not a shared inbox, and the same reasoning covers
     *  starting a thread. Adding a fourth verb here would mean a role
     *  that can answer a customer but not write to one first, which
     *  is not a distinction any business has asked for and is a
     *  permission migration across every role in every org to undo.
     * ═══════════════════════════════════════════════════════════════
     */
    public function store(Request $request, Mailbox $mailbox): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'send from this inbox');

        $input = $request->validate([
            // A list, because the envelope takes one RCPT per recipient and a
            // To field that quietly delivered to the first address only would
            // look like success on both ends.
            'to'      => ['required', 'array', 'min:1', 'max:25'],
            'to.*'    => ['required', 'string', 'email', 'max:254'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:50000'],

            /*
             * Files arrive INSIDE this request and are never written down.
             *
             * No `attachments` record, no `attachables` row, no disk. An
             * outgoing file that outlives the send is a second copy of the
             * correspondence — the same store decision that keeps the words
             * out of the audit log, and the reason there is nothing here to
             * clean up later.
             *
             * `max:2048` is kilobytes, per file AND as the total checked
             * below. It sits under the 3MB transport backstop with room for
             * base64's third, and under Graph's inline-attachment ceiling, so
             * a message that passes here sends the same on all three
             * providers rather than on two of them.
             */
            'attachments'   => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:2048'],
        ]);

        $attachments = $this->attachmentsFrom($request);

        if ($attachments === null) {
            return response()->json([
                // The same sentence the panel shows before the upload starts.
                // Two copies of a refusal is normally a smell; here the client
                // one saves a person waiting for 2MB to travel before hearing
                // no, and this one is the check that actually holds. They must
                // agree, so they are written to agree.
                'message' => 'Those files come to more than 2 MB together. Mail services cap '
                    .'what can travel inside a single message, so send fewer, or share '
                    .'the large ones as a link instead.',
            ], 422);
        }

        try {
            $this->readers->for($mailbox)->send(
                $mailbox,
                array_values($input['to']),
                (string) ($input['subject'] ?? ''),
                $input['body'],
                $attachments,
            );
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotReachHost $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        $this->cache->forget($mailbox);

        $this->audit->log('mailbox.sent', $mailbox, [
            'address'    => $mailbox->address,
            // Recipients are recorded; the words are NOT. Same rule as the
            // reply path — an audit log holding the text of every message is a
            // second copy of the correspondence, arriving through a side door.
            'recipients' => count($input['to']),
            'characters' => strlen($input['body']),
            // How many files, never their names. A filename is content —
            // "Smith-eviction-notice.pdf" says as much as a sentence of the
            // body would, and the body is deliberately not here.
            'files'      => count($attachments),
        ]);

        $this->events->emit('email.sent', $mailbox, [
            'address'    => $mailbox->address,
            'recipients' => count($input['to']),
            'files'      => count($attachments),
        ]);

        return response()->json([
            'message' => sprintf(
                'Sent, from %s. The reply comes back here where everybody can see it, '
                .'rather than to you.',
                $mailbox->address,
            ),
        ]);
    }

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/folders
     *
     * Which folders this mailbox can open — asked, not assumed.
     *
     * ── Why this is its own request ──────────────────────────────
     *
     * IMAP is the reason. Gmail and Outlook answer from a constant, but an
     * IMAP server has to be ASKED, and asking means a connection. Folding that
     * into the mailbox listing would open one connection per mailbox every
     * time somebody looked at the Email tab, before they had chosen any of
     * them. This is asked once, when a mailbox is opened.
     *
     * The read gate, because a folder list is a fact about somebody's mail.
     * That an inbox has an Archive folder is not much on its own, and it is
     * not nothing, and there is no reason for it to be reachable by somebody
     * who may not open the inbox.
     */
    public function folders(Request $request, Mailbox $mailbox): JsonResponse
    {
        $this->assertMayRead($request->user(), $mailbox, 'open this inbox');

        try {
            $folders = $this->readers->for($mailbox)->availableFolders($mailbox);
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotReachHost $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json(['data' => array_values($folders)]);
    }

    /**
     * The uploaded files as name/mime/bytes, or null if they are too big together.
     *
     * ── Why the TOTAL is checked here and not by a rule ──────────
     *
     * `attachments.*` => `max:2048` bounds each file on its own, and five
     * files each just under the limit is 10MB — every one of them individually
     * valid and the message unsendable. Per-item validation cannot express
     * "these, added up", so the sum is checked here and refused with a
     * sentence about the sum.
     *
     * ── The MIME type is the server's, not the browser's ─────────
     *
     * `getMimeType()` reads the file; `getClientMimeType()` repeats whatever
     * the client claimed. A recipient's mail program decides how to treat a
     * part by its Content-Type, so taking the client's word lets the sender
     * label anything as anything.
     *
     * @return list<array{name: string, mime: string, bytes: string}>|null
     */
    private function attachmentsFrom(Request $request): ?array
    {
        $files = $request->file('attachments', []);

        if ($files === []) {
            return [];
        }

        $total = 0;
        $out   = [];

        foreach ($files as $file) {
            $bytes  = (string) file_get_contents($file->getRealPath());
            $total += strlen($bytes);

            if ($total > 2 * 1024 * 1024) {
                return null;
            }

            $out[] = [
                // The name the person sees on the file, not the temporary path
                // PHP parked it at.
                'name'  => $file->getClientOriginalName(),
                'mime'  => $file->getMimeType() ?: 'application/octet-stream',
                'bytes' => $bytes,
            ];
        }

        return $out;
    }

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/messages/{message}/picture
     *
     * The original URL is an encrypted token on the block. This origin fetches
     * it, after the host guard, so the browser never dials a host the sender
     * chose. A failure is a missing picture, not a dead inbox.
     */
    public function picture(Request $request, Mailbox $mailbox, string $message): Response
    {
        $this->assertMayRead($request->user(), $mailbox, 'open this inbox');

        $token = (string) $request->query('src', '');

        if ($token === '' || strlen($token) > 8192) {
            return response()->json(['message' => 'That picture could not be loaded.'], 404);
        }

        try {
            $picture = $this->pictureLoader->load($mailbox, $message, $token);
        } catch (PictureUnavailable|CannotReachHost|CannotReadMailbox|\App\Support\Email\GoogleWorkspaceRefused) {
            return response()->json(['message' => 'That picture could not be loaded.'], 404);
        }

        return response($picture->bytes, 200, [
            'Content-Type'  => $picture->mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /api/v1/email/mailboxes/{mailbox}/messages/{message}/file
     *
     * The attachment handle is an encrypted token. Bytes are fetched live
     * and not stored. The filename on the token is the one we put on
     * Content-Disposition — never a path the sender wrote into a header.
     */
    public function file(Request $request, Mailbox $mailbox, string $message): Response
    {
        $this->assertMayRead($request->user(), $mailbox, 'open this inbox');

        $token = (string) $request->query('src', '');

        if ($token === '' || strlen($token) > 8192) {
            return response()->json(['message' => 'That file could not be loaded.'], 404);
        }

        try {
            [$target, $attachmentId] = $this->attachmentHandle(
                $this->pictures->reveal($token),
                $message,
            );
        } catch (PictureUnavailable) {
            return response()->json(['message' => 'That file could not be loaded.'], 404);
        }

        try {
            $file = $this->readers->for($mailbox)->downloadAttachment($mailbox, $target, $attachmentId);
        } catch (PictureUnavailable|CannotReachHost|CannotReadMailbox|\App\Support\Email\GoogleWorkspaceRefused) {
            return response()->json(['message' => 'That file could not be loaded.'], 404);
        }

        $name = $this->safeDownloadName($request->query('name', 'attachment'));

        return response($file->bytes, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: string, 1: string} message id, attachment id
     */
    private function attachmentHandle(string $revealed, string $urlMessage): array
    {
        if (str_contains($revealed, "\n")) {
            [$left, $right] = explode("\n", $revealed, 2);
            if ($left !== '' && $right !== '') {
                return [$left, $right];
            }
        }

        return [$urlMessage, $revealed];
    }

    private function safeDownloadName(mixed $name): string
    {
        $name = is_string($name) ? $name : 'attachment';
        $name = str_replace(['"', "\r", "\n", '/', '\\'], '', $name);
        $name = trim($name);

        return $name === '' ? 'attachment' : substr($name, 0, 180);
    }

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/messages/{message}/seen
     *
     * For the case opening does not cover: somebody who has read a message in
     * the list and dealt with it without opening it, and wants their colleagues
     * to know. Idempotent — see ReadState::markSeen.
     */
    public function markSeen(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'mark a message in this inbox');

        $this->readState->markSeen($mailbox, $message, $user);

        return response()->json([
            'data' => [
                'message_id' => $message,
                'seen_by'    => $this->readState->seenBy($mailbox, [$message], $user)[$message] ?? [],
                'shared'     => $this->readState->isShared($mailbox),
            ],
        ]);
    }

    /**
     * DELETE /api/v1/email/mailboxes/{mailbox}/messages/{message}/seen
     *
     * "Mark as unread" for this person. Only their mark. A shared inbox's
     * "Seen by Marta" is Marta's, and taking it off because somebody else
     * clicked unread would be rewriting a fact they did not ask to rewrite.
     */
    public function unsee(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'mark a message in this inbox');

        $this->readState->forgetSeen($mailbox, $message, $user);

        return response()->json([
            'data' => [
                'message_id' => $message,
                'seen_by'    => $this->readState->seenBy($mailbox, [$message], $user)[$message] ?? [],
                'shared'     => $this->readState->isShared($mailbox),
            ],
        ]);
    }

    /**
     * DELETE /api/v1/email/mailboxes/{mailbox}/messages/{message}
     *
     * The thread goes to the provider's Trash. A shared inbox's bin is
     * shared: everybody who can open this address no longer sees it.
     */
    public function destroy(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $this->assertMayRead($request->user(), $mailbox, 'open this inbox');

        try {
            $this->readers->for($mailbox)->trash($mailbox, $message);
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotChangeMail $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        $this->cache->forget($mailbox);

        return response()->json(['data' => ['id' => $message]]);
    }

    /**
     * POST /api/v1/email/mailboxes/{mailbox}/messages/bulk
     *
     * One round trip for a handful of ticks: trash, or this person's seen
     * mark. Caps at 50 so a mis-click cannot empty a mailbox in one go.
     */
    public function bulk(Request $request, Mailbox $mailbox): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'open this inbox');

        $input = $request->validate([
            'action' => ['required', 'in:trash,seen,unseen'],
            'ids'    => ['required', 'array', 'min:1', 'max:50'],
            'ids.*'  => ['string', 'max:256'],
        ]);

        $ids = array_values(array_unique($input['ids']));
        $reader = $this->readers->for($mailbox);
        $done = [];

        try {
            foreach ($ids as $id) {
                match ($input['action']) {
                    'trash'  => $reader->trash($mailbox, $id),
                    'seen'   => $this->readState->markSeen($mailbox, $id, $user),
                    'unseen' => $this->readState->forgetSeen($mailbox, $id, $user),
                };
                $done[] = $id;
            }
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotChangeMail $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        if ($input['action'] === 'trash') {
            $this->cache->forget($mailbox);
        }

        return response()->json(['data' => ['ids' => $done, 'action' => $input['action']]]);
    }

    // ── shared bits ──────────────────────────────────────────────

    /**
     * A page with the "Seen by" names attached.
     *
     * @return array<string, mixed>
     */
    private function withSeen(MessagePage $page, Mailbox $mailbox, User $user): array
    {
        $decorated = $this->readState->decorate($mailbox, $page->messages, $user);

        return [
            'messages'    => array_map(fn (MessageSummary $m) => $m->toArray(), $decorated),
            'next_cursor' => $page->nextCursor,
            'approximate_total' => $page->approximateTotal,
            'from_cache'  => $page->fromCache,
        ];
    }

    /** What every response repeats about the inbox being looked at. */
    private function mailboxHeader(Mailbox $mailbox): array
    {
        return [
            'id'           => $mailbox->getKey(),
            'address'      => $mailbox->address,
            'display_name' => $mailbox->display_name,
            'read_state_shared' => $this->readState->isShared($mailbox),
            'read_state_note'   => $this->readState->explain($mailbox),
        ];
    }

    /**
     * A mailbox that cannot be read, answered as itself.
     *
     * The status comes from the exception rather than being chosen here, because
     * the three cases are genuinely different to a client: 409 for a mailbox
     * that is not connected, 503 for a provider having a bad afternoon, 501 for
     * something EzBizi has not built. A single 500 would make all three look
     * like a fault at our end, and only one of them is.
     */
    private function refused(CannotReadMailbox $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),

            /*
             * Whose problem it is, as a flag beside the sentence.
             *
             * The sentence already says so, and a client should not have to read
             * English to decide whether to offer a "Reconnect" button. Pointing
             * an administrator at a reconnect flow for something at EzBizi's end
             * wastes their afternoon.
             */
            'at_our_end' => $e->isOurs,
        ], $e->status());
    }

    private function assertMayRead(User $user, Mailbox $mailbox, string $act): void
    {
        $refusal = $this->access->readRefusal($user, $mailbox, $act);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, $refusal);
    }
}
