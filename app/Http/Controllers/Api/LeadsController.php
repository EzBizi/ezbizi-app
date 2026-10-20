<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SyncLeads;
use App\Models\AdsAccountState;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\ServiceAreaZip;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WebBooking;
use App\Services\Leads\CallTranscripts;
use App\Services\Leads\LeadInsights;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Audio\PlayableAudio;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\AddressCheck;
use App\Support\Leads\LeadDetails;
use App\Support\Leads\LeadPresence;
use App\Support\Leads\LeadReplies;
use App\Support\Leads\RingCentralLeadCalls;
use App\Support\Access\Refusal;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scheduling\Openings;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ChatPeople;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /**
     * The leads this reader may see: their reach, the view they are in, a
     * source still connected, a source their role shows. Shared by the list
     * and the Leads dashboard (LeadDashboardController), so the two cannot
     * count different leads.
     */
    public static function scopeToReader(Builder $query, User $user): Builder
    {
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

        /*
         * THE SOURCES THIS PERSON WAS GRANTED - Jose, 2026-09-17: *"i should be
         * able to grant access by sub tab as well"*.
         *
         * Applied to EVERY request, including the one with no `source`
         * parameter. `All` is the request that would otherwise hand back the
         * rows the chips are hiding, and it is the default one the screen
         * makes.
         *
         * The narrowing below it still runs: asking for a source you may not
         * see returns nothing rather than erroring, because the two clauses
         * intersect. That is the right answer for a URL nobody should have
         * been able to build from the screen anyway.
         */
        $query->sourceVisibleTo($user);

        return $query;
    }

    /** GET /api/v1/leads */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();

        /*
         * Read is the LEAD's mark, shared by everyone (Jose, 2026-10-05); the
         * star stays the reader's own, on a left join. Unread means no mark,
         * or a mark older than the newest thing on the lead - a message after
         * somebody read it makes it unread again. `read_by_me_at` keeps its
         * name so present() and the client are unchanged.
         */
        $query = Lead::query()
            ->select('leads.*', 'leads.read_at as read_by_me_at', 'lead_reads.starred_at as starred_by_me_at')
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
        self::scopeToReader($query, $user);

        // Unread per source for the rail (UI/UX, queue 30): the reader's
        // leads before any source, status or search narrowing, keyed as the
        // strip keys them - Google split into ls_call / ls_text.
        $unreadBySource = (clone $query)
            ->whereRaw(self::UNREAD_SQL)
            ->reorder()
            ->select(DB::raw("CASE WHEN leads.status = 'job_applicant' THEN 'job_applicant' "
                ."WHEN leads.source = 'website_form' AND leads.lead_type = 'BAD_RATING' THEN 'bad_rating' WHEN leads.source = 'local_services' THEN "
                ."(CASE WHEN leads.lead_type = 'PHONE_CALL' THEN 'ls_call' WHEN leads.lead_type = 'BOOKING' THEN 'ls_booking' ELSE 'ls_text' END) "
                .'ELSE leads.source END AS kind'))
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('kind')
            ->toBase()
            ->pluck('n', 'kind')
            ->map(fn ($n) => (int) $n);

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
            /*
             * AND ONLY AN INBOX SOURCE - added with Workiz, 2026-09-23.
             *
             * The null clause below is what makes this filter honest for a
             * Google enquiry whose conversation has not been fetched yet. It is
             * also what would have made it useless the day A-1's Workiz book
             * arrived: a Workiz lead is a record copied from another system
             * with no thread and no future thread, so its `last_contact_from`
             * is null permanently rather than temporarily, and all thirty-one
             * thousand would sit in this queue for ever.
             *
             * The first version of this scoped to sources a reply can be SENT
             * on, which is a different question and the wrong one - see
             * Lead::INBOX_SOURCES. It would have dropped every website form
             * from the queue, and a form is precisely a person waiting for a
             * call back.
             */
            $query->whereIn('leads.source', Lead::INBOX_SOURCES)
                // Calls and Google bookings owe no reply (Jose, 2026-10-15: "non actionable").
                ->whereNotIn('leads.source', [Lead::SOURCE_RINGCENTRAL, Lead::SOURCE_EZ_CALLS])
                ->where(fn ($q) => $q->where('leads.source', '!=', Lead::SOURCE_LOCAL_SERVICES)
                    ->orWhereNotIn('leads.lead_type', ['PHONE_CALL', 'BOOKING'])
                    ->orWhereNull('leads.lead_type'))
                // Completed: the customer is owed nothing (Jose, 2026-10-01).
                ->whereNotIn('leads.status', [Lead::STATUS_COMPLETED, Lead::STATUS_LOST])
                ->where(function ($q) {
                    $q->where('leads.last_contact_from', LeadConversation::FROM_CONSUMER)
                        ->orWhereNull('leads.last_contact_from');
                });
        }

        // The red ones only (Jose, 2026-10-08): Lead::replyOverdue() in SQL.
        // canReply() is the channel's, so its two column rules are restated:
        // a Google lead must be a text, and a connected source must still be.
        if ($request->boolean('overdue')) {
            $query->where('leads.status', Lead::STATUS_NEW)
                ->whereNotIn('leads.source', Lead::SOURCES_NO_REPLY_OWED)
                ->where('leads.last_contact_from', LeadConversation::FROM_CONSUMER)
                ->where('leads.last_contact_at', '<=', now()->subMinutes(Lead::REPLY_OVERDUE_AFTER_MINUTES))
                ->where(fn ($q) => $q->where('leads.source', '!=', Lead::SOURCE_LOCAL_SERVICES)
                    ->orWhere('leads.lead_type', 'MESSAGE'))
                ->where(fn ($q) => $q->whereNotIn('leads.source', Lead::SOURCES_VIA_CONNECTION)
                    ->orWhereNotNull('leads.connection_id'))
                // A form: until a note says somebody called back (Lead::awaitsCallback).
                ->where(fn ($q) => $q->where('leads.source', '!=', Lead::SOURCE_WEBSITE_FORM)
                    ->orWhereNull('leads.note_updated_at')
                    ->orWhereColumn('leads.note_updated_at', '<', 'leads.last_contact_at'));
        }

        // Asked the price or a day on a call, nothing booked (Jose, 2026-10-12,
        // AI item 6): Grok's call signals, until a job is matched or somebody
        // marks it handled.
        if ($request->boolean('follow_up')) {
            $query->whereNull('leads.job_id')
                ->whereNull('leads.follow_up_handled_at')
                ->where('leads.status', Lead::STATUS_NEW)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('lead_conversations')
                    ->whereColumn('lead_conversations.lead_id', 'leads.id')
                    // ponytail: unquoted text compare, the one JSON boolean test
                    // MySQL and MariaDB agree on.
                    ->where(fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(lead_conversations.call_signals, '$.asked_price')) = 'true'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(lead_conversations.call_signals, '$.asked_availability')) = 'true'"))
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(lead_conversations.call_signals, '$.booked')) = 'false'"));
        }

        if ($request->boolean('unread')) {
            $query->whereRaw(self::UNREAD_SQL);
        }

        // A1 Leads' filters (takeover, Jose 2026-10-16): Google's charge
        // status, the RingCentral line the call came in on, who answered, and
        // the day it arrived in the organization's own time.
        if (in_array($charge = $request->query('charge'), ['CHARGED', 'CREDITED', 'IN_REVIEW', 'NOT_CHARGED'], true)) {
            $query->where('leads.charge_status', $charge);
        }
        if (is_string($ivr = $request->query('ivr')) && $ivr !== '') {
            $query->where('leads.source', Lead::SOURCE_RINGCENTRAL);
            match ($ivr) {
                'Existing Customer' => $query->where('leads.source_status', RingCentralLeadCalls::EXISTING_CLIENT),
                'LS Ads', 'GMB', 'SEO', 'PPC' => $query->where('leads.category', 'like', '% - '.$ivr.'%'),
                default => $query->where('leads.category', 'like', str_replace(['%', '_'], ['\%', '\_'], $ivr).'%'),
            };
        }
        if (is_string($dispatcher = $request->query('dispatcher')) && $dispatcher !== '') {
            $query->where('leads.dispatcher', $dispatcher);
        }
        $zone = (string) app(SettingsResolver::class)->get('timezone', $user->organization);
        foreach (['from' => 'startOfDay', 'to' => 'endOfDay'] as $key => $edge) {
            if (is_string($day = $request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $query->where('leads.received_at', $key === 'from' ? '>=' : '<=', Carbon::parse($day, $zone)->{$edge}()->utc());
            }
        }

        if ($request->boolean('starred')) {
            // The reader's own stars (Jose, 2026-09-30: stars are personal).
            $query->whereNotNull('lead_reads.starred_at');
        }

        // Source is the fourth filter, beside the three it narrows with (UI/UX
        // sources layout 1, 2026-09-08): one list answers "who is waiting on
        // me" across Google, Thumbtack and the website at once.
        if (is_string($source = $request->query('source')) && $source !== '') {
            // Google, split the way A1 Leads splits it (Jose, 2026-09-28):
            // "LS Call" and "LS Text" are Local Services leads by lead_type.
            $query->where(fn ($q) => self::whereKind($q, $source));
        }

        // Job applicants live only under their own tab (Jose, 2026-10-09).
        $askedForApplicants = $source === 'job_applicant'
            || in_array('job_applicant', explode(',', (string) $request->query('sources', '')), true)
            || $request->query('status') === Lead::STATUS_JOB_APPLICANT;
        if (! $askedForApplicants) {
            $query->where('leads.status', '!=', Lead::STATUS_JOB_APPLICANT);
        }

        // All, narrowed to the sources ticked under it (Jose, 2026-09-29: "by
        // default all of them active except for LS Call as those are not
        // actionable"). Comma-separated keys; one nobody has matches nothing.
        if (is_string($sources = $request->query('sources')) && $sources !== '') {
            $query->where(function ($q) use ($sources) {
                foreach (array_filter(explode(',', $sources)) as $key) {
                    $q->orWhere(fn ($w) => self::whereKind($w, $key));
                }
            });
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

                // Anything said in the thread, calls' transcripts included -
                // A1 Leads searched message text (Jose, 2026-09-30). Three
                // characters, or every lead matches "a".
                // ponytail: LIKE over lead_conversations; a FULLTEXT index if it gets slow.
                if (mb_strlen($search) >= 3) {
                    $q->orWhereIn('leads.id', DB::table('lead_conversations')
                        ->select('lead_id')
                        ->where(fn ($w) => $w->where('text', 'like', '%'.$search.'%')
                            ->orWhere('transcript', 'like', '%'.$search.'%')));
                }
            });
        }

        // Up to 500: the list grows ten at a time as it is scrolled, and its
        // refresh asks for every row it holds (Jose, 2026-10-01).
        $leads = $query->paginate(min((int) $request->query('per_page', 50), 500));

        // Which of these numbers already belong to a customer - one query for
        // the page (8 ms on production's 7,869 customers), so a known caller
        // is shown by name. Jose, 2026-09-28.
        $customers = $user->hasPermission('customers.view')
            ? $this->customersByNumber(array_map(fn (Lead $l) => $l->phone_digits, $leads->items()))
            : [];

        $viewers = LeadPresence::others(array_map(fn (Lead $l) => (string) $l->id, $leads->items()), $user);

        return response()->json([
            'data' => array_map(fn (Lead $l) => [
                ...$this->present($l),
                // Who else has it open right now (Jose, 2026-09-29).
                'viewers' => $viewers[(string) $l->id] ?? [],
                'customer' => ($c = $customers[self::tenDigits($l->phone_digits)] ?? null) === null
                    ? null
                    : ['id' => $c->id, 'number' => $c->number, 'name' => $c->name],
            ], $leads->items()),
            'meta' => [
                ...$this->pageMeta($leads),
                'connections' => $this->connectionState(),
                'unread_by_source' => (object) $unreadBySource->all(),
                // The dispatcher filter's choices: who answered this reader's leads.
                'dispatchers' => self::scopeToReader(Lead::query(), $user)->whereNotNull('leads.dispatcher')
                    ->distinct()->orderBy('leads.dispatcher')->limit(200)->pluck('leads.dispatcher')->all(),
                /*
                 * "Google is refusing, so enquiries are late" - or null.
                 *
                 * The Leads screen re-reads itself every twenty seconds now, so
                 * a stalled sync looks exactly like a quiet afternoon: the same
                 * list, refreshed, over and over. On 2026-09-16 that quiet
                 * afternoon was four hours of enquiries not arriving, and the
                 * only place it was written down was a log.
                 *
                 * SyncLeads sets the key when it abandons a run on quota; it
                 * expires on its own. Read rather than derived here: the
                 * controller cannot ask Google without spending the quota that
                 * is already gone.
                 */
                'sync_delayed_since' => Cache::get(SyncLeads::STALLED_KEY),
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
        // Who sent each of our replies, from the audit every reply writes -
        // shown as initials on the bubble, as A1 Tools does (Jose,
        // 2026-09-28). Replies sent elsewhere (A1 Tools, Google) have no name.
        $senders = AuditEvent::query()
            ->where('action', 'lead.replied')
            ->where('target_id', $lead->getKey())
            ->get(['changes', 'actor_label'])
            ->mapWithKeys(fn (AuditEvent $e) => [
                (string) ($e->changes['conversation_id'] ?? '') => trim((string) preg_replace('/\s*<[^>]*>$/', '', (string) $e->actor_label)),
            ]);

        // The day and window a website booking asked for, and where it stands (Jose, 2026-10-08).
        $booking = WebBooking::query()->where('lead_id', $lead->getKey())->first();
        $data['booking'] = $booking === null ? null : $this->presentBooking($booking);

        $conversations = $lead->conversations;
        // A website visitor typing right now, shown under the thread (Jose, 2026-10-01).
        $data['visitor_typing'] = ChatPeople::isVisitorTyping((string) $lead->getKey());
        $data['conversations'] = $conversations
            ->map(fn (LeadConversation $c) => $this->presentConversation($c, $senders[$c->id] ?? null))
            ->values()
            ->all();

        // What A1 Tools shows beside a lead: which Google Ads account, Google's
        // own status, and a name, address and ZIP read out of what the customer
        // wrote - by pattern, not a model (LeadDetails).
        $data['details'] = [
            ...LeadDetails::from($conversations
                ->where('participant', LeadConversation::FROM_CONSUMER)
                ->where('channel', '!=', LeadConversation::CHANNEL_PHONE_CALL)
                ->pluck('text')->filter()->values()->all()),
            'account' => $lead->connection === null ? null : ($lead->connection->label ?: $lead->connection->display_name),
            // The office the account serves from: its Business Profile listing (AccountProfileLink).
            'office' => ($loc = $lead->connection?->businessLocation) === null ? null
                : trim(implode(', ', array_filter([$loc->address_line1, $loc->city, $loc->state]))),
            'google_status' => $lead->source_status === null ? null : ucfirst(strtolower(str_replace('_', ' ', $lead->source_status))),
        ];

        // The ZIP the enquirer typed into a website chat, when no message
        // named one: the words they wrote still win (Leads, 2026-10-01).
        $data['details']['zip'] ??= $lead->postal_code;

        // The address as Google knows it, with its ZIP - Rodrigo's USPS
        // check, done with Google (AddressCheck). Null when unknown.
        $checked = $data['details']['address'] !== null
            ? AddressCheck::check($data['details']['address'], $data['details']['zip'])
            : null;
        $data['details']['checked_address'] = $checked;
        $data['details']['zip'] ??= $checked['zip'] ?? null;

        // Whether a reply can go anywhere, and when it cannot, WHY — in the
        // refusing channel's own words. The screen used to carry one sentence
        // for every source, and it named Google; the first Thumbtack enquiry
        // was told it had come in as a call on an account Google has never
        // heard of.
        $data['can_reply'] = $this->replies->canReply($lead);
        $data['cannot_reply_because'] = $this->replies->whyNot($lead);

        // Canned replies beside Send, filled in for this customer and brand.
        // {{missing}} asks only for what we do not already have (Jose,
        // 2026-09-30: a customer who wrote his name and address in his first
        // message was asked for both again).
        $first = Lead::firstNameOf($lead->contact_name);
        $missing = array_values(array_filter([
            trim((string) $lead->contact_name) === '' && ($data['details']['name'] ?? null) === null ? 'full name' : null,
            ($data['details']['address'] ?? null) === null ? 'full service address' : null,
            trim((string) $lead->email) === '' ? 'email' : null,
        ]));
        $missingText = match (count($missing)) {
            0 => 'confirmation that the details above are right',
            1 => $missing[0],
            default => implode(', ', array_slice($missing, 0, -1)).' and '.end($missing),
        };
        $data['reply_templates'] = ! $data['can_reply'] ? [] : collect(
            app(SettingsResolver::class)->get('leads.reply_templates', $lead->company ?? request()->user()->organization)
        )->map(fn ($t) => [
            'name' => (string) ($t['label'] ?? $t),
            'text' => strtr((string) ($t['value'] ?? $t), [
                '{{greeting}}' => $first === null ? 'Hi there' : "Hi {$first}",
                '{{company}}' => (string) ($lead->company?->name ?? request()->user()->organization->name),
                '{{missing}}' => $missingText,
                '{{availability}}' => str_contains((string) ($t['value'] ?? ''), '{{availability}}')
                    ? $this->availabilityFor($data['details']['zip'] ?? null)
                    : '',
            ]),
        ])->values()->all();

        // A draft is offered only where a reply can go and the model is on.
        $data['can_suggest'] = $data['can_reply'] && $this->insights->enabled();

        // Customers this number already belongs to, so booking can attach to
        // one instead of making a second Jane Doe. Same digits-only match the
        // Customers screen uses for its duplicate warning; advisory only.
        // Reading the lead says I have it open: the thread re-reads it on its
        // own timer, so this is the check-in (LeadPresence).
        LeadPresence::checkIn((string) $lead->id, request()->user());
        $data['viewers'] = LeadPresence::others([(string) $lead->id], request()->user())[(string) $lead->id] ?? [];
        // Somebody else is writing to this customer right now (Jose, 2026-10-20).
        $data['replying'] = LeadPresence::replyingOther((string) $lead->id, request()->user());
        // The server's clock for "what you have seen": sent back with a reply (seen_at).
        $data['seen_at'] = now()->toIso8601String();

        $matches = $this->customerMatches($lead);
        $data['customer_matches'] = $matches
            ->map(fn (Customer $c) => ['id' => $c->id, 'number' => $c->number, 'name' => $c->name, 'phone' => $c->phone])
            ->values()
            ->all();

        // The customer this number already is, with what they have had done -
        // so whoever answers knows who is asking before they ask.
        $customer = $matches->first();
        $data['customer'] = $customer !== null && request()->user()?->can('view', $customer)
            ? $this->customerHistory($customer)
            : null;

        return response()->json(['data' => $data]);
    }

    /**
     * A known customer, and their jobs the reader may see: newest five, the
     * count, and what the finished ones came to (cancelled ones left out).
     *
     * @return array<string, mixed>
     */
    private function customerHistory(Customer $customer): array
    {
        $user = request()->user();
        $jobs = $user->hasPermission('jobs.view')
            ? $customer->jobs()->get()->filter(fn (Job $j) => $user->can('view', $j))
            : collect();
        $when = fn (Job $j) => $j->scheduled_for ?? $j->earned_at ?? $j->created_at;
        $kept = $jobs->reject(fn (Job $j) => in_array(strtolower((string) $j->status), ['canceled', 'cancelled'], true));

        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => implode(', ', array_filter([$customer->address_line1, $customer->city, $customer->state])) ?: null,
            'jobs_total' => $jobs->count(),
            'revenue_minor' => (int) $kept->sum('amount_minor'),
            'currency' => $kept->first()?->currency,
            'jobs' => $jobs->sortByDesc(fn (Job $j) => $when($j)?->getTimestamp() ?? 0)->take(5)
                ->map(fn (Job $j) => [
                    'id' => $j->id,
                    'number' => $j->number,
                    'title' => $j->title,
                    'status' => $j->status,
                    'when' => $when($j)?->toIso8601String(),
                    'amount_minor' => $j->amount_minor,
                    'currency' => $j->currency,
                ])->values()->all(),
        ];
    }

    /**
     * One source key as the list narrows by it. Google splits as A1 Leads
     * splits it: LS Call is the calls, LS Text everything else Google sends
     * (texts and bookings), so ticking every chip reaches every lead.
     */
    private static function whereKind($query, string $key): void
    {
        match ($key) {
            Lead::KIND_LS_CALL => $query->where('leads.source', Lead::SOURCE_LOCAL_SERVICES)
                ->where('leads.lead_type', 'PHONE_CALL'),
            Lead::KIND_LS_TEXT => $query->where('leads.source', Lead::SOURCE_LOCAL_SERVICES)
                ->where(fn ($t) => $t->whereNull('leads.lead_type')->orWhereNotIn('leads.lead_type', ['PHONE_CALL', 'BOOKING'])),
            Lead::KIND_LS_BOOKING => $query->where('leads.source', Lead::SOURCE_LOCAL_SERVICES)
                ->where('leads.lead_type', 'BOOKING'),
            Lead::STATUS_JOB_APPLICANT => $query->where('leads.status', Lead::STATUS_JOB_APPLICANT),
            Lead::KIND_BAD_RATING => $query->where('leads.source', Lead::SOURCE_WEBSITE_FORM)
                ->where('leads.lead_type', 'BAD_RATING'),
            // Forms, without the Bad Ratings that have their own tab now.
            Lead::SOURCE_WEBSITE_FORM => $query->where('leads.source', Lead::SOURCE_WEBSITE_FORM)
                ->where(fn ($q) => $q->whereNull('leads.lead_type')->orWhere('leads.lead_type', '!=', 'BAD_RATING')),
            default => $query->where('leads.source', $key),
        };
    }

    /** The last ten digits, which decide between +1 (216) 555-0100 and 2165550100. */
    private static function tenDigits(?string $digits): ?string
    {
        return $digits === null || strlen($digits) < 10 ? null : substr($digits, -10);
    }

    /**
     * Customers by the last ten digits of their phone, for many numbers in one
     * query. Where two customers share a number the first by name wins, as in
     * customerMatches().
     *
     * @param  list<?string>  $digits
     * @return array<string, Customer>
     */
    private function customersByNumber(array $digits): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(self::tenDigits(...), $digits))));
        if ($numbers === []) {
            return [];
        }

        $expr = "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10)";

        return Customer::query()
            ->whereRaw("{$expr} IN (".implode(',', array_fill(0, count($numbers), '?')).')', $numbers)
            ->orderBy('name')
            ->get()
            ->reduce(function (array $by, Customer $c) {
                $by[self::tenDigits(preg_replace('/\D/', '', (string) $c->phone))] ??= $c;

                return $by;
            }, []);
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
     * POST /api/v1/leads/{lead}/booking/{approve|decline}
     *
     * A dispatcher's answer to a website booking (Leads ▸ Online Booking).
     * Approved keeps the window's seat until the lead has a job; declined
     * gives it back at once. Either way the customer is called, not texted:
     * nothing here sends them anything.
     */
    public function decideBooking(Request $request, Lead $lead, string $decision): JsonResponse
    {
        $this->authorize('update', $lead);

        $booking = WebBooking::query()->where('lead_id', $lead->getKey())->firstOrFail();
        $wasApproved = $booking->status === WebBooking::APPROVED;

        $booking->forceFill([
            'status' => $decision === 'approve' ? WebBooking::APPROVED : WebBooking::DECLINED,
            'decided_by' => $request->user()->getKey(),
            'decided_at' => now(),
        ])->save();

        // The confirmation to the customer (Jose, 2026-10-13: "an automation
        // that sends the confirmation to the client ... plus the number to call
        // if they need to reschedule"). Once: approving twice is not news.
        if ($decision === 'approve' && ! $wasApproved) {
            $this->events->emit('lead.booking_approved', $lead, ['after' => [
                'booking_when' => self::bookingWhen($booking),
                'booking_date' => $booking->date->toDateString(),
                'booking_day' => $booking->date->format('l, M j'),
                'booking_window' => self::bookingWindow($booking),
                'branch_name' => (string) ($lead->franchise?->name ?? ''),
                'office_phone' => $lead->office_phone,
                'contact_name' => $lead->contact_name,
                'phone' => $lead->phone,
            ]]);
        }

        return response()->json(['data' => $this->presentBooking($booking)]);
    }

    /** "Thursday, October 16, between 8:00 AM and 11:00 AM" */
    public static function bookingWhen(WebBooking $booking): string
    {
        $at = fn (string $t) => \Carbon\CarbonImmutable::parse($booking->date->toDateString().' '.$t)->format('g:i A');

        return $booking->date->format('l, F j').', between '.$at((string) $booking->start_time).' and '.$at((string) $booking->end_time);
    }

    /** "8:00 AM - 11:00 AM" */
    public static function bookingWindow(WebBooking $booking): string
    {
        $at = fn (string $t) => \Carbon\CarbonImmutable::parse($booking->date->toDateString().' '.$t)->format('g:i A');

        return $at((string) $booking->start_time).' - '.$at((string) $booking->end_time);
    }

    /** @return array<string, mixed> */
    private function presentBooking(WebBooking $booking): array
    {
        return [
            'date' => $booking->date->toDateString(),
            'start_time' => substr((string) $booking->start_time, 0, 5),
            'end_time' => substr((string) $booking->end_time, 0, 5),
            'status' => $booking->status,
            'decided_at' => $booking->decided_at?->toIso8601String(),
            'decided_by' => $booking->decided_by === null ? null : User::query()->whereKey($booking->decided_by)->value('name'),
            'fit' => $booking->status === WebBooking::PENDING ? self::bookingFit($booking) : null,
        ];
    }

    /**
     * How the asked-for window sits beside work already booked, for the
     * dispatcher only (Jose, 2026-10-13: "the client does not need to see the
     * recommendation"): Scheduling's NearbySuggestion for the lead's ZIP. Null
     * when there is no ZIP, no area or nothing booked nearby.
     *
     * @return array{verdict: string, date: string, start_time: string, end_time: string, nearby: int, nearest_miles: ?float}|null
     */
    private static function bookingFit(WebBooking $booking): ?array
    {
        $zip = substr(preg_replace('/\D/', '', (string) $booking->lead?->postal_code) ?? '', 0, 5);
        $area = $booking->serviceArea;
        if (strlen($zip) !== 5 || $area === null) {
            return null;
        }

        $tz = (string) app(SettingsResolver::class)->get('timezone', $booking->lead?->organization);
        // Scheduling's own scoring (2799bf9a): the chosen window, and a
        // strictly better one only when there is one.
        $fit = app(\App\Support\Scheduling\NearbySuggestion::class)->fit($area, $zip, $booking->date->toDateString(),
            substr((string) $booking->start_time, 0, 5), \App\Support\Scheduling\Openings::tomorrow($tz));
        $better = $fit['better'] ?? null;
        $shown = $better ?? $fit['chosen'];
        $miles = $shown['nearest_miles'] ?? null;

        return [
            // "better": another window sits beside more work; "good": this one
            // is beside booked work; "alone": nothing booked nearby that day.
            'verdict' => $better !== null ? 'better' : ((int) $fit['chosen']['nearby'] > 0 ? 'good' : 'alone'),
            'date' => $shown['date'],
            'start_time' => $better['window']['start_time'] ?? $shown['start_time'],
            'end_time' => $better['window']['end_time'] ?? substr((string) $booking->end_time, 0, 5),
            'nearby' => (int) $shown['nearby'],
            'nearest_miles' => is_numeric($miles) ? round((float) $miles, 1) : null,
        ];
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
    private function presentConversation(LeadConversation $c, ?string $sentBy = null): array
    {
        // Our RingCentral call out: the text holds who made it ("We called" on
        // the card with the staff name), and "No answer" when nobody picked up.
        if ((str_starts_with((string) $c->remote_id, 'rc_') || str_starts_with((string) $c->remote_id, \App\Support\Leads\EzLeadCalls::CALL))
            && $c->channel === LeadConversation::CHANNEL_PHONE_CALL && $c->participant === LeadConversation::FROM_ADVERTISER) {
            $sentBy ??= $c->text;
            $c = (clone $c)->forceFill(['text' => $c->duration_ms === null ? 'No answer' : null]);
        }

        return [
            'id' => $c->id,
            // The person who sent it from EzBizi, or null. And whether a rule
            // sent it rather than a person, for the bot mark.
            'sent_by' => $sentBy !== '' ? $sentBy : null,
            'automatic' => (bool) $c->is_automatic,
            'reaction' => self::reaction($c->text),
            'channel' => $c->channel,
            'from' => $c->participant,
            'occurred_at' => $c->occurred_at?->toIso8601String(),
            'duration_ms' => $c->duration_ms,
            // Our number on a call and its line (Jose, 2026-10-20).
            'line_number' => $c->line_number,
            'line_name' => $c->line_name,
            'text' => $c->text,
            // A call's words, machine-transcribed from the recording, divided
            // by speaker once Grok has (ezbizi:label-call-transcripts).
            'transcript' => $c->transcript_labelled ?? $c->transcript,
            // Grok's one-line read of the call, shown above the transcript
            // with the AI mark (ezbizi:label-call-transcripts).
            'summary' => $c->call_summary,
            'next_step' => $c->call_next_step,
            'signals' => $c->call_signals,
            // Waiting its turn with the transcriber (ezbizi-transcribe.timer):
            // an answered, recorded customer call from the last week, not done.
            'transcript_pending' => $c->transcript === null && $c->transcribed_at === null
                && $c->channel === LeadConversation::CHANNEL_PHONE_CALL
                && $c->participant === LeadConversation::FROM_CONSUMER
                && $c->recording_url !== null && $c->duration_ms !== null
                && $c->occurred_at?->gte(now()->subDays(7)) === true
                && app(CallTranscripts::class)->enabled(),
            'attachments' => $c->attachments ?? [],
            // Never the URL itself: it is fetched with the connection's
            // token through the recording endpoint.
            'has_recording' => $c->recording_url !== null,
        ];
    }

    /**
     * An iPhone reaction, which reaches Google as a text of its own: `Liked
     * "..."`, `Loved "..."`. 42 on production by 2026-09-28. The screen hangs
     * it on the message it quotes, WhatsApp-style, instead of a bubble.
     *
     * @return array{emoji: string, quote: string}|null
     */
    private static function reaction(?string $text): ?array
    {
        if ($text === null || ! preg_match('/^(Liked|Loved|Laughed at|Emphasized|Disliked|Questioned|Reacted (\S+) to)\s+[\x{201C}"](.*)[\x{201D}"]\s*$/us', trim($text), $m)) {
            return null;
        }

        $emoji = match ($m[1]) {
            'Liked' => "\u{1F44D}",
            'Loved' => "\u{2764}\u{FE0F}",
            'Laughed at' => "\u{1F602}",
            'Emphasized' => "\u{203C}\u{FE0F}",
            'Disliked' => "\u{1F44E}",
            'Questioned' => "\u{2753}",
            default => $m[2],
        };

        return ['emoji' => $emoji, 'quote' => rtrim($m[3], " \u{2026}.")];
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
    /** POST /leads/{lead}/typing — a dispatcher is typing; the website chat shows "Lenin is typing…". */
    public function typing(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);
        ChatPeople::agentTyping((string) $lead->getKey(), $request->user());
        LeadPresence::replying((string) $lead->getKey(), $request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * Ask Google to credit a Local Services lead (Jose, 2026-10-13). AI only
     * suggests the reason; a person presses the button, because it is a claim
     * made to Google in the business's name.
     */
    public function requestCredit(Request $request, Lead $lead, \App\Services\Leads\LocalServicesLeads $google): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            'reason' => ['required', 'string', \Illuminate\Validation\Rule::in(['SPAM', 'SOLICITATION', 'JOB_TYPE_MISMATCH', 'GEO_MISMATCH', 'DUPLICATE', 'NOT_READY_TO_BOOK', 'OTHER_DISSATISFIED_REASON'])],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:reason,OTHER_DISSATISFIED_REASON'],
        ]);

        abort_unless($lead->source === Lead::SOURCE_LOCAL_SERVICES, Response::HTTP_UNPROCESSABLE_ENTITY, 'Only Google Local Services leads can be credited.');
        abort_if($lead->credit_requested_at !== null, Response::HTTP_CONFLICT, 'A credit was already requested for this lead.');

        try {
            $decision = $google->requestCredit($lead, $input['reason'], (string) ($input['comment'] ?? ''));
        } catch (RuntimeException $e) {
            abort(Response::HTTP_BAD_GATEWAY, $e->getMessage());
        }

        $lead->forceFill([
            'credit_reason' => $input['reason'],
            'credit_requested_at' => now(),
            'credit_decision' => mb_substr($decision, 0, 40),
        ])->save();

        $this->audit->log('lead.credit_requested', $lead, [
            'reason' => $input['reason'],
            'decision' => $decision,
        ], actor: $request->user());

        return response()->json(['data' => $this->present($lead->refresh())]);
    }

    public function reply(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            // When this person last saw the thread; a staff reply after it
            // stops the send until they say so (`force`).
            'seen_at' => ['nullable', 'date'],
            'force' => ['nullable', 'boolean'],
        ]);

        $text = trim($input['text']);

        abort_if($text === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Write something to send.');

        /*
         * Two people answering one customer at once (Jose, 2026-10-20: Ara and
         * Tiara sent four messages in under a minute). Checked here, not only on
         * screen, so it holds for two people who never saw each other.
         */
        if (! ($input['force'] ?? false) && ($input['seen_at'] ?? null) !== null) {
            $other = $this->replyAfter($lead, Carbon::parse($input['seen_at']), $request->user());
            if ($other !== null) {
                return response()->json([
                    'message' => "{$other['by']} just replied: {$other['text']}. Send anyway?",
                    'conflict' => $other,
                ], Response::HTTP_CONFLICT);
            }
        }

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
        LeadPresence::stopReplying((string) $lead->getKey(), $request->user());

        // The first person to answer is whose lead it is, on the board too,
        // unless somebody already said who (dispatcher_by_user_id).
        Lead::query()->whereKey($lead->getKey())->whereNull('dispatcher')->whereNull('dispatcher_by_user_id')
            ->toBase()->update(['dispatcher' => mb_substr((string) $request->user()->name, 0, 120)]);

        return response()->json(['data' => $this->presentConversation($row)], Response::HTTP_CREATED);
    }

    /**
     * The newest reply a person (not a rule) sent this customer after $since,
     * by somebody other than $me: who and its first line, or null.
     *
     * @return array{by: string, text: string, at: ?string}|null
     */
    private function replyAfter(Lead $lead, \Carbon\CarbonInterface $since, User $me): ?array
    {
        $rows = LeadConversation::query()->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_ADVERTISER)->where('is_automatic', false)
            ->where('created_at', '>', $since)->orderByDesc('created_at')->get(['id', 'text', 'occurred_at']);
        foreach ($rows as $row) {
            $sent = AuditEvent::query()->where('action', 'lead.replied')->where('target_id', $lead->getKey())
                ->where('changes->conversation_id', (string) $row->id)->first(['actor_id', 'actor_label']);
            if ($sent !== null && (string) $sent->actor_id === (string) $me->getKey()) {
                continue;   // their own earlier message
            }
            $by = $sent === null ? 'Someone' : (trim((string) preg_replace('/\s*<[^>]*>$/', '', (string) $sent->actor_label)) ?: 'Someone');

            return ['by' => $by, 'text' => mb_substr(strtok((string) $row->text, "\n") ?: '', 0, 120), 'at' => $row->occurred_at?->toIso8601String()];
        }

        return null;
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

        // An EZ Call: the audio through Phone's Twilio gateway, never a Twilio URL.
        if (str_starts_with((string) $row->recording_url, 'twilio:')) {
            $call = \App\Models\PhoneCall::query()->find(substr((string) $row->recording_url, strlen('twilio:')));
            abort_if($call === null || $call->recording_sid === null || $call->connection === null, Response::HTTP_NOT_FOUND);
            try {
                $body = app(\App\Support\Phone\TwilioGateway::class)->recordingMedia($call->connection, (string) $call->recording_sid);
            } catch (\Throwable $e) {
                abort(Response::HTTP_BAD_GATEWAY, 'Twilio did not give the recording.');
            }
            $this->audit->log('lead.recording_played', $lead, [
                'conversation_id' => $row->id,
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'duration_ms' => $row->duration_ms,
            ], actor: $request->user());

            return response($body, 200, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, no-store']);
        }

        if (str_starts_with((string) $row->recording_url, 'ringcentral:')) {
            try {
                $audio = app(\App\Support\Reports\Experimental\RingCentralCallLog::class)
                    ->recording(substr((string) $row->recording_url, strlen('ringcentral:')));
            } catch (\RuntimeException $e) {
                abort(Response::HTTP_BAD_GATEWAY, 'RingCentral did not give the recording: '.$e->getMessage());
            }
            $this->audit->log('lead.recording_played', $lead, [
                'conversation_id' => $row->id,
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'duration_ms' => $row->duration_ms,
            ], actor: $request->user());

            return response($audio['body'], 200, ['Content-Type' => $audio['type'], 'Cache-Control' => 'private, no-store']);
        }

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

        // Google sends MPEG-2.5, which the Windows app cannot decode; it gets
        // a WAV every player opens. See PlayableAudio.
        [$body, $type] = PlayableAudio::forEveryPlayer($audio->body(), $audio->header('Content-Type') ?: 'audio/mpeg');

        return response($body, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * PATCH /api/v1/leads/{lead}
     *
     * Status, and the lead's labels a person may correct in place (A1 Leads
     * takeover, Jose 2026-10-16): the name, the ZIP, the pile (BOOK / SUPPORT
     * / ARCHIVE) and who handled it. Never the phone or what was said: the
     * conversations stay the evidence, and a charge dispute reads those.
     */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $input = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Lead::STATUSES)],
            'starred' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'classification' => ['sometimes', 'nullable', 'string', Rule::in(['BOOK', 'SUPPORT', 'ARCHIVE'])],
            'dispatcher' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        foreach (['contact_name', 'postal_code', 'classification'] as $field) {
            if (array_key_exists($field, $input)) {
                $lead->{$field} = trim((string) $input[$field]) ?: null;
            }
        }
        if (array_key_exists('classification', $input) && $lead->isDirty('classification')) {
            $lead->classified_at = now();
        }
        // A person's word on who handled it; RingCentral never overwrites it.
        // Blank hands it back to RingCentral.
        if (array_key_exists('dispatcher', $input)) {
            abort_unless($request->user()->hasPermission('leads.dispatcher'), Response::HTTP_FORBIDDEN,
                Refusal::permission('change who handled a call')->message());
            $lead->dispatcher = trim((string) $input['dispatcher']) ?: null;
            $lead->dispatcher_by_user_id = $lead->dispatcher === null ? null : $request->user()->getKey();
        }

        // Star and note are marks, not decisions: no event, one audit row
        // each, and blank clears rather than storing an empty string.
        // A star is the reader's own, kept beside their read mark.
        if (array_key_exists('starred', $input)) {
            DB::table('lead_reads')->upsert(
                [['lead_id' => $lead->getKey(), 'user_id' => $request->user()->getKey(), 'starred_at' => $input['starred'] ? now() : null]],
                ['lead_id', 'user_id'],
                ['starred_at'],
            );
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
     * Somebody has seen this lead as it stands now, so it is read for
     * everyone. A later message makes it unread again.
     */
    public function read(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        // The query builder, so updated_at does not move for a glance.
        DB::table('leads')->where('id', $lead->getKey())->update(['read_at' => now()]);

        return response()->json(['data' => ['id' => $lead->getKey(), 'is_unread' => false]]);
    }

    /**
     * POST /api/v1/leads/{lead}/reclassify
     *
     * Grok reads it again, at any age, right after this answers (A1 Leads
     * takeover: "re-classify by hand"). The new pile arrives on the next read.
     */
    public function reclassify(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);
        abort_unless($request->user()->hasPermission('leads.reclassify'), Response::HTTP_FORBIDDEN,
            Refusal::permission('re-classify leads with AI')->message());

        $id = (string) $lead->getKey();
        dispatch(fn () => \Illuminate\Support\Facades\Artisan::call('ezbizi:leads-read', ['--lead' => [$id]]))->afterResponse();

        return response()->json(['data' => ['id' => $id, 'reclassifying' => true]], 202);
    }

    /**
     * PATCH /api/v1/leads/{lead}/conversations/{conversation}/caller-type
     *
     * A person's word on whether a RingCentral caller was new or existing,
     * over the key they pressed and the evidence (CallTypeCorrections).
     * Final, and audited. Same grant as re-classifying.
     */
    public function callerType(Request $request, Lead $lead, string $conversation): JsonResponse
    {
        $this->authorize('update', $lead);
        abort_unless($request->user()->hasPermission('leads.reclassify'), Response::HTTP_FORBIDDEN,
            Refusal::permission('re-classify leads')->message());
        $input = $request->validate(['type' => ['required', Rule::in(['new', 'existing'])]]);
        $row = $lead->conversations()->whereKey($conversation)->first();
        abort_if($row === null || ! str_starts_with((string) $row->remote_id, 'rc_'), Response::HTTP_NOT_FOUND);

        $saved = app(\App\Support\Leads\CallTypeCorrections::class)
            ->override(substr((string) $row->remote_id, 3), $input['type'], $request->user());

        return response()->json(['data' => ['corrected_type' => $saved->corrected_type, 'reason' => $saved->reason]]);
    }

    /**
     * GET /api/v1/leads/{lead}/timeline
     *
     * Everything this caller has said to the business, on every lead with
     * their number, newest first, calls with their recordings (A1 Leads
     * takeover: "customer timeline with recordings"). Only leads the reader
     * can see.
     */
    public function timeline(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $digits = self::tenDigits($lead->phone_digits);
        $leads = $digits === null
            ? collect([$lead])
            : self::scopeToReader(Lead::query(), $request->user())
                ->where('leads.phone_digits', 'like', '%'.$digits)->limit(50)->get(['leads.id', 'leads.source', 'leads.lead_type']);
        $byId = $leads->keyBy('id');

        $rows = LeadConversation::query()->whereIn('lead_id', $byId->keys())
            ->orderByDesc('occurred_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (LeadConversation $c) => [
            ...$this->presentConversation($c),
            'lead_id' => $c->lead_id,
            'source' => $byId[$c->lead_id]?->source,
        ])->values()]);
    }

    /**
     * POST /api/v1/leads/{lead}/follow-up-handled
     *
     * Off the Follow up list: somebody called back, or it needs nothing.
     */
    public function followUpHandled(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        DB::table('leads')->where('id', $lead->getKey())->update(['follow_up_handled_at' => now()]);

        return response()->json(['data' => ['id' => $lead->getKey(), 'follow_up_handled' => true]]);
    }

    /**
     * POST /api/v1/leads/read-all
     *
     * Every lead this person can see, marked read (for everyone), now.
     * Chunked because "every lead" is tens of thousands of rows.
     */
    public function readAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $now = now();
        $count = 0;

        // "Every lead this person can see" is the docblock above, and the
        // sources they were granted are half of what that means. Marking a
        // hidden Thumbtack enquiry read on their behalf is not harmful, but it
        // is a row written about a lead they are not allowed to know exists.
        $ids = Lead::query()->select('id')->sourceVisibleTo($user);

        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds = $user->reachableCompanies()->pluck('id')->all();

        if (! $user->canActOnOrganization($user->organization)) {
            $ids->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    ->orWhere(fn ($w) => $w->whereNull('company_id')->whereNull('franchise_id'));
            });
        }

        $ids->chunkById(1000, function ($chunk) use ($now, &$count) {
            DB::table('leads')->whereIn('id', $chunk->pluck('id'))->update(['read_at' => $now]);
            $count += $chunk->count();
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

        // Each Google Ads account's last certification check, in one query.
        $certifications = AdsAccountState::query()
            ->whereIn('connection_id', $connections->map(fn (SocialConnection $c) => $c->getKey()))
            ->get()
            ->keyBy('connection_id');

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
                'certification' => self::certification($certifications->get($c->getKey())),
                // Why it is broken, in the provider's words, when it is.
                'status_reason' => $c->status_reason,
                'sync_failing' => self::syncFailing($c),
                // The account's Local Services campaigns as the hourly watch
                // last read them, for Pause / Resume in Manage - the same
                // POST /ads/campaigns/status the Ads report uses, already
                // declared to Google (Jose, 2026-09-30). Null until read.
                'campaigns' => $c->provider !== SocialProvider::GoogleAds->value
                    ? null
                    : $certifications->get($c->getKey())?->campaigns,
                'may_pause' => $c->provider === SocialProvider::GoogleAds->value
                    && ($user?->hasPermission('ads_performance.manage') ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * "Connected" while nothing is being read. A Google Ads account is read
     * every few minutes and each read stamps `last_reached_at`; one that has
     * not been reached in SYNC_FAILING_AFTER_HOURS is failing every read
     * (2026-09-30: a row with no lasting permission stayed "connected" while
     * every tick refused it). Thumbtack is pushed to us, not read, so its
     * stamp says nothing and it never counts.
     */
    private const SYNC_FAILING_AFTER_HOURS = 6;

    private static function syncFailing(SocialConnection $c): bool
    {
        if ($c->provider !== SocialProvider::GoogleAds->value || $c->status !== SocialConnection::STATUS_CONNECTED) {
            return false;
        }

        $since = $c->last_reached_at ?? $c->created_at;

        return $since !== null && $since->lt(now()->subHours(self::SYNC_FAILING_AFTER_HOURS));
    }

    /**
     * `{{availability}}` in a reply template: the first day with room in the
     * area covering the customer's ZIP and its open windows - "Thursday,
     * October 8: 11am-2pm or 2-5pm" - as the Availability pane shows it
     * (Jose, 2026-09-30: "we do have her zip code, so the system could fill up
     * the template"). A blank to fill in when the ZIP or its area is unknown.
     *
     * Cached a minute per area: the open thread re-reads the lead every ten
     * seconds, and the answer is a year's walk through the calendar.
     */
    private function availabilityFor(?string $zip): string
    {
        $blank = '___: 8-11am, 11am-2pm or 2-5pm';
        $digits = preg_replace('/\D+/', '', (string) $zip);

        if (strlen($digits) < 4) {
            return $blank;
        }

        $area = ServiceAreaZip::query()->where('zip', str_pad(substr($digits, 0, 5), 5, '0', STR_PAD_LEFT))->first()?->serviceArea;

        if ($area === null || ! Openings::hasCapacity($area)) {
            return $blank;
        }

        return Cache::remember('lead-availability:'.$area->getKey(), 60, function () use ($area, $blank) {
            $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', request()->user()->organization));
            $first = app(Openings::class)->next($area, $from, 0)['first'];

            if ($first === null) {
                return $blank;
            }

            $windows = collect($first['windows'])->filter(fn ($w) => $w['remaining'] > 0)
                ->map(fn ($w) => self::window((string) $w['start_time'], (string) $w['end_time']))
                ->values()->all();
            $last = array_pop($windows);

            return Carbon::parse($first['date'])->format('l, F j').': '
                .($windows === [] ? $last : implode(', ', $windows).' or '.$last);
        });
    }

    /** "8-11am", "11am-2pm": the way the dispatchers write a window. */
    private static function window(string $start, string $end): string
    {
        $a = Carbon::parse($start);
        $b = Carbon::parse($end);
        $hour = fn (Carbon $t) => $t->minute === 0 ? $t->format('g') : $t->format('g:i');

        return $a->format('a') === $b->format('a')
            ? $hour($a).'-'.$hour($b).$b->format('a')
            : $hour($a).$a->format('a').'-'.$hour($b).$b->format('a');
    }

    /** Whether the reader starred it: joined in on the list, asked for otherwise. */
    private function starredByMe(Lead $lead): bool
    {
        if (array_key_exists('starred_by_me_at', $lead->getAttributes())) {
            return $lead->getAttribute('starred_by_me_at') !== null;
        }

        $user = request()->user();

        return $user !== null && DB::table('lead_reads')
            ->where('lead_id', $lead->getKey())
            ->where('user_id', $user->getKey())
            ->whereNotNull('starred_at')
            ->exists();
    }

    /**
     * Can Google still show this Local Services account's ads? Null until the
     * daily check (`ezbizi:ads-certifications`) has read it once.
     *
     * @return array{state: string, problems: list<string>, soonest_expiry: ?string, checked_at: ?string, failed: ?string}|null
     */
    private static function certification(?AdsAccountState $state): ?array
    {
        if ($state === null || $state->certification_checked_at === null) {
            return null;
        }

        return [
            'state' => $state->certification_problem_count > 0 ? 'attention' : 'ok',
            'problems' => array_column((array) $state->certification_problems, 'text'),
            'soonest_expiry' => $state->certification_soonest_expiry?->toIso8601String(),
            'checked_at' => $state->certification_checked_at->toIso8601String(),
            // The last check could not read Google; the verdict above is older.
            'failed' => $state->certification_failed,
        ];
    }

    /**
     * Unread: no read mark, or one older than the newest contact. Never an LS
     * Call (Jose, 2026-10-13: "for LS CALLS automatically mark them as read
     * and remove them from the lead warnings, as they are non actionable").
     */
    private const UNREAD_SQL = "(leads.read_at IS NULL OR COALESCE(leads.last_contact_at, leads.received_at) > leads.read_at) "
        ."AND NOT (leads.source = 'local_services' AND leads.lead_type = 'PHONE_CALL') AND leads.source NOT IN ('ringcentral', 'ez_calls')";

    /** @return list<string> the fields whose value is still the one AI wrote */
    public static function aiHeld(Lead $lead): array
    {
        $set = $lead->ai_set ?? [];

        return array_values(array_filter(['status', 'contact_name', 'postal_code'],
            fn ($f) => isset($set[$f]) && (string) $lead->{$f} === (string) $set[$f]));
    }

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
            'postal_code' => $lead->postal_code,
            'lead_type' => $lead->lead_type,
            'category' => $lead->category,
            'service' => $lead->service,
            'locale' => $lead->locale,
            'summary' => $lead->summary,
            'status' => $lead->status,

            // Which of these AI set and still holds (UI queue 77: the AI mark,
            // gone once a person changes the value).
            'ai' => self::aiHeld($lead),
            // A different name the customer gave, kept for a person to decide.
            'name_conflict' => $lead->ai_set['name_conflict'] ?? null,
            // Why AI set the status, while the status is still AI's.
            'ai_reason' => in_array('status', self::aiHeld($lead), true) ? ($lead->ai_set['reason'] ?? null) : null,
            // Google Local Services credit: AI's suggested reason, then what
            // Google decided once a person asked.
            'credit' => $lead->source === Lead::SOURCE_LOCAL_SERVICES && ($lead->credit_reason !== null) ? [
                'reason' => $lead->credit_reason,
                'requested_at' => $lead->credit_requested_at?->toIso8601String(),
                'decision' => $lead->credit_decision,
            ] : null,

            // What the lead LOOKS LIKE (BOOK / SUPPORT / ARCHIVE, read by a
            // model), beside what somebody DECIDED (status). Null until the
            // enquirer has said something in words, or while the model is off.
            'classification' => $lead->classification,
            'classified_at' => $lead->classified_at?->toIso8601String(),
            // Who answered the call (RingCentral), or who a person said did.
            'dispatcher' => $lead->dispatcher,
            'dispatcher_overridden' => $lead->dispatcher_by_user_id !== null,
            'starred' => $this->starredByMe($lead),
            'note' => $lead->note,
            'note_by' => $lead->note_by_user_id !== null && $lead->relationLoaded('noteBy')
                ? $lead->noteBy?->only(['id', 'name'])
                : null,
            'note_updated_at' => $lead->note_updated_at?->toIso8601String(),

            // Only known on the list, where the reader's mark was joined in.
            // Null on a single-lead read means "not asked", not "read".
            'is_unread' => ! array_key_exists('read_by_me_at', $lead->getAttributes())
                ? null
                : $lead->kind() !== Lead::KIND_LS_CALL && ! in_array($lead->source, [Lead::SOURCE_RINGCENTRAL, Lead::SOURCE_EZ_CALLS], true) && ($lead->read_by_me_at === null
                    || ($lead->last_contact_at ?? $lead->received_at) > Carbon::parse($lead->read_by_me_at)),
            'source_status' => $lead->source_status,
            // The website a chat, form or booking came from (Jose, 2026-10-19).
            'site' => $lead->site_host,
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
            'can_reply' => $canReply = $this->replies->canReply($lead),
            // The customer has waited longer than the business should let
            // them - the list's red mark (Jose, 2026-09-28).
            'reply_overdue' => $lead->replyOverdue($canReply),
            'company' => $lead->company?->only(['id', 'name']),
            'franchise' => $lead->franchise?->only(['id', 'name']),

            // The job it became, once booked. Loaded lazily here: the list
            // is not the place this shows, the thread header is.
            'job' => $lead->job_id !== null && $lead->relationLoaded('job') && $lead->job !== null
                ? $this->presentJob($lead->job)
                : null,
            // 'phone' when the job was found by the customer's number
            // (`ezbizi:match-leads-to-jobs`) rather than booked from here.
            'job_matched_by' => $lead->job_matched_by,
        ];
    }
}
