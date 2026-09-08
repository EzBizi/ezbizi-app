<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enquiries from connected advertising (EZ Leads).
 *
 * Read and re-file, never create. Every row got here because a member of the
 * public contacted the business, and the one thing this module must not do is
 * let anybody type one in — a lead nobody actually sent is indistinguishable
 * from one somebody did, and the whole point of the record is that it is
 * evidence.
 */
class LeadsController extends Controller
{
    public function __construct(
        private readonly LocalServicesLeads $localServices,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/leads */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();

        /*
         * The reader's own mark rides along on a left join, so "unread" costs
         * no query per row. Unread means no mark, or a mark older than the
         * newest thing on the lead - a message after you read it makes it
         * unread again, which is what a1tools does and what a person expects.
         */
        $query = Lead::query()
            ->select('leads.*', 'lead_reads.read_at as read_by_me_at')
            ->leftJoin('lead_reads', function ($join) use ($user) {
                $join->on('lead_reads.lead_id', '=', 'leads.id')
                    ->where('lead_reads.user_id', '=', $user->getKey());
            })
            ->with(['company:id,name', 'franchise:id,name'])
            ->orderByDesc('leads.received_at')
            ->orderByDesc('leads.created_at');

        // Reach, not just tenancy. BelongsToTenant already filters to the
        // organization; this narrows to the places this person can act on, so
        // a branch manager sees their branch's enquiries and not the group's.
        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds   = $user->reachableCompanies()->pluck('id')->all();

        if (! $user->canActOnOrganization($user->organization)) {
            $query->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('leads.company_id', $companyIds)
                    ->orWhereIn('leads.franchise_id', $franchiseIds)
                    // A row filed at organization level - both columns null,
                    // which is every row from an organization-scoped
                    // connection - belongs to everybody who can see this tab.
                    // Matching the two columns alone hid all of them from
                    // every user below organization level, while the policy
                    // still allowed each one by id. Business Profile carried
                    // this clause from the start; Leads and Reviews did not.
                    ->orWhere(fn ($w) => $w->whereNull('leads.company_id')->whereNull('leads.franchise_id'));
            });
        }

        if (is_string($status = $request->query('status')) && $status !== '') {
            $query->where('leads.status', $status);
        }

        // "Who is waiting on me": the enquirer spoke last. The one filter with
        // data behind it; Unread and Starred are not drawn until they have some.
        if ($request->boolean('unreplied')) {
            $query->where('leads.last_contact_from', LeadConversation::FROM_CONSUMER);
        }

        if ($request->boolean('unread')) {
            $query->where(function ($q) {
                $q->whereNull('lead_reads.read_at')
                    ->orWhereRaw('COALESCE(leads.last_contact_at, leads.received_at) > lead_reads.read_at');
            });
        }

        if ($request->boolean('starred')) {
            $query->whereNotNull('leads.starred_at');
        }

        // One box, two meanings. Somebody looking for a lead has either a name
        // or a phone number in front of them, and asking which they typed is a
        // question the screen should answer for itself. Digits are matched
        // against the normalised column so formatting never decides the result.
        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $search = trim($search);
            $digits = Lead::digits($search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('leads.contact_name', 'like', '%'.$search.'%');

                if ($digits !== null && strlen($digits) >= 4) {
                    $q->orWhere('leads.phone_digits', 'like', '%'.$digits.'%');
                }
            });
        }

        $leads = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => array_map(fn (Lead $l) => $this->present($l), $leads->items()),
            'meta' => [
                'total'        => $leads->total(),
                'per_page'     => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page'    => $leads->lastPage(),
                'connections'  => $this->connectionState(),
            ],
        ]);
    }

    /** GET /api/v1/leads/{lead} */
    public function show(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $data = $this->present($lead->load(['company:id,name', 'franchise:id,name']));

        // The thread. Every call and message, oldest first, so the screen reads
        // top to bottom the way the conversation happened.
        $data['conversations'] = $lead->conversations
            ->map(fn (LeadConversation $c) => $this->presentConversation($c))
            ->values()
            ->all();

        // Whether a reply can go anywhere: Google accepts them for text
        // enquiries only, and only while the account is connected.
        $data['can_reply'] = $lead->lead_type === 'MESSAGE' && $lead->connection_id !== null;

        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function presentConversation(LeadConversation $c): array
    {
        return [
            'id'            => $c->id,
            'channel'       => $c->channel,
            'from'          => $c->participant,
            'occurred_at'   => $c->occurred_at?->toIso8601String(),
            'duration_ms'   => $c->duration_ms,
            'text'          => $c->text,
            'attachments'   => $c->attachments ?? [],
            // Never the URL itself: it is fetched with the connection's
            // token through the recording endpoint.
            'has_recording' => $c->recording_url !== null,
        ];
    }

    /**
     * POST /api/v1/leads/{lead}/messages
     *
     * Reply to the enquirer. The message goes through Google into the chat
     * they wrote in and appears in the thread as ours at once. Audited per
     * send, because it is a sentence to a stranger in the customer's name.
     */
    public function reply(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $text = trim($input['text']);

        abort_if($text === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Write something to send.');

        try {
            $row = $this->localServices->reply($lead, $text);
        } catch (RuntimeException $e) {
            // Google's refusal and "this is a call" both arrive here with a
            // sentence written for the screen. 422 for the ones the person can
            // act on, 502 for the ones only Google can.
            $status = $lead->lead_type !== 'MESSAGE' || $lead->connection === null
                ? Response::HTTP_UNPROCESSABLE_ENTITY
                : Response::HTTP_BAD_GATEWAY;

            abort($status, $e->getMessage());
        }

        $this->audit->log('lead.replied', $lead, [
            'conversation_id' => $row->id,
            'characters'      => mb_strlen($text),
        ], actor: $request->user());

        return response()->json(['data' => $this->presentConversation($row)], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/leads/{lead}/conversations/{conversation}/recording
     *
     * The call, played through EzBizi.
     *
     * Google's recording URL answers only to a bearer token for the account
     * the call was billed to, so the client cannot open it and must not be
     * handed it: the token would go with it. The audio is fetched here with
     * the connection's credentials and streamed back once, uncached. Somebody
     * listening to a stranger's voice is worth an audit row.
     */
    public function recording(Request $request, Lead $lead, string $conversation): HttpResponse
    {
        $this->authorize('view', $lead);

        $row = $lead->conversations()->whereKey($conversation)->first();

        abort_if($row === null || $row->recording_url === null, Response::HTTP_NOT_FOUND);

        $connection = $lead->connection;

        abort_if(
            $connection === null,
            Response::HTTP_CONFLICT,
            'The account this enquiry came through is no longer connected, so its recording cannot be fetched.',
        );

        try {
            $audio = Http::withToken(GoogleToken::forConnection($connection, 'google_ads'))
                ->withHeaders(['developer-token' => (string) config('services.google_ads.developer_token')])
                ->timeout(30)
                ->get($row->recording_url);
        } catch (ConnectionException) {
            abort(Response::HTTP_BAD_GATEWAY, 'Google did not answer while fetching the recording.');
        }

        abort_if(
            $audio->failed(),
            Response::HTTP_BAD_GATEWAY,
            sprintf('Google refused the recording (HTTP %d).', $audio->status()),
        );

        $this->audit->log('lead.recording_played', $lead, [
            'conversation_id' => $row->id,
            'occurred_at'     => $row->occurred_at?->toIso8601String(),
            'duration_ms'     => $row->duration_ms,
        ], actor: $request->user());

        return response($audio->body(), Response::HTTP_OK, [
            'Content-Type'  => $audio->header('Content-Type') ?: 'audio/mpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * PATCH /api/v1/leads/{lead}
     *
     * Status only. Name, phone and what they asked about are a record of what
     * somebody said, not fields to tidy — editing them would turn evidence into
     * notes, and the difference matters the first time a charge is disputed.
     */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            'status'  => ['sometimes', 'string', Rule::in(Lead::STATUSES)],
            'starred' => ['sometimes', 'boolean'],
            'note'    => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        // Star and note are marks, not decisions: no event, one audit row
        // each, and blank clears rather than storing an empty string.
        if (array_key_exists('starred', $input)) {
            $lead->starred_at = $input['starred'] ? now() : null;
        }

        if (array_key_exists('note', $input)) {
            $lead->note = trim((string) $input['note']) ?: null;
        }

        $statusChanges = array_key_exists('status', $input) && $input['status'] !== $lead->status;
        $before        = $lead->status;

        if (! $statusChanges && ! $lead->isDirty()) {
            return response()->json(['data' => $this->present($lead)]);
        }

        DB::transaction(function () use ($lead, $input, $before, $statusChanges) {
            if ($statusChanges) {
                $lead->status = $input['status'];
                $this->audit->logChange('lead.status_changed', $lead);
            } else {
                $this->audit->logChange('lead.marked', $lead);
            }

            $lead->save();

            if ($statusChanges) {
                $this->events->emit('lead.status_changed', $lead, [
                    'before' => ['status' => $before],
                    'after'  => ['status' => $lead->status],
                ]);
            }
        });

        return response()->json(['data' => $this->present($lead->fresh())]);
    }

    /**
     * POST /api/v1/leads/{lead}/read
     *
     * This person has seen this lead as it stands now. A later message makes
     * it unread again for them; nobody else's mark moves.
     */
    public function read(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        DB::table('lead_reads')->upsert(
            [['lead_id' => $lead->getKey(), 'user_id' => $request->user()->getKey(), 'read_at' => now()]],
            ['lead_id', 'user_id'],
            ['read_at'],
        );

        return response()->json(['data' => ['id' => $lead->getKey(), 'is_unread' => false]]);
    }

    /**
     * POST /api/v1/leads/read-all
     *
     * Every lead this person can see, marked read for them, now. Chunked
     * because "every lead" is twenty-four thousand rows on the first tenant.
     */
    public function readAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user  = $request->user();
        $now   = now();
        $count = 0;

        $ids = Lead::query()->select('id');

        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds   = $user->reachableCompanies()->pluck('id')->all();

        if (! $user->canActOnOrganization($user->organization)) {
            $ids->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    ->orWhere(fn ($w) => $w->whereNull('company_id')->whereNull('franchise_id'));
            });
        }

        $ids->chunkById(1000, function ($chunk) use ($user, $now, &$count) {
            $rows = $chunk->map(fn ($l) => [
                'lead_id' => $l->id, 'user_id' => $user->getKey(), 'read_at' => $now,
            ])->all();

            DB::table('lead_reads')->upsert($rows, ['lead_id', 'user_id'], ['read_at']);
            $count += count($rows);
        });

        return response()->json(['data' => ['marked' => $count]]);
    }

    /**
     * POST /api/v1/leads/sync
     *
     * On demand, because the scheduled sync has not been built yet and a screen
     * that can only ever show stale data is worse than no screen. When the
     * scheduler arrives this stays: somebody watching for an enquiry they know
     * was just made should not have to wait for a cron.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();

        $connections = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get()
            /*
             * The view, not just the organization.
             *
             * The tenant scope narrows this to the organization and nothing
             * narrowed it to the company being looked at, so every company in an
             * organization saw - and fetched through - every other company's
             * connected Google account. Marketing's catalogue has always filtered
             * this way; these three tabs never did.
             */
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($connections->isEmpty()) {
            return response()->json([
                'message' => 'No Google Ads account is connected yet, so there is nothing to fetch. '
                    .'Connect one on Marketing to start seeing Local Services enquiries here.',
            ], Response::HTTP_CONFLICT);
        }

        $totals = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'messages' => 0];
        $failures = [];

        foreach ($connections as $connection) {
            try {
                $result = $this->localServices->sync($connection);

                foreach (['fetched', 'created', 'updated'] as $key) {
                    $totals[$key] += $result[$key];
                }

                // New calls and messages, so "Fetched 0 new leads" can still
                // say that three people wrote back.
                $totals['messages'] += $result['conversations']['created'];
            } catch (RuntimeException $e) {
                // One broken connection must not hide the leads the others
                // fetched successfully. Both halves are reported.
                $failures[] = [
                    'connection' => $connection->display_name,
                    'because'    => $e->getMessage(),
                ];
            }
        }

        $this->audit->log('lead.synced', $request->user()->organization, metadata: $totals);

        /*
         * Failed only when EVERY account failed, not when none had leads.
         *
         * `fetched === 0` was standing in for "the fetch failed", but zero
         * leads is also the correct answer for an account that simply has no
         * enquiries. A-1 Chimney has 22 connected Ads accounts: the ordinary
         * shape there is most of them answering honestly with nothing and one
         * being suspended, which reported a hard 502 for a week whose true
         * answer was "no enquiries yet".
         *
         * Counting failures against attempts keeps the case this was written
         * for — nothing could be read at all — and drops the case it was not.
         */
        $failedOut = $failures !== [] && count($failures) === $connections->count();

        $payload = ['data' => [...$totals, 'failures' => $failures]];

        if ($failedOut) {
            // Top-level `message` is what the client actually renders. Without
            // it a 502 becomes "Something went wrong (HTTP 502)" and the
            // sentence in `data.failures` never reaches the screen — which is
            // how the 2026-09-03 A-1 Chimney fetch looked empty of a reason.
            $payload['message'] = (string) ($failures[0]['because'] ?? 'Google would not return leads.');
        }

        return response()->json(
            $payload,
            $failedOut ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK,
        );
    }

    /**
     * What the screen says when there are no leads.
     *
     * "No leads" and "no connection" look identical in an empty list and mean
     * completely different things — one is a quiet week, the other is nothing
     * working. The list carries this so the empty state can tell them apart.
     *
     * @return array<int, array{provider: string, display_name: string, status: string}>
     */
    private function connectionState(): array
    {
        $user = request()->user();

        $connections = SocialConnection::query()
            ->whereIn('provider', array_map(
                fn (SocialProvider $p) => $p->value,
                SocialProvider::dataSources(),
            ))
            ->get()
            /*
             * The view, not just the organization.
             *
             * The tenant scope narrows this to the organization and nothing
             * narrowed it to the company being looked at, so every company in an
             * organization saw - and fetched through - every other company's
             * connected Google account. Marketing's catalogue has always filtered
             * this way; these three tabs never did.
             */
            ->filter(fn (SocialConnection $c) => $user !== null && $this->access->inView($user, $c))
            ->values();

        /*
         * Counted in ONE grouped query, not per row.
         *
         * The screen's whole problem is that A-1 Chimney has twenty-two
         * accounts that look alike, and the only thing that tells them apart is
         * which ones actually produce enquiries. Two busy accounts and twenty
         * silent ones is a readable list; twenty-two identical rows in
         * arbitrary order is not. So the count is what the design is sorted and
         * grouped by, which is why it is here rather than left to the client -
         * `present(Lead)` names no connection, so nothing could be counted
         * client-side at all.
         */
        $counts = Lead::query()
            ->whereIn('connection_id', $connections->map(fn (SocialConnection $c) => $c->getKey()))
            ->selectRaw('connection_id, COUNT(*) as total')
            ->groupBy('connection_id')
            ->pluck('total', 'connection_id');

        /*
         * Name a franchise only if this person can reach it.
         *
         * An account may be placed on a franchise outside the reader's reach -
         * a company-level placement seen by someone scoped to one branch. The
         * id still goes out, because the screen needs to know a placement
         * EXISTS, but the name does not: rendering a bare uuid is worse than
         * saying nothing, and naming a franchise somebody cannot otherwise see
         * leaks the shape of the organization one row at a time.
         *
         * Null name with a non-null id is therefore a real answer, not missing
         * data, and the screen renders it as a place the reader cannot see.
         */
        $reachable = $user?->reachableFranchises()->pluck('name', 'id') ?? collect();

        return $connections
            ->map(fn (SocialConnection $c) => [
                // The id is what lets the tab that owns this connection
                // remove it. Without it the only disconnect was in
                // Marketing, a module these providers are kept out of.
                'id'                   => $c->getKey(),
                'provider'             => $c->provider,
                'display_name'         => $c->display_name,

                // What a person called it, or null. The screen shows this when
                // set and display_name when not — twenty-two numbers are not
                // something anybody remembers the meaning of.
                'label'                => $c->label,
                'status'               => $c->status,
                'leads_franchise_id'   => $c->leads_franchise_id,
                'leads_franchise_name' => $c->leads_franchise_id === null
                    ? null
                    : $reachable->get($c->leads_franchise_id),
                'leads_count'          => (int) ($counts[$c->getKey()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(Lead $lead): array
    {
        return [
            'id'            => $lead->id,
            'source'        => $lead->source,
            'contact_name'  => $lead->contact_name,
            'phone'         => $lead->phone,
            'email'         => $lead->email,
            'lead_type'     => $lead->lead_type,
            'category'      => $lead->category,
            'service'       => $lead->service,
            'locale'        => $lead->locale,
            'summary'       => $lead->summary,
            'status'        => $lead->status,
            'starred'       => $lead->starred_at !== null,
            'note'          => $lead->note,

            // Only known on the list, where the reader's mark was joined in.
            // Null on a single-lead read means "not asked", not "read".
            'is_unread'     => ! array_key_exists('read_by_me_at', $lead->getAttributes())
                ? null
                : ($lead->read_by_me_at === null
                    || ($lead->last_contact_at ?? $lead->received_at) > \Illuminate\Support\Carbon::parse($lead->read_by_me_at)),
            'source_status' => $lead->source_status,
            'charge_status' => $lead->charge_status,
            'received_at'   => $lead->received_at?->toIso8601String(),

            // The preview line: the newest call or message, who it was from,
            // and one line of it. `last_contact_from` = CONSUMER with nothing
            // after it is what "unreplied" means.
            'last_contact_at'      => $lead->last_contact_at?->toIso8601String(),
            'last_contact_channel' => $lead->last_contact_channel,
            'last_contact_from'    => $lead->last_contact_from,
            'last_contact_preview' => $lead->last_contact_preview,
            'company'       => $lead->company?->only(['id', 'name']),
            'franchise'     => $lead->franchise?->only(['id', 'name']),
        ];
    }
}
