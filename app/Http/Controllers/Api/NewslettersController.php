<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendNewsletter;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterSend;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Newsletter\BlockRenderer;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Newsletter\NewsletterSenders;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Time\ViewTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Newsletters — write, schedule, and hand to the account the company brought.
 *
 * ── Where a newsletter files, and what it can see ───────────────
 *
 * It files on the VIEW (§3): standing in A-1 Chimney writes an A-1 Chimney
 * newsletter, and Everdoors is not an option even for somebody who can reach it.
 * That is the leak that has already happened in Inventory, Fleet and Phone, so
 * the scope is taken from `ViewScope` here and never from a list of companies
 * the caller sent.
 *
 * Reading follows the same rule connections use: an organization-level
 * newsletter is visible down into a company and a franchise; a sibling
 * company's never is.
 *
 * ── The four gates (§4) ─────────────────────────────────────────
 *
 * `newsletter.view` to look, `newsletter.create` to start one, `newsletter.edit`
 * to change it, `newsletter.delete` to remove it, and `newsletter.send` to put
 * it in front of anybody. Sending is separate on purpose: drafting a mailing and
 * mailing twelve hundred customers are different acts and plenty of
 * organizations will want one person who can do the first and not the second.
 */
class NewslettersController extends Controller
{
    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly BlockRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->mayView($request);

        /*
         * The list is the calendar AND the picker - Dashboard and Composer read
         * the same rows - so it answers to either tab rather than taking one
         * route-level gate that would empty the other pane.
         */
        abort_unless(
            $user->hasPermission('newsletter.dashboard.view')
                || $user->hasPermission('newsletter.composer.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open this tab')->message(),
        );

        /*
         * `status` narrows the rows to one of the dashboard's tiles. The list is
         * capped at fifty, so the screen cannot filter it itself: a company
         * with sixty drafts tapping "Drafts" would see whichever drafts fell
         * inside the newest fifty mailings, under a tile counting all sixty
         * (Mia, 2026-10-02). An unknown value is ignored rather than refused,
         * so the unfiltered list is what an old or confused client gets.
         */
        $status = $request->query('status');
        $filter = in_array($status, [
            Newsletter::STATUS_DRAFT, Newsletter::STATUS_SCHEDULED, Newsletter::STATUS_SENT,
        ], true) ? $status : null;

        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        $rows = NewsletterAccess::visible(Newsletter::query(), $user)
            ->when($filter !== null, fn ($q) => $q->where('status', $filter))
            // Same reason as `status`: the list is capped, so the search runs here.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', $like)
                ->orWhereHas('audience', fn ($a) => $a->where('name', 'like', $like))))
            ->with(['audience', 'author:id,name', 'sends'])
            ->latest()
            ->limit(50)
            ->get()
            ->values();

        return response()->json([
            'data' => $rows->map(fn (Newsletter $n) => $this->present($n))->all(),

            /*
             * The screen needs to know whether Send can work at all, and the
             * answer is the server's. A client deciding it from the provider key
             * would be a second copy of the sender map — the mistake the social
             * half already made once and fixed.
             */
            'sending' => [
                'refused_because' => NewsletterSenders::refusalForView($this->connectionsInView($user)),
            ],

            /*
             * Counted over EVERY newsletter this view can see, not over the
             * fifty the list returns.
             *
             * The distinction matters the moment a company has sent more than
             * fifty: a dashboard whose "sent" figure quietly means "sent, among
             * the last fifty" is worse than no figure, because it looks like an
             * answer. `$rows` above is a page; this is the whole set.
             */
            'stats' => $this->statsFor($user),
        ]);
    }

    /**
     * What the dashboard reports, scoped the same way the list is.
     *
     * @return array<string, mixed>
     */
    private function statsFor(User $user): array
    {
        $all = NewsletterAccess::visible(Newsletter::query(), $user)
            ->with('sends')
            ->get();

        $sent = $all->where('status', Newsletter::STATUS_SENT);

        return [
            'drafts' => $all->where('status', Newsletter::STATUS_DRAFT)->count(),
            'scheduled' => $all->where('status', Newsletter::STATUS_SCHEDULED)->count(),
            'sent' => $sent->count(),

            /*
             * Addresses the provider ACCEPTED, summed across every send. Not
             * "delivered" and deliberately not called that: what happens after
             * the provider takes it is the provider's to report, and naming
             * this "delivered" would be a claim EzBizi cannot stand behind.
             */
            'recipients' => $sent->sum(
                fn (Newsletter $n) => (int) ($n->sends->last()?->recipients ?? 0),
            ),

            'last_sent_at' => $sent
                ->map(fn (Newsletter $n) => $n->sends->last()?->sent_at)
                ->filter()
                ->max()?->toIso8601String(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.create'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('start a newsletter')->message(),
        );

        $data = $this->validated($request);

        [$scopeType, $scopeId] = $this->filingScope($user);
        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN, 'Choose a view where your assignments allow creating newsletters.');

        $newsletter = new Newsletter([
            'subject' => $data['subject'],
            'blocks' => $data['blocks'] ?? [],
            'html_override' => $data['html_override'] ?? null,
            'newsletter_audience_id' => $this->audienceIdFor($user, $data['audience_id'] ?? null),
            'created_by' => $user->getKey(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'status' => Newsletter::STATUS_DRAFT,
        ]);

        $this->applySchedule($request, $newsletter, $data['scheduled_for'] ?? null);
        $newsletter->save();

        $this->audit->log('newsletter.created', $newsletter, [
            'subject' => $newsletter->subject,
            'scope_type' => $scopeType,
        ], actor: $user);

        return response()->json(['data' => $this->present($newsletter->fresh(['audience', 'sends']))], Status::HTTP_CREATED);
    }

    public function update(Request $request, string $newsletter): JsonResponse
    {
        return DB::transaction(fn () => $this->updateLocked($request, $newsletter));
    }

    private function updateLocked(Request $request, string $newsletter): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('edit a newsletter')->message(),
        );

        $row = $this->findInView($user, $newsletter, lock: true);
        $this->assertMayWrite($user, $row);
        $data = $this->validated($request);

        /*
         * A mailing that has gone cannot be edited. Not a technical limit — the
         * copy in twelve hundred inboxes is the real one, and letting somebody
         * change the record afterwards makes it disagree with what people
         * actually received.
         */
        if (in_array($row->status, [Newsletter::STATUS_SENT, Newsletter::STATUS_SENDING, Newsletter::STATUS_SCHEDULED], true)) {
            throw ValidationException::withMessages([
                'subject' => 'This newsletter is scheduled, sending, or already sent, so it cannot be changed. '
                    .'Duplicate it to send something similar.',
            ]);
        }

        $row->fill([
            'subject' => $data['subject'],
            'blocks' => $data['blocks'] ?? [],
            'html_override' => $data['html_override'] ?? null,
            'newsletter_audience_id' => $this->audienceIdFor($user, $data['audience_id'] ?? null),
        ]);

        $this->applySchedule($request, $row, $data['scheduled_for'] ?? null);
        $row->save();

        return response()->json(['data' => $this->present($row->fresh(['audience', 'sends']))]);
    }

    public function destroy(Request $request, string $newsletter): JsonResponse
    {
        return DB::transaction(fn () => $this->destroyLocked($request, $newsletter));
    }

    private function destroyLocked(Request $request, string $newsletter): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.delete'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('delete a newsletter')->message(),
        );

        $row = $this->findInView($user, $newsletter, lock: true);
        $this->assertMayWrite($user, $row);

        if ($row->status === Newsletter::STATUS_SENDING) {
            throw ValidationException::withMessages(['subject' => 'This newsletter is being sent and cannot be deleted.']);
        }

        $this->audit->log('newsletter.deleted', $row, ['subject' => $row->subject], actor: $user);
        $row->delete();

        return response()->json(['data' => ['id' => $newsletter]]);
    }

    /**
     * GET /newsletters/{id}/preview — the email itself, as HTML.
     *
     * The same document the send will carry, rendered by the same class. The
     * composer's preview pane shows THIS rather than building its own, because
     * two renderers eventually draw two different emails and the preview would
     * become a picture of one nobody receives.
     */
    public function preview(Request $request, string $newsletter): JsonResponse
    {
        $user = $this->mayView($request);
        $row = $this->findInView($user, $newsletter);

        return response()->json(['data' => ['html' => $this->htmlFor($row)]]);
    }

    /**
     * POST /newsletters/{id}/send
     *
     * Everything that can refuse, refuses before a row is written — the same
     * promise the social composer makes, and for the same reason: a mailing
     * cannot be unsent.
     */
    public function send(Request $request, string $newsletter): JsonResponse
    {
        return DB::transaction(fn () => $this->admitSend($request, $newsletter));
    }

    private function admitSend(Request $request, string $newsletter): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.send'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('send a newsletter')->message(),
        );

        $row = $this->findInView($user, $newsletter, lock: true);
        $this->assertMayWrite($user, $row);

        if (in_array($row->status, [Newsletter::STATUS_SENT, Newsletter::STATUS_SCHEDULED, Newsletter::STATUS_SENDING], true)
            || $row->sends()->whereIn('status', [NewsletterSend::STATUS_QUEUED, NewsletterSend::STATUS_SENDING])->exists()) {
            throw ValidationException::withMessages([
                'subject' => 'This newsletter has already been sent or is waiting to send.',
            ]);
        }

        /*
         * The connection is checked BEFORE the audience, and the order is not
         * arbitrary. An audience cannot even be built without a connected
         * account — it needs a Mailchimp list to point at — so "choose who this
         * goes to" is a step somebody cannot take yet, and telling them to take
         * it sends them to a screen that will refuse them for the same reason.
         */
        $connection = $this->sendingConnection($user, $row);

        if ($connection === null) {
            throw ValidationException::withMessages([
                'audience_id' => (string) NewsletterSenders::refusalForView($this->connectionsInView($user)),
            ]);
        }

        if ($row->audience === null) {
            throw ValidationException::withMessages([
                'audience_id' => 'Choose who this goes to before sending it.',
            ]);
        }

        /*
         * `queued` means "the sweep will pick this up"; `sending` means "a job
         * already has it". Creating an immediate send as `queued` would leave
         * it visible to `ezbizi:send-due-newsletters`, which would claim it and
         * dispatch a second job — the same mailing twice to a real audience.
         */
        $send = $row->sends()->create([
            'connection_id' => $connection->getKey(),
            'provider' => (string) $connection->provider,
            'status' => $row->scheduled_for
                ? NewsletterSend::STATUS_QUEUED
                : NewsletterSend::STATUS_SENDING,
            'recipients' => 0,
        ]);

        $row->forceFill([
            'status' => $row->scheduled_for ? Newsletter::STATUS_SCHEDULED : Newsletter::STATUS_SENDING,
        ])->save();

        $this->audit->log('newsletter.send_requested', $row, [
            'audience' => $row->audience->name,
            'provider' => $connection->provider,
            'scheduled_for' => $row->scheduled_for,
        ], actor: $user);

        // A scheduled mailing dispatches nothing now — `ezbizi:send-due-newsletters`
        // picks it up when its wall clock arrives, the same outbox shape
        // scheduled social posts use.
        if (! $row->scheduled_for) {
            SendNewsletter::dispatch($send->getKey(), $connection->getKey())->afterCommit();
        }

        return response()->json(['data' => $this->present($row->fresh(['audience', 'sends']))]);
    }

    // ── plumbing ────────────────────────────────────────────────

    private function mayView(Request $request): User
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Newsletter')->message(),
        );

        return $user;
    }

    /** @return array{0: string, 1: string} */
    private function filingScope(User $user): array
    {
        $view = ViewScope::forUser($user);

        return $view->isOrganization()
            ? [SocialConnection::SCOPE_ORGANIZATION, (string) $user->organization_id]
            : [$view->type, (string) $view->id];
    }

    private function inView(User $user, Newsletter $n): bool
    {
        // Reuses the connection rule verbatim rather than restating it: an
        // organization-level item is visible down, a sibling company's never is.
        $stand = new SocialConnection;
        $stand->forceFill(['scope_type' => $n->scope_type, 'scope_id' => $n->scope_id]);

        return $this->access->inView($user, $stand);
    }

    private function findInView(User $user, string $id, bool $lock = false): Newsletter
    {
        $query = NewsletterAccess::visible(Newsletter::query(), $user)->with(['audience', 'sends']);
        $row = ($lock ? $query->lockForUpdate() : $query)->find($id);

        // Out of view is a 404 rather than a 403: a sibling company learning
        // that a newsletter exists is itself the leak.
        abort_if($row === null || ! $this->inView($user, $row), Status::HTTP_NOT_FOUND);

        return $row;
    }

    private function assertMayWrite(User $user, Newsletter $row): void
    {
        abort_unless(NewsletterAccess::mayWrite($user, $row), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this newsletter.');
    }

    /** @return Collection<int, SocialConnection> */
    private function connectionsInView(User $user): Collection
    {
        return SocialConnection::query()->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();
    }

    private function sendingConnection(User $user, Newsletter $row): ?SocialConnection
    {
        $scope = $row->audience ?? $row;
        $connections = $this->connectionsInView($user)->filter(
            fn (SocialConnection $c) => NewsletterSenders::for($c->providerEnum()) !== null);
        $exact = $connections->where('scope_type', $scope->scope_type)->where('scope_id', $scope->scope_id);
        if ($exact->count() === 1) {
            return $exact->first();
        }

        // Ambiguous accounts must not be chosen by database row order.
        return $connections->count() === 1 ? $connections->first() : null;
    }

    private function audienceIdFor(User $user, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $audience = NewsletterAudience::query()->find($id);

        if ($audience === null) {
            throw ValidationException::withMessages([
                'audience_id' => 'That audience is not available in this view any more.',
            ]);
        }

        $stand = new SocialConnection;
        $stand->forceFill(['scope_type' => $audience->scope_type, 'scope_id' => $audience->scope_id]);

        if (! $this->access->inView($user, $stand)) {
            throw ValidationException::withMessages([
                'audience_id' => 'That audience is not available in this view any more.',
            ]);
        }

        return $audience->getKey();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'blocks' => ['nullable', 'array'],
            'blocks.*.type' => ['required_with:blocks', 'string', 'max:40'],
            'html_override' => ['nullable', 'string'],
            'audience_id' => ['nullable', 'string'],
            'scheduled_for' => ['nullable', 'string', 'date_format:Y-m-d H:i'],
        ], [
            'subject.required' => 'A newsletter needs a subject line — it is the first thing '
                .'anybody sees.',
            'scheduled_for.date_format' => 'That is not a time this can read. Pick the day and '
                .'time again.',
        ]);

        /*
         * The blocks as SENT, not as validated. `blocks.*.type` makes the
         * validator return each block with only its type, so every heading,
         * text and button was saved empty and the email rendered as a footer
         * - found when the composer began drawing the blocks itself and the
         * server preview disagreed (Mia, 2026-10-02). The renderer escapes
         * every field, so keeping them is safe.
         */
        if (array_key_exists('blocks', $data)) {
            $data['blocks'] = $request->input('blocks');
        }

        return $data;
    }

    /**
     * Wall clock in the VIEW's own timezone, resolved by the server.
     *
     * Identical to scheduled social posts, per TODO #20 — the promise is about a
     * clock on a wall. The client sends a time and never a zone, because a
     * client that sent one could send a different one than the screen showed.
     */
    private function applySchedule(Request $request, Newsletter $row, ?string $wall): void
    {
        if ($wall === null || trim($wall) === '') {
            $row->scheduled_for = null;
            $row->scheduled_timezone = null;

            return;
        }

        $timezone = ViewTimezone::forUser($request->user());
        $moment = Carbon::parse($wall, $timezone);

        if ($moment->lt(now()->subMinute())) {
            throw ValidationException::withMessages([
                'scheduled_for' => 'That time has already passed. Pick a time in the future, '
                    .'or send it now.',
            ]);
        }

        $row->scheduled_for = $moment->format('Y-m-d H:i:s');
        $row->scheduled_timezone = $timezone;
    }

    private function htmlFor(Newsletter $row): string
    {
        if (is_string($row->html_override) && $row->html_override !== '') {
            return $row->html_override;
        }

        return $this->renderer->render((array) ($row->blocks ?? []), [
            'company' => (string) ($row->organization?->name ?? 'EzBizi'),
            'address' => '',
            'unsubscribe_url' => url('/api/v1/newsletter/unsubscribe/preview'),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Newsletter $n): array
    {
        $send = $n->sends->last();

        return [
            'id' => $n->getKey(),
            'subject' => $n->subject,
            'blocks' => $n->blocks ?? [],
            'status' => $n->status,
            'audience' => $n->audience === null ? null : [
                'id' => $n->audience->getKey(),
                'name' => $n->audience->name,
                'kind' => $n->audience->kind,
            ],
            'scheduled_for' => $n->scheduled_for,
            'scheduled_timezone' => $n->scheduled_timezone,
            'author' => $n->author?->name,
            'has_html_override' => is_string($n->html_override) && $n->html_override !== '',
            'last_send' => $send === null ? null : [
                'status' => $send->status,
                'recipients' => $send->recipients,
                'failure' => $send->failure,
                'sent_at' => $send->sent_at?->toIso8601String(),
            ],
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
