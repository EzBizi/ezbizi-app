<?php

namespace App\Http\Controllers\Api;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Email\CannotChangeMail;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\DealtState;
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
use App\Support\Email\RecordingMailboxReader;
use App\Support\Email\RepliesWithCopies;
use App\Support\Email\ReplyComposer;
use App\Support\Tenancy\TenantContext;
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
        private readonly DealtState $dealtState,
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
            // The list row's conversation, so Gmail can open it in one fetch.
            // A hint only -- the reader checks it.
            $thread = $request->query('thread');
            $thread = is_string($thread) && $thread !== '' && strlen($thread) <= 256 ? $thread : null;

            $detail = $this->readers->for($mailbox)->open($mailbox, $message, $thread);
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

        /*
         * And at the mail service, if this mailbox asked for that.
         *
         * HERE as well as on markSeen, because THIS is the act somebody
         * described: open a promotion, and expect it to stop being bold in
         * Gmail too. Wiring only the explicit "mark as read" button would have
         * left the setting doing nothing in the case it was asked for — built,
         * shipped, and silent.
         *
         * Once for the conversation, not once per part: the reader modifies
         * the thread, which is what makes a Gmail conversation stop showing as
         * unread and matches the loop above marking every part seen locally.
         */
        // AFTER the response, on opening only. Marking read at Gmail is two more
        // round trips (~330 ms measured), and its outcome is not part of what
        // opening returns -- so making the person wait for it bought nothing.
        // The explicit buttons keep doing it in-request, because they DO
        // report `at_source` back.
        // A deferred callback runs after the tenant middleware has cleared the
        // context, and the Workspace reader reads a tenant-scoped connection --
        // so it carries the mailbox's organization with it (production error,
        // 2026-10-09).
        $openedId = $detail->summary->id;
        $organization = $mailbox->organization;
        defer(fn () => app(TenantContext::class)->run(
            $organization,
            fn () => $this->alsoMarkAtSource($mailbox, $openedId),
        ));

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
            'cc'   => ['sometimes', 'array', 'max:25'],
            'cc.*' => ['string', 'email', 'max:254'],
            'all'  => ['sometimes', 'boolean'],
        ]);

        $reader = $this->readers->for($mailbox);
        $copies = ($input['cc'] ?? []) !== [] || $request->boolean('all');
        $inner  = $reader instanceof RecordingMailboxReader ? $reader->inner : $reader;

        if ($copies && ! $inner instanceof RepliesWithCopies) {
            return response()->json(['message' => 'Reply all and copies work on Gmail inboxes. Reply to the sender instead.'], 422);
        }

        try {
            $copies
                ? $inner->replyWith($mailbox, $message, $input['body'], array_values($input['cc'] ?? []), $request->boolean('all'))
                : $reader->reply($mailbox, $message, $input['body']);
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
     * POST /api/v1/email/mailboxes/{mailbox}/messages/{message}/forward  { to[], body? }
     *
     * Design 137. The person's note, then the original as text under a
     * "Forwarded message" line, sent from the inbox like any new mail. The
     * original's attachments do not travel; the note says so.
     */
    public function forward(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $this->assertMayRead($request->user(), $mailbox, 'forward from this inbox');

        $input = $request->validate([
            'to'   => ['required', 'array', 'min:1', 'max:25'],
            'to.*' => ['required', 'string', 'email', 'max:254'],
            'body' => ['nullable', 'string', 'max:50000'],
        ]);

        $reader = $this->readers->for($mailbox);

        try {
            $original = $reader->open($mailbox, $message);
            $s        = $original->summary;
            $quoted   = implode("\n\n", array_filter(array_map(fn ($block) => trim($block->text()), $original->blocks)));
            $body     = trim((string) ($input['body'] ?? ''))."\n\n---------- Forwarded message ----------\n"
                ."From: {$s->fromName} <{$s->fromAddress}>\n"
                .'Date: '.($s->sentAt?->toDayDateTimeString() ?? '')."\n"
                ."Subject: {$s->subject}\n\n".$quoted
                .($original->attachments === [] ? '' : "\n\n(".count($original->attachments).' attachment(s) on the original were not forwarded.)');

            $reader->send($mailbox, array_values($input['to']), 'Fwd: '.preg_replace('/^(fwd?:\s*)+/i', '', $s->subject), ltrim($body));
        } catch (CannotReadMailbox $e) {
            return $this->refused($e);
        } catch (CannotReachHost $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        $this->cache->forget($mailbox);
        $this->audit->log('mailbox.forwarded', $mailbox, ['address' => $mailbox->address, 'message_id' => $message, 'recipients' => count($input['to'])]);

        return response()->json(['message' => sprintf('Forwarded, from %s.', $mailbox->address)]);
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
     * POST /api/v1/email/mailboxes/{mailbox}/messages/{message}/dealt
     *
     * "This one is handled." One fact for the inbox, not one per person.
     *
     * ── The read gate, for the reply path's reason ───────────────
     *
     * Somebody who may open this inbox may say a message in it is handled.
     * There is no `email.deal` permission for the same reason there is no
     * `email.send` one: an inbox somebody can read and answer, but cannot mark
     * as answered, is a distinction no business has asked for.
     *
     * ── It also marks read at the service, when that is switched on ──
     *
     * The two settings are one intent. An inbox told to mirror its read state
     * to Gmail is an inbox where "handled here" should mean "handled there" —
     * having to mark it twice, in two applications, is the thing that switch
     * exists to stop. Same best-effort rule as opening: the local fact is the
     * one that must not be lost, so a provider refusal is reported rather than
     * thrown.
     */
    public function markDealt(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'mark a message in this inbox');

        $this->dealtState->markDealt($mailbox, $message, $user);

        $this->audit->log('mailbox.message_dealt', $mailbox, [
            'address'    => $mailbox->address,
            'message_id' => $message,
        ]);

        return response()->json([
            'data' => [
                'message_id' => $message,
                'dealt'      => $this->dealtState->dealtWith($mailbox, [$message])[$message] ?? null,
                'at_source'  => $this->alsoMarkAtSource($mailbox, $message),
            ],
        ]);
    }

    /**
     * DELETE /api/v1/email/mailboxes/{mailbox}/messages/{message}/dealt
     *
     * ── ANYBODY may undo this, unlike "seen" ─────────────────────
     *
     * {@see unsee} takes off only the caller's own mark, because "Marta has
     * seen this" is Marta's fact. "This is handled" is the INBOX'S fact: when
     * it turns out the customer was never actually answered, whoever notices
     * has to be able to say so without finding whoever marked it.
     *
     * It does not un-mark at the mail service. Marking read there is a
     * side effect of handling; un-handling something does not make it unread,
     * and reaching into somebody's Gmail to set UNREAD back is a bigger claim
     * than this action makes.
     */
    public function unmarkDealt(Request $request, Mailbox $mailbox, string $message): JsonResponse
    {
        $user = $request->user();

        $this->assertMayRead($user, $mailbox, 'mark a message in this inbox');

        $this->dealtState->forgetDealt($mailbox, $message);

        $this->audit->log('mailbox.message_undealt', $mailbox, [
            'address'    => $mailbox->address,
            'message_id' => $message,
        ]);

        return response()->json([
            'data' => ['message_id' => $message, 'dealt' => null],
        ]);
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

        // EzBizi's own mark FIRST, and it is the one that must not be lost.
        // It is per person, it is what "Seen by" reads, and it is the thing
        // this request was actually for.
        $this->readState->markSeen($mailbox, $message, $user);

        return response()->json([
            'data' => [
                'message_id' => $message,
                'seen_by'    => $this->readState->seenBy($mailbox, [$message], $user)[$message] ?? [],
                'shared'     => $this->readState->isShared($mailbox),
                'at_source'  => $this->alsoMarkAtSource($mailbox, $message),
            ],
        ]);
    }

    /**
     * Also mark it read at the mail service, when this mailbox asked for that.
     *
     * ── Why a failure here does NOT fail the request ─────────────
     *
     * The local mark is the primary fact and it is already written. If Google
     * refuses, the honest outcome is "read in EzBizi, still unread in Gmail" —
     * which is exactly how every mailbox behaved before this existed, so the
     * degradation is to the previous behaviour rather than to something
     * broken. Throwing here would instead lose the mark the person asked for
     * because of a secondary effect they did not.
     *
     * ── And why it is not swallowed either ───────────────────────
     *
     * Returning the outcome is what stops this being the "did nothing" failure
     * this module keeps finding. The caller is told which of the two happened,
     * so a switch that is quietly not working is visible rather than
     * indistinguishable from one that is.
     *
     * A genuinely broken connection still surfaces the usual way: the reader
     * is wrapped by {@see \App\Support\Email\RecordingMailboxReader}, which
     * raises `email.mailbox_broken` when a call fails for a reason that means
     * the connection is gone.
     *
     * ── Both directions, and the cached list ─────────────────────
     *
     * `$read` false marks it UNREAD at the service. Until 2026-10-06 only
     * reading was carried across, so "mark as unread" changed EzBizi's record
     * while the list's bold came from the provider's flag -- the message went
     * straight back to read on the next refresh.
     *
     * And a successful write clears the cached listing, which only trash did
     * before. The list is cached for a short while, so without this the list
     * kept showing the provider's OLD flag for that window: open a message,
     * come back, and it was bold again. That bounce is what Jose saw as read
     * state "not syncing".
     *
     * Only asked of a reader that can actually do it. Outlook and IMAP are
     * read-only grants by design; asking them anyway would make a failing call
     * on every open of every such inbox for no outcome.
     *
     * @return bool|null  true marked, false tried and refused, null not asked for
     */
    private function alsoMarkAtSource(Mailbox $mailbox, string $message, bool $read = true): ?bool
    {
        $reader = $this->readers->for($mailbox);

        if (! $mailbox->mark_read_at_source || ! $reader->canMarkReadAtSource($mailbox)) {
            return null;
        }

        try {
            $read
                ? $reader->markReadAtSource($mailbox, $message)
                : $reader->markUnreadAtSource($mailbox, $message);
        } catch (CannotChangeMail|CannotReadMailbox|CannotReachHost) {
            return false;
        }

        $this->cache->forget($mailbox);

        return true;
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
                'at_source'  => $this->alsoMarkAtSource($mailbox, $message, read: false),
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

                // The ticked-row buttons are the same two actions as the
                // single-message ones, so they carry across the same way.
                if ($input['action'] !== 'trash') {
                    $this->alsoMarkAtSource($mailbox, $id, read: $input['action'] === 'seen');
                }

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

        /*
         * Handled-with is a SEPARATE map rather than a field on each message,
         * and one query for the page rather than one per row.
         *
         * Separate because MessageSummary is what a provider returned — it
         * describes the mail, and "somebody here has handled this" is a fact
         * about EzBizi that no provider knows. Folding it into the summary
         * would put our bookkeeping inside the shape that carries theirs, which
         * is how a mirror starts.
         */
        $ids = array_map(fn (MessageSummary $m) => $m->id, $decorated);

        return [
            'messages'    => array_map(fn (MessageSummary $m) => $m->toArray(), $decorated),
            'dealt'       => $this->dealtState->dealtWith($mailbox, $ids),
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
