<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $query = Lead::query()
            ->with(['company:id,name', 'franchise:id,name'])
            ->orderByDesc('received_at')
            ->orderByDesc('created_at');

        // Reach, not just tenancy. BelongsToTenant already filters to the
        // organization; this narrows to the places this person can act on, so
        // a branch manager sees their branch's enquiries and not the group's.
        $franchiseIds = $user->reachableFranchises()->pluck('id')->all();
        $companyIds   = $user->reachableCompanies()->pluck('id')->all();

        if (! $user->canActOnOrganization($user->organization)) {
            $query->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    // A row filed at organization level - both columns null,
                    // which is every row from an organization-scoped
                    // connection - belongs to everybody who can see this tab.
                    // Matching the two columns alone hid all of them from
                    // every user below organization level, while the policy
                    // still allowed each one by id. Business Profile carried
                    // this clause from the start; Leads and Reviews did not.
                    ->orWhere(fn ($w) => $w->whereNull('company_id')->whereNull('franchise_id'));
            });
        }

        if (is_string($status = $request->query('status')) && $status !== '') {
            $query->where('status', $status);
        }

        // One box, two meanings. Somebody looking for a lead has either a name
        // or a phone number in front of them, and asking which they typed is a
        // question the screen should answer for itself. Digits are matched
        // against the normalised column so formatting never decides the result.
        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $search = trim($search);
            $digits = Lead::digits($search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('contact_name', 'like', '%'.$search.'%');

                if ($digits !== null && strlen($digits) >= 4) {
                    $q->orWhere('phone_digits', 'like', '%'.$digits.'%');
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

        return response()->json(['data' => $this->present($lead->load(['company:id,name', 'franchise:id,name']))]);
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
            'status' => ['required', 'string', Rule::in(Lead::STATUSES)],
        ]);

        $before = $lead->status;

        if ($before === $input['status']) {
            return response()->json(['data' => $this->present($lead)]);
        }

        DB::transaction(function () use ($lead, $input, $before) {
            $lead->status = $input['status'];

            $this->audit->logChange('lead.status_changed', $lead);

            $lead->save();

            $this->events->emit('lead.status_changed', $lead, [
                'before' => ['status' => $before],
                'after'  => ['status' => $lead->status],
            ]);
        });

        return response()->json(['data' => $this->present($lead->fresh())]);
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

        $totals = ['fetched' => 0, 'created' => 0, 'updated' => 0];
        $failures = [];

        foreach ($connections as $connection) {
            try {
                $result = $this->localServices->sync($connection);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
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

        return SocialConnection::query()
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
            ->map(fn (SocialConnection $c) => [
                // The id is what lets the tab that owns this connection
                // remove it. Without it the only disconnect was in
                // Marketing, a module these providers are kept out of.
                'id'           => $c->getKey(),
                'provider'     => $c->provider,
                'display_name' => $c->display_name,
                'status'       => $c->status,
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
            'summary'       => $lead->summary,
            'status'        => $lead->status,
            'source_status' => $lead->source_status,
            'charge_status' => $lead->charge_status,
            'received_at'   => $lead->received_at?->toIso8601String(),
            'company'       => $lead->company?->only(['id', 'name']),
            'franchise'     => $lead->franchise?->only(['id', 'name']),
        ];
    }
}
