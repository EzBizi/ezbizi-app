<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Services\Leads\LeadInsights;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadReplies;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
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
        private readonly RecordNumberGenerator $numbers,
        private readonly LeadReplies $replies,
        private readonly LeadInsights $insights,
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
            ->with(['company:id,name', 'franchise:id,name', 'noteBy:id,name'])
            /*
             * Newest ACTIVITY first, not newest lead. Jose, 2026-09-08: leads
             * that a1tools showed at the top "were not appearing" here. They
             * were here, thirty rows down: a lead from three days ago with a
             * text five minutes ago sorted by the day it arrived, while the
             * dispatcher was looking for the text. A conversation list is
             * ordered by its last message; this is one.
             */
            ->orderByRaw('COALESCE(leads.last_contact_at, leads.received_at) DESC')
            ->orderByDesc('leads.created_at');

        // Reach, not just tenancy. BelongsToTenant already filters to the
        // organization; this narrows to the places this person can act on, so
        // a branch manager sees their branch's enquiries and not the group's.
        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds = $user->reachableCompanies()->pluck('id')->all();

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

        /*
         * REACH is a floor; the VIEW is what somebody is looking at now.
         *
         * The clause above answers "may this person act here" and stops at the
         * organization: an owner can act everywhere, so it filtered nothing and
         * the scope switcher changed the connections band while the list under
         * it stayed the same. Jose, 2026-09-10, standing in Everdoors › A1
         * Garage Doors: "i should only see them mixed when im in the
         * organization level ... right now it's showing me a1chimney and a1
         * garage doors".
         *
         * The rule is SocialConnectionAccess::inView's, one level down — a lead
         * is placed where its connection was, so the two must agree or the band
         * says one thing and the rows say another. An organization-scoped
         * connection is visible in every view there, so a lead filed at
         * organization level (both columns null) is visible in every view here.
         * That is why Thumbtack's twelve businesses, connected at organization
         * level, still appear under a company: their leads belong to no company
         * because the account belongs to none.
         *
         * ConnectionsRespectTheViewTest asserted the band and said in prose
         * that "index() correctly filters the rows by view". It did not, for
         * anybody who can act on the organization, and no test read a row.
         */
        $view = ViewScope::forUser($user);

        if (! $view->isOrganization()) {
            $viewCompanyIds = $view->type === ViewScope::COMPANY
                ? $view->companyIds()
                // A franchise view covers its own company's work, the same
                // match inView makes for a company-scoped connection.
                : array_values(array_filter([Franchise::find($view->id)?->company_id]));

            $viewFranchiseIds = $view->franchiseIds();

            $query->where(function ($q) use ($viewCompanyIds, $viewFranchiseIds) {
                $q->whereIn('leads.company_id', $viewCompanyIds)
                    ->orWhereIn('leads.franchise_id', $viewFranchiseIds)
                    ->orWhere(fn ($w) => $w->whereNull('leads.company_id')->whereNull('leads.franchise_id'));
            });
        }

        /*
         * A DISCONNECTED ACCOUNT TAKES ITS ENQUIRIES OFF THE LIST WITH IT.
         *
         * Jose, 2026-09-16, having disconnected A-1 Chimney's Google Ads
         * account and found its enquiries still sitting there: "if the account
         * where the leads come from is disconnected, then it should hide said
         * leads, only show them again once the account is reconnected."
         *
         * They are hidden rather than removed, and the "show them again" half
         * needs no code: disconnecting only nulls `connection_id`, the rows
         * keep `remote_customer_id`, and reclaim() re-adopts them on the next
         * connect. The same mechanism that fixed 41,006 orphans reads as the
         * un-hide here.
         *
         * See Lead::scopeSourceStillConnected for why this is not a global
         * scope, and why website enquiries are exempt rather than special-cased
         * (they never had a connection_id to lose).
         */
        $query->sourceStillConnected();

        if (is_string($status = $request->query('status')) && $status !== '') {
            $query->where('leads.status', $status);
        }

        // "Who is waiting on me": the enquirer spoke last. The one filter with
        // data behind it; Unread and Starred are not drawn until they have some.
        if ($request->boolean('unreplied')) {
            /*
             * NOBODY HAS REPLIED INCLUDES NOBODY HAS FETCHED.
             *
             * `last_contact_from` is a cache of the newest CONVERSATION, and
             * conversations are read on a slower tick than leads — ten minutes
             * against five, and slower still on the day the Google quota runs
             * out, since the second pass is the half designed to lose. Until
             * that read lands the column is null, and a filter matching only
             * CONSUMER answered "nobody is waiting" about somebody who had
             * just rung.
             *
             * The same lead flipped INTO the filter minutes later with nothing
             * about the customer having changed, which is the tell: it was
             * reporting our fetch state as their behaviour.
             *
             * Null means no call or message is held, and a lead exists because
             * somebody made an enquiry. Nobody has replied to them — that is
             * what this filter is asked, and it is true in both readings.
             */
            $query->where(function ($q) {
                $q->where('leads.last_contact_from', LeadConversation::FROM_CONSUMER)
                    ->orWhereNull('leads.last_contact_from');
            });
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

        // Source is the fourth filter, beside the three it narrows with (UI/UX
        // sources layout 1, 2026-09-08): one list answers "who is waiting on
        // me" across Google, Thumbtack and the website at once.
        if (is_string($source = $request->query('source')) && $source !== '') {
            $query->where('leads.source', $source);
        }

        /*
         * One box, many meanings. Somebody looking for a lead has a name, an
         * email, a phone number in whatever punctuation they saw it in, or a
         * few words of what was said. Digits are matched against the
         * normalised column so "(609)", "609-246" and "609246" all find
         * +1 (609) 246-1458; the four-digit floor that used to be here made
         * "609" find nothing while "609246" found the lead (Jose, 2026-09-09),
         * and a person types the short form first. Two digits is enough to be
         * a search and not an accident.
         */
        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $search = trim($search);
            $digits = Lead::digits($search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('leads.contact_name', 'like', '%'.$search.'%')
                    ->orWhere('leads.email', 'like', '%'.$search.'%')
                    ->orWhere('leads.last_contact_preview', 'like', '%'.$search.'%');

                if ($digits !== null && strlen($digits) >= 2) {
                    $q->orWhere('leads.phone_digits', 'like', '%'.$digits.'%');
                }
            });
        }

        $leads = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => array_map(fn (Lead $l) => $this->present($l), $leads->items()),
            'meta' => [
                'total' => $leads->total(),
                'per_page' => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'connections' => $this->connectionState(),
            ],
        ]);
    }

    /** GET /api/v1/leads/{lead} */
    public function show(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $data = $this->present($lead->load(['company:id,name', 'franchise:id,name', 'job', 'noteBy:id,name']));

        // The thread. Every call and message, oldest first, so the screen reads
        // top to bottom the way the conversation happened.
        $data['conversations'] = $lead->conversations
            ->map(fn (LeadConversation $c) => $this->presentConversation($c))
            ->values()
            ->all();

        // Whether a reply can go anywhere, and when it cannot, WHY — in the
        // refusing channel's own words. The screen used to carry one sentence
        // for every source, and it named Google; the first Thumbtack enquiry
        // was told it had come in as a call on an account Google has never
        // heard of.
        $data['can_reply'] = $this->replies->canReply($lead);
        $data['cannot_reply_because'] = $this->replies->whyNot($lead);

        // A draft is offered only where a reply can go and the model is on.
        $data['can_suggest'] = $data['can_reply'] && $this->insights->enabled();

        // Customers this number already belongs to, so booking can attach to
        // one instead of making a second Jane Doe. Same digits-only match the
        // Customers screen uses for its duplicate warning; advisory only.
        $data['customer_matches'] = $this->customerMatches($lead)
            ->map(fn (Customer $c) => ['id' => $c->id, 'number' => $c->number, 'name' => $c->name, 'phone' => $c->phone])
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    /** @return Collection<int, Customer> */
    private function customerMatches(Lead $lead): Collection
    {
        $digits = $lead->phone_digits;

        if ($digits === null || strlen($digits) < 7) {
            return collect();
        }

        // Google sends +1 216 555 0100; a customer typed in by hand may be
        // (216) 555-0100. The country code is the difference, so the last
        // ten digits decide when both sides have that many.
        $expr = "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '')";

        return Customer::query()
            ->when(
                strlen($digits) >= 10,
                fn ($q) => $q->whereRaw("RIGHT({$expr}, 10) = ?", [substr($digits, -10)]),
                fn ($q) => $q->whereRaw("{$expr} = ?", [$digits]),
            )
            ->orderBy('name')
            ->limit(3)
            ->get();
    }

    /**
     * POST /api/v1/leads/{lead}/book
     *
     * The lead becomes a customer and a job. Item 2 of the a1tools list (Jose,
     * 2026-09-08): what a1leads books into Workiz, EzBizi books into its own
     * Jobs. One transaction: the customer (attached by id, or created from the
     * details in the request, which may correct the lead's - the lead itself
     * stays evidence), the job in the chosen franchise, the lead marked booked
     * and pointing at the job, and the events every other path raises for a
     * new customer and a new job, plus lead.converted, declared the day the
     * module was drawn and raised for the first time here.
     *
     * The same shape as JobsController::store and CustomersController::store,
     * including their two-argument policy checks: booking into a franchise is
     * creating there, and somebody who cannot open a job in Connecticut
     * cannot do it through a lead either.
     */
    public function book(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            // A lead placed in a franchise prefills this; one at company level
            // has none, and the person picks.
            'franchise_id' => ['sometimes', 'uuid', $this->existsInTenant('franchises')],
            'customer_id' => ['nullable', 'uuid', $this->existsInTenant('customers')],
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['nullable', 'string', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.email' => ['nullable', 'email', 'max:200'],
            'customer.address_line1' => ['nullable', 'string', 'max:200'],
            'customer.address_line2' => ['nullable', 'string', 'max:200'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.state' => ['nullable', 'string', 'size:2'],
            'customer.postal_code' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:200'],
            'status' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:10000'],
            'scheduled_for' => ['nullable', 'date'],
        ]);

        $franchiseId = $input['franchise_id'] ?? $lead->franchise_id;

        if ($franchiseId === null) {
            throw ValidationException::withMessages([
                'franchise_id' => 'This enquiry is not placed in a franchise. Pick the one that will do the work.',
            ]);
        }

        $franchise = Franchise::query()->findOrFail($franchiseId);

        if (! in_array($input['status'], Job::allowedStatuses($franchise), strict: true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Not one of this organization\'s job statuses (%s). Change the list in Settings.',
                    implode(', ', Job::allowedStatuses($franchise)),
                ),
            ]);
        }

        $existing = isset($input['customer_id']) ? Customer::query()->findOrFail($input['customer_id']) : null;

        if ($existing === null) {
            $this->authorize('create', [Customer::class, $franchise]);
        }

        $this->authorize('create', [Job::class, $franchise]);

        $user = $request->user();

        $result = DB::transaction(function () use ($lead, $input, $franchise, $existing, $user) {
            $customer = $existing;
            $created = false;

            if ($customer === null) {
                $details = (array) ($input['customer'] ?? []);

                $customer = Customer::create([
                    'owning_franchise_id' => $franchise->getKey(),
                    'number' => $this->numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => trim((string) ($details['name'] ?? '')) ?: ($lead->contact_name ?: ($lead->phone ?: 'Enquirer')),
                    'phone' => trim((string) ($details['phone'] ?? '')) ?: $lead->phone,
                    'email' => trim((string) ($details['email'] ?? '')) ?: $lead->email,
                    'address_line1' => $details['address_line1'] ?? null,
                    'address_line2' => $details['address_line2'] ?? null,
                    'city' => $details['city'] ?? null,
                    'state' => isset($details['state']) ? strtoupper($details['state']) : null,
                    'postal_code' => $details['postal_code'] ?? null,
                ]);
                $created = true;

                $this->audit->log('customer.created', $customer, [
                    'number' => $customer->number,
                    'from_lead' => $lead->getKey(),
                ], actor: $user);

                $this->events->emit('customer.created', $customer, [
                    'after' => $customer->only(['name', 'phone', 'email', 'owning_franchise_id']),
                ]);
            }

            $job = Job::create([
                'franchise_id' => $franchise->getKey(),
                'customer_id' => $customer->getKey(),
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $input['title'],
                'status' => $input['status'],
                'description' => $input['description'] ?? null,
                'scheduled_for' => $input['scheduled_for'] ?? null,
                'currency' => strtoupper($franchise->currency ?: 'USD'),
            ]);

            $this->audit->log('job.created', $job, [
                'franchise' => $franchise->name,
                'customer' => $customer->name,
                'number' => $job->number,
                'from_lead' => $lead->getKey(),
                'customer_owned_by_other_franchise' => $customer->owning_franchise_id !== $franchise->getKey(),
            ], actor: $user);

            $this->events->emit('job.created', $job, ['after' => $job->only([
                'status', 'title', 'franchise_id', 'customer_id', 'amount_minor',
            ])]);

            if ($job->scheduled_for !== null) {
                $this->events->emit('job.scheduled', $job, [
                    'after' => ['scheduled_for' => $job->scheduled_for->toIso8601String()],
                ]);
            }

            $before = $lead->status;
            $lead->status = Lead::STATUS_BOOKED;
            $lead->job_id = $job->getKey();
            $this->audit->logChange('lead.converted', $lead);
            $lead->save();

            $this->events->emit('lead.converted', $lead, [
                'before' => ['status' => $before],
                'after' => [
                    'status' => $lead->status,
                    'source' => $lead->source,
                    'job_id' => $job->getKey(),
                    'customer_id' => $customer->getKey(),
                ],
            ]);

            return ['job' => $job, 'customer' => $customer, 'customer_created' => $created];
        });

        return response()->json([
            'data' => [
                'lead' => $this->present($lead->fresh()->load(['company:id,name', 'franchise:id,name', 'job', 'noteBy:id,name'])),
                'customer' => $result['customer']->only(['id', 'number', 'name', 'phone', 'email']),
                'customer_created' => $result['customer_created'],
                'job' => $this->presentJob($result['job']),
            ],
        ], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function presentJob(Job $job): array
    {
        return [
            'id' => $job->id,
            'number' => $job->number,
            'title' => $job->title,
            'status' => $job->status,
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
        ];
    }

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->tenant->idOrFail());
    }

    /** @return array<string, mixed> */
    private function presentConversation(LeadConversation $c): array
    {
        return [
            'id' => $c->id,
            'channel' => $c->channel,
            'from' => $c->participant,
            'occurred_at' => $c->occurred_at?->toIso8601String(),
            'duration_ms' => $c->duration_ms,
            'text' => $c->text,
            'attachments' => $c->attachments ?? [],
            // Never the URL itself: it is fetched with the connection's
            // token through the recording endpoint.
            'has_recording' => $c->recording_url !== null,
        ];
    }

    /**
     * POST /api/v1/leads/{lead}/suggest-reply
     *
     * A draft, never a send. The text comes back for the composer; a person
     * reads it, changes it, and presses Send, or does not. Gated the same as
     * replying, because a draft for a lead you may not answer is noise.
     */
    public function suggestReply(Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        abort_unless(
            $this->replies->canReply($lead) && $this->insights->enabled(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'A suggested reply is not available for this enquiry.',
        );

        $draft = $this->insights->suggestReply($lead);

        abort_if($draft === null, Response::HTTP_BAD_GATEWAY, 'The model did not answer. Write the reply yourself.');

        return response()->json(['data' => ['text' => $draft]]);
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

        // Which door the text goes through is the source's business, not this
        // controller's: Google's Local Services, a website chat, and a form
        // that has no way back each answer for themselves.
        $channel = $this->replies->for($lead);

        abort_if(
            $channel === null || ! $channel->canReply($lead),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            // Same sentence the screen was already showing, from the same
            // place, rather than a second copy of one source's rules here.
            (string) $this->replies->whyNot($lead),
        );

        try {
            $row = $channel->send($lead, $text);
        } catch (RuntimeException $e) {
            // The provider refused, with a sentence written for the screen.
            // Only the provider can act on it, so 502.
            abort(Response::HTTP_BAD_GATEWAY, $e->getMessage());
        }

        $this->audit->log('lead.replied', $lead, [
            'conversation_id' => $row->id,
            'characters' => mb_strlen($text),
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
            'occurred_at' => $row->occurred_at?->toIso8601String(),
            'duration_ms' => $row->duration_ms,
        ], actor: $request->user());

        return response($audio->body(), Response::HTTP_OK, [
            'Content-Type' => $audio->header('Content-Type') ?: 'audio/mpeg',
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
            'status' => ['sometimes', 'string', Rule::in(Lead::STATUSES)],
            'starred' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        // Star and note are marks, not decisions: no event, one audit row
        // each, and blank clears rather than storing an empty string.
        if (array_key_exists('starred', $input)) {
            $lead->starred_at = $input['starred'] ? now() : null;
        }

        if (array_key_exists('note', $input)) {
            $note = trim((string) $input['note']) ?: null;

            if ($note !== $lead->note) {
                $lead->note = $note;
                $lead->note_by_user_id = $note === null ? null : $request->user()->getKey();
                $lead->note_updated_at = $note === null ? null : now();
            }
        }

        $statusChanges = array_key_exists('status', $input) && $input['status'] !== $lead->status;
        $before = $lead->status;

        if (! $statusChanges && ! $lead->isDirty()) {
            // The same load as the other return path. Without it, starring a
            // lead that already had a note answered with note_by null and the
            // byline vanished on screen until the next reload (UI/UX, 2026-09-08).
            return response()->json(['data' => $this->present($lead->load('noteBy:id,name'))]);
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
                    'after' => ['status' => $lead->status],
                ]);
            }
        });

        return response()->json(['data' => $this->present($lead->fresh()->load('noteBy:id,name'))]);
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

        $user = $request->user();
        $now = now();
        $count = 0;

        $ids = Lead::query()->select('id');

        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds = $user->reachableCompanies()->pluck('id')->all();

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
                    'because' => $e->getMessage(),
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
                'id' => $c->getKey(),
                'provider' => $c->provider,
                'display_name' => $c->display_name,
                // The provider's own id, for the one case display_name cannot
                // settle: twelve Thumbtack businesses all called "A1 Chimney"
                // (API Integrations, 2026-09-08). Google's digits are unique,
                // its names are unique; Thumbtack's names are not.
                'remote_id' => $c->remote_id,

                // What a person called it, or null. The screen shows this when
                // set and display_name when not — twenty-two numbers are not
                // something anybody remembers the meaning of.
                'label' => $c->label,
                'status' => $c->status,
                'leads_franchise_id' => $c->leads_franchise_id,
                'leads_franchise_name' => $c->leads_franchise_id === null
                    ? null
                    : $reachable->get($c->leads_franchise_id),
                'leads_count' => (int) ($counts[$c->getKey()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'source' => $lead->source,

            // A specimen from `ezbizi:thumbtack-test-lead`. It stays in the
            // list — seeing it is how somebody knows the pipe works — and the
            // row says what it is rather than reading as the first customer.
            'is_test' => (bool) $lead->is_test,
            'contact_name' => $lead->contact_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'lead_type' => $lead->lead_type,
            'category' => $lead->category,
            'service' => $lead->service,
            'locale' => $lead->locale,
            'summary' => $lead->summary,
            'status' => $lead->status,

            // What the lead LOOKS LIKE (BOOK / SUPPORT / ARCHIVE, read by a
            // model), beside what somebody DECIDED (status). Null until the
            // enquirer has said something in words, or while the model is off.
            'classification' => $lead->classification,
            'classified_at' => $lead->classified_at?->toIso8601String(),
            'starred' => $lead->starred_at !== null,
            'note' => $lead->note,
            'note_by' => $lead->note_by_user_id !== null && $lead->relationLoaded('noteBy')
                ? $lead->noteBy?->only(['id', 'name'])
                : null,
            'note_updated_at' => $lead->note_updated_at?->toIso8601String(),

            // Only known on the list, where the reader's mark was joined in.
            // Null on a single-lead read means "not asked", not "read".
            'is_unread' => ! array_key_exists('read_by_me_at', $lead->getAttributes())
                ? null
                : ($lead->read_by_me_at === null
                    || ($lead->last_contact_at ?? $lead->received_at) > Carbon::parse($lead->read_by_me_at)),
            'source_status' => $lead->source_status,
            'charge_status' => $lead->charge_status,
            'received_at' => $lead->received_at?->toIso8601String(),

            // The preview line: the newest call or message, who it was from,
            // and one line of it. `last_contact_from` = CONSUMER with nothing
            // after it is what "unreplied" means.
            'last_contact_at' => $lead->last_contact_at?->toIso8601String(),
            'last_contact_channel' => $lead->last_contact_channel,
            'last_contact_from' => $lead->last_contact_from,
            'last_contact_preview' => $lead->last_contact_preview,

            /*
             * Whether a reply is possible AT ALL, which is not whether one is
             * owed.
             *
             * The list needs it because "waiting for a reply" is meaningless on
             * a row nobody can reply to. Most Local Services enquiries are
             * CALLS, and a call has no reply box - marking one unreplied
             * describes a duty that does not exist and buries the text
             * enquiries that genuinely are waiting.
             *
             * Asked of the CHANNEL rather than recomputed here. LocalServices
             * answers on lead_type and connection, Thumbtack on connection,
             * is_test and remote_id, and a source with no channel answers
             * false. Restating any of that here would be a second copy that
             * goes quietly wrong the day a channel changes its mind - which is
             * the defect LeadReplies was extracted to end.
             *
             * No N+1: every rule reads columns already on the row.
             */
            'can_reply' => $this->replies->canReply($lead),
            'company' => $lead->company?->only(['id', 'name']),
            'franchise' => $lead->franchise?->only(['id', 'name']),

            // The job it became, once booked. Loaded lazily here: the list
            // is not the place this shows, the thread header is.
            'job' => $lead->job_id !== null && $lead->relationLoaded('job') && $lead->job !== null
                ? $this->presentJob($lead->job)
                : null,
        ];
    }
}
