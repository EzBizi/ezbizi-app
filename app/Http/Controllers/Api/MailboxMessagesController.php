<?php

namespace App\Http\Controllers\Api;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
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
        ]);

        $reader = $this->readers->for($mailbox);
        $limit  = (int) ($input['limit'] ?? 25);
        $search = trim((string) ($input['search'] ?? ''));
        $cursor = $input['cursor'] ?? null;

        try {
            $page = $search === ''
                /*
                 * Only the plain listing is cached.
                 *
                 * A search is a question whose answer changes with every message
                 * that arrives, and somebody searching a mailbox has usually
                 * just failed to find something — which is the worst possible
                 * moment to be handed a minute-old answer.
                 */
                ? $this->cache->remember(
                    $mailbox,
                    $cursor,
                    $limit,
                    fn () => $reader->list($mailbox, $cursor, $limit),
                )
                : $reader->search($mailbox, $search, $cursor, $limit);
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

        $this->readState->markSeen($mailbox, $detail->summary->id, $user);

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
