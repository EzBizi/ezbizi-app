<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\EmailFilter;
use App\Models\EmailSnooze;
use App\Models\Lead;
use App\Models\Mailbox;
use App\Models\User;
use App\Support\Email\CannotChangeMail;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\HeaderCache;
use App\Support\Email\LabelsThreads;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessageSummary;
use App\Support\Email\ReadState;
use App\Support\Email\RecordingMailboxReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Gmail-style inbox (design 137, 2026-10-22; Jose: "overhaul the Email -
 * Inbox UI, something more akin to how gmail looks").
 *
 * One list across every inbox the person may open, with the same mail that
 * reached admin@ and info@ shown ONCE (merged by Message-ID) and both inboxes
 * named on the row. Folders, Gmail-style categories, the customer or lead the
 * sender is, and star / archive / snooze / move / read / trash in bulk.
 *
 * Access is MailboxAccess per inbox, as everywhere in Email: a row only ever
 * comes from an inbox this person may read, and an action names its inbox.
 */
class InboxController extends Controller
{
    private const FOLDERS = ['inbox' => 'INBOX', 'starred' => 'STARRED', 'sent' => 'SENT', 'drafts' => 'DRAFT', 'all' => 'ALL'];

    private const PER_INBOX = 50; // Gmail's page (Jose, 11-04)

    /** With a tab chosen, at most this many Gmail pages per inbox per request. */
    private const MAX_PAGES = 4;

    /** Rows asked of each inbox when several are listed together. */
    private const PER_INBOX_MANY = 10;

    /** Stop asking further inboxes after this long; the rest come on "Older". */
    private const BUDGET_SECONDS = 20;

    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
        private readonly ReadState $readState,
        private readonly HeaderCache $cache,
        private readonly \App\Support\Email\DealtState $dealtState,
    ) {}

    /** GET /api/v1/email/inbox */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $input = $request->validate([
            'folder'   => ['sometimes', 'in:inbox,starred,snoozed,sent,drafts,all'],
            'category' => ['sometimes', 'nullable', 'in:primary,people,notifications'],
            'mailbox'  => ['sometimes', 'nullable', 'string', 'max:64'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'cursor'   => ['sometimes', 'nullable', 'string', 'max:8192'],
            'filter'   => ['sometimes', 'nullable', 'uuid'],
        ]);

        $folder    = $input['folder'] ?? 'inbox';
        $mailboxes = $this->mailboxes($user, $input['mailbox'] ?? null);

        if ($folder === 'snoozed') {
            return response()->json(['data' => $this->snoozedRows($mailboxes), 'meta' => [
                'cursor' => null, 'errors' => [], 'counts' => $this->counts($mailboxes, collect()),
            ]]);
        }

        $category = $input['category'] ?? null;
        $cursors = json_decode(base64_decode((string) ($input['cursor'] ?? ''), true) ?: '{}', true) ?: [];
        $search  = trim((string) ($input['search'] ?? ''));
        $filters = EmailFiltersController::visibleTo($user);

        // A filter's folder: its match asked of the provider, across every page.
        if (($input['filter'] ?? null) !== null) {
            $opened  = $filters->firstWhere('id', $input['filter']) ?? abort(404);
            $search  = trim($opened->searchQuery().' '.$search);
            $filters = $filters->reject(fn ($f) => $f->is($opened));
        }
        $next    = [];
        $errors  = [];
        $found   = [];   // [mailbox, summary]

        /*
         * All inboxes is ~40 inboxes read one after another, and the gateway
         * gives up at 60 s (Jose, 2026-10-26: "not loading the all inboxes";
         * nginx: upstream timed out). Across several inboxes: fewer rows each,
         * one page each, the cached Inbox listing, and a time budget -- inboxes
         * not reached are carried in the cursor and read on "Older".
         */
        $many     = $mailboxes->count() > 1;
        $limit    = $many ? self::PER_INBOX_MANY : self::PER_INBOX;
        $maxPages = $category === null || $many ? 1 : self::MAX_PAGES;
        $started  = microtime(true);

        foreach ($mailboxes as $mailbox) {
            // A paged-out inbox (cursor present, null) has nothing more; on the first page every inbox is asked.
            if ($cursors !== [] && ! array_key_exists($mailbox->id, $cursors)) {
                continue;
            }

            $reader = $this->readers->for($mailbox);
            $cursor = $cursors[$mailbox->id] ?? null;
            $wanted = 0;
            $mine   = EmailFiltersController::forMailbox($filters, $mailbox);

            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                $next[$mailbox->id] = $cursor;   // not reached: asked on the next page

                continue;
            }

            /*
             * A tab is a filter over Gmail's pages, so one page of 25 can hold
             * 24 notifications and 1 person (Jose, 2026-10-22: "emails inboxes
             * are kind of empty"). With a tab chosen, read on until it has a
             * page's worth or MAX_PAGES pages have been read.
             * ponytail: matches by isBulk before the customer lookup, so a bulk
             * mail from a customer can be counted as a notification here.
             */
            for ($pages = 0; $pages < $maxPages; $pages++) {
                try {
                    $page = match (true) {
                        $search !== '' => $reader->search($mailbox, $search, $cursor, $limit, self::FOLDERS[$folder]),
                        $folder === 'inbox' => $this->cache->remember(
                            $mailbox, $cursor, $limit,
                            fn () => $reader->list($mailbox, $cursor, $limit, 'INBOX'),
                        ),
                        default => $reader->list($mailbox, $cursor, $limit, self::FOLDERS[$folder]),
                    };
                } catch (CannotReadMailbox $e) {
                    $errors[] = ['mailbox_id' => $mailbox->id, 'address' => $mailbox->address, 'message' => $e->getMessage()];

                    break;
                }

                foreach ($page->messages as $summary) {
                    // A filter: hide drops the mail from this view, notifications files it there.
                    $action = $mine->first(fn ($f) => $f->matches($summary->fromName, $summary->fromAddress, $summary->subject))?->action;

                    // Hidden, or moved to its filter's folder; the All tab (no category) keeps
                    // folder mail too, so it is the whole inbox (Jose, 2026-11-03).
                    if ($action === EmailFilter::ACTION_HIDE || ($action === EmailFilter::ACTION_FOLDER && $category !== null)) {
                        continue;
                    }

                    $filed   = $action === EmailFilter::ACTION_NOTIFICATIONS;
                    $found[] = [$mailbox, $summary, $filed];
                    $wanted += ($category === 'notifications') === ($filed || $summary->isBulk) ? 1 : 0;
                }

                $cursor = $page->nextCursor;

                if ($cursor === null || $wanted >= $limit) {
                    break;
                }
            }

            if ($cursor !== null) {
                $next[$mailbox->id] = $cursor;
            }
        }

        $rows = $this->merge($found, $user);

        return response()->json([
            'data' => $rows->when($category !== null, fn ($r) => $r->where('category', $category))->values(),
            'meta' => [
                'cursor' => $next === [] ? null : base64_encode(json_encode($next)),
                'errors' => $errors,
                'counts' => $this->counts($mailboxes, $rows),
            ],
        ]);
    }

    /** POST /api/v1/email/inbox/actions */
    public function actions(Request $request): JsonResponse
    {
        $user  = $request->user();
        $input = $request->validate([
            'action'             => ['required', 'in:star,unstar,archive,read,unread,snooze,unsnooze,move,trash'],
            'items'              => ['required', 'array', 'min:1', 'max:100'],
            'items.*.mailbox_id' => ['required', 'string', 'max:64'],
            'items.*.message_id' => ['required', 'string', 'max:256'],
            'until'              => ['required_if:action,snooze', 'nullable', 'date', 'after:now'],
            'folder'             => ['required_if:action,move', 'nullable', 'in:inbox'],
        ]);

        $readable = $this->access->readable($user)->keyBy('id');
        $results  = [];

        foreach ($input['items'] as $item) {
            $mailbox = $readable->get($item['mailbox_id']);
            $id      = $item['message_id'];

            if (! $mailbox instanceof Mailbox) {
                $results[] = ['mailbox_id' => $item['mailbox_id'], 'message_id' => $id, 'ok' => false,
                    'message' => 'You cannot open that inbox.'];

                continue;
            }

            try {
                $this->act($input['action'], $mailbox, $id, $user, $input['until'] ?? null);
                $results[] = ['mailbox_id' => $mailbox->id, 'message_id' => $id, 'ok' => true, 'message' => null];
            } catch (CannotChangeMail|CannotReadMailbox $e) {
                $results[] = ['mailbox_id' => $mailbox->id, 'message_id' => $id, 'ok' => false, 'message' => $e->getMessage()];
            }

            $this->cache->forget($mailbox);
        }

        return response()->json(['data' => ['results' => $results]]);
    }

    private function act(string $action, Mailbox $mailbox, string $id, User $user, ?string $until): void
    {
        $reader = $this->readers->for($mailbox);

        match ($action) {
            'star'     => $this->labels($reader, $mailbox)->modifyThread($mailbox, $id, ['STARRED'], []),
            'unstar'   => $this->labels($reader, $mailbox)->modifyThread($mailbox, $id, [], ['STARRED']),
            'archive'  => $this->labels($reader, $mailbox)->modifyThread($mailbox, $id, [], ['INBOX']),
            'move'     => $this->labels($reader, $mailbox)->modifyThread($mailbox, $id, ['INBOX'], []),
            'trash'    => $reader->trash($mailbox, $id),
            'read'     => $this->read($reader, $mailbox, $id, $user, true),
            'unread'   => $this->read($reader, $mailbox, $id, $user, false),
            'snooze'   => $this->snooze($reader, $mailbox, $id, $user, Carbon::parse($until)),
            'unsnooze' => $this->wake($mailbox, $id),
        };
    }

    private function read(MailboxReader $reader, Mailbox $mailbox, string $id, User $user, bool $read): void
    {
        $read ? $this->readState->markSeen($mailbox, $id, $user) : $this->readState->forgetSeen($mailbox, $id, $user);

        if ($mailbox->mark_read_at_source && $reader->canMarkReadAtSource($mailbox)) {
            $read ? $reader->markReadAtSource($mailbox, $id) : $reader->markUnreadAtSource($mailbox, $id);
        }
    }

    private function snooze(MailboxReader $reader, Mailbox $mailbox, string $id, User $user, Carbon $until): void
    {
        $summary = collect($reader->list($mailbox, null, 50, 'INBOX')->messages)->firstWhere('id', $id);

        $this->labels($reader, $mailbox)->modifyThread($mailbox, $id, [], ['INBOX']);

        EmailSnooze::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->id, 'message_id' => $id],
            [
                'thread_id'    => $summary?->threadId,
                'subject'      => mb_substr((string) $summary?->subject, 0, 255),
                'from_name'    => mb_substr((string) $summary?->fromName, 0, 255),
                'from_address' => mb_substr((string) $summary?->fromAddress, 0, 255),
                'snippet'      => $summary?->snippet,
                'until'        => $until,
                'snoozed_by'   => $user->id,
            ],
        );
    }

    /** Back in the Inbox now; also what ezbizi:wake-snoozed-mail calls when `until` passes. */
    public function wake(Mailbox $mailbox, string $id): void
    {
        $this->labels($this->readers->for($mailbox), $mailbox)->modifyThread($mailbox, $id, ['INBOX'], []);

        EmailSnooze::query()->where('mailbox_id', $mailbox->id)->where('message_id', $id)->delete();
        // A bulk delete fires no model event: the woken mail is the inbox's change.
        \App\Support\Live\ChangeFeed::record($mailbox, 'mailboxes');
    }

    private function labels(MailboxReader $reader, Mailbox $mailbox): LabelsThreads
    {
        $inner = $reader instanceof RecordingMailboxReader ? $reader->inner : $reader;

        if (! $inner instanceof LabelsThreads) {
            throw new CannotChangeMail('This inbox\'s mail service has no stars or archive. That works on Gmail inboxes.');
        }

        return $inner;
    }

    /** @return Collection<int, Mailbox> */
    private function mailboxes(User $user, ?string $only): Collection
    {
        $readable = $this->access->readable($user)
            ->filter(fn (Mailbox $m) => $this->access->inView($user, $m))
            ->values();

        return $only === null ? $readable : $readable->where('id', $only)->values();
    }

    /**
     * One row per mail, however many inboxes it reached.
     *
     * @param  list<array{0: Mailbox, 1: MessageSummary}>  $found
     */
    private function merge(array $found, User $user): Collection
    {
        $rows = [];

        foreach ($found as [$mailbox, $s, $filed]) {
            $key   = $s->rfcId !== '' ? 'm:'.$s->rfcId : 't:'.$mailbox->id.':'.($s->threadId ?? $s->id);
            $where = ['mailbox_id' => $mailbox->id, 'address' => $mailbox->address, 'message_id' => $s->id, 'thread_id' => $s->threadId];

            if (! isset($rows[$key])) {
                $rows[$key] = ['summary' => $s, 'inboxes' => [$where], 'unread' => $s->unreadAtProvider,
                    'starred' => in_array('STARRED', $s->labels, true), 'count' => $s->messageCount, 'bulk' => $s->isBulk, 'filed' => $filed];

                continue;
            }

            $rows[$key]['inboxes'][] = $where;
            $rows[$key]['unread']    = $rows[$key]['unread'] || $s->unreadAtProvider;
            $rows[$key]['starred']   = $rows[$key]['starred'] || in_array('STARRED', $s->labels, true);
            $rows[$key]['count']     = max($rows[$key]['count'], $s->messageCount);
            $rows[$key]['bulk']      = $rows[$key]['bulk'] || $s->isBulk;
            $rows[$key]['filed']     = $rows[$key]['filed'] || $filed;
        }

        $who = $this->whoSent(array_map(fn ($r) => mb_strtolower($r['summary']->fromAddress), $rows), $user);

        // Handled, per inbox the mail is in: one query per inbox for the page.
        $dealt = [];
        foreach (collect($found)->groupBy(fn ($pair) => $pair[0]->id) as $pairs) {
            $dealt[$pairs[0][0]->id] = $this->dealtState->dealtWith($pairs[0][0], $pairs->map(fn ($pair) => $pair[1]->id)->all());
        }

        foreach ($rows as &$r) {
            foreach ($r['inboxes'] as &$where) {
                $where['dealt'] = $dealt[$where['mailbox_id']][$where['message_id']] ?? null;
            }
            unset($where);
        }
        unset($r);

        return collect($rows)->map(function (array $r, string $key) use ($who) {
            $s        = $r['summary'];
            $customer = $who[mb_strtolower($s->fromAddress)] ?? null;

            return [
                'key'             => $key,
                'subject'         => $s->subject,
                'snippet'         => $s->snippet,
                'from'            => ['name' => $s->fromName, 'address' => $s->fromAddress],
                'received_at'     => $s->sentAt?->toIso8601String(),
                'unread'          => $r['unread'],
                'starred'         => $r['starred'],
                'has_attachments' => $s->hasAttachments,
                'message_count'   => max(1, $r['count']),
                // A known customer or lead outranks a bulk marker: their
                // automated invoice reply is still them.
                // A filter somebody made outranks both: they said where it goes.
                'category'        => $r['filed'] ? 'notifications' : ($customer !== null ? 'people' : ($r['bulk'] ? 'notifications' : 'primary')),
                'inboxes'         => $r['inboxes'],
                // Handled if handled in any inbox it reached: {at, by} of the first.
                'dealt'           => collect($r['inboxes'])->pluck('dealt')->filter()->first(),
                'customer'        => $customer,
            ];
        })->sortByDesc('received_at')->values();
    }

    /**
     * The customer or lead each sender is, when this person may see them.
     *
     * @param  array<array-key, string>  $addresses
     * @return array<string, array{type: string, id: string, name: string}>
     */
    private function whoSent(array $addresses, User $user): array
    {
        $addresses = array_values(array_unique(array_filter($addresses)));
        $who       = [];

        if ($addresses === []) {
            return $who;
        }

        if ($user->hasPermission('leads.view')) {
            foreach (Lead::query()->whereIn('email', $addresses)->get(['id', 'email', 'contact_name']) as $lead) {
                $who[mb_strtolower((string) $lead->email)] = ['type' => 'lead', 'id' => $lead->id, 'name' => (string) $lead->contact_name];
            }
        }

        if ($user->hasPermission('customers.view')) {
            foreach (Customer::query()->whereIn('email', $addresses)->get(['id', 'email', 'name']) as $customer) {
                $who[mb_strtolower((string) $customer->email)] = ['type' => 'customer', 'id' => $customer->id, 'name' => (string) $customer->name];
            }
        }

        return $who;
    }

    /** @param Collection<int, Mailbox> $mailboxes */
    private function snoozedRows(Collection $mailboxes): array
    {
        $byId = $mailboxes->keyBy('id');

        return EmailSnooze::query()->whereIn('mailbox_id', $byId->keys())->orderBy('until')->get()
            ->map(fn (EmailSnooze $z) => [
                'key'             => 'z:'.$z->id,
                'subject'         => $z->subject,
                'snippet'         => (string) $z->snippet,
                'from'            => ['name' => $z->from_name, 'address' => $z->from_address],
                'received_at'     => null,
                'snoozed_until'   => $z->until->toIso8601String(),
                'unread'          => false,
                'starred'         => false,
                'has_attachments' => false,
                'message_count'   => 1,
                'category'        => 'primary',
                'inboxes'         => [['mailbox_id' => $z->mailbox_id, 'address' => $byId[$z->mailbox_id]->address,
                    'message_id' => $z->message_id, 'thread_id' => $z->thread_id]],
                'customer'        => null,
            ])->all();
    }

    /**
     * Counts this list can state honestly: categories over the rows loaded,
     * and how many conversations are snoozed. Unread per inbox is on
     * GET email/mailboxes (`unread`).
     *
     * @param  Collection<int, Mailbox>  $mailboxes
     */
    private function counts(Collection $mailboxes, Collection $rows): array
    {
        return [
            'categories' => [
                'primary'       => $rows->where('category', 'primary')->count(),
                'people'        => $rows->where('category', 'people')->count(),
                'notifications' => $rows->where('category', 'notifications')->count(),
            ],
            'unread_loaded' => $rows->where('unread', true)->count(),
            'snoozed'       => EmailSnooze::query()->whereIn('mailbox_id', $mailboxes->pluck('id'))->count(),
        ];
    }
}
