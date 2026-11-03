<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Services\Leads\BusinessProfileLocations;
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
 * Business Profile — the listings the public sees on Maps.
 *
 * Read, plus exactly one write that is EzBizi's own: which franchise is
 * accountable for a listing. Everything else on these rows belongs to Google,
 * and changing what a business publishes about itself is not something this
 * controller does as a side effect of anything.
 */
class BusinessProfileController extends Controller
{
    public function __construct(
        private readonly BusinessProfileLocations $locations,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/business-profile/locations */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);

        $user = $request->user();

        $query = BusinessLocation::query()
            ->with(['company:id,name', 'franchise:id,name'])
            ->orderBy('title');

        \App\Support\CustomerExperience\Places::scope($query, $user);

        // The filter somebody actually wants: what is Google NOT showing.
        if ($request->query('live') === 'no') {
            $query->where(fn ($q) => $q
                ->where('verification_state', '!=', BusinessLocation::VERIFIED)
                ->orWhere('is_published', false));
        }

        $locations = $query->get();

        return response()->json([
            'data' => $locations->map(fn (BusinessLocation $l) => $this->present($l))->all(),
            'meta' => [
                'total'       => $locations->count(),
                // Counted here rather than derived on the client, so the number
                // in the tab badge and the number in the list cannot disagree.
                'not_live'    => $locations->reject(fn (BusinessLocation $l) => $l->isLive())->count(),
                'connections' => $this->access->presentableInView($user, SocialProvider::GoogleBusinessProfile),
                // A just-connected account still reading its listings: the screen says "Syncing…".
                'syncing' => SocialConnection::query()->where('provider', SocialProvider::GoogleBusinessProfile->value)->pluck('id')
                    ->contains(fn ($id) => \Illuminate\Support\Facades\Cache::has(\App\Jobs\SyncBusinessProfileConnection::SYNCING_KEY.$id)),
            ],
        ]);
    }

    /** GET /api/v1/business-profile/locations/{location} */
    public function show(BusinessLocation $location): JsonResponse
    {
        $this->authorize('view', $location);

        return response()->json([
            'data' => $this->present(
                $location->load(['company:id,name', 'franchise:id,name']),
                detailed: true,
            ),
        ]);
    }

    /**
     * PATCH /api/v1/business-profile/locations/{location}
     *
     * Only the franchise link. It is the one field on this row that is EzBizi's
     * rather than Google's — a listing is not a franchise and nothing derives
     * one from the other, so somebody has to say which branch answers for it.
     */
    public function update(Request $request, BusinessLocation $location): JsonResponse
    {
        $this->authorize('update', $location);

        $input = $request->validate([
            'franchise_id' => [
                'present', 'nullable', 'uuid',
                Rule::exists('franchises', 'id')
                    ->where('organization_id', $this->tenant->idOrFail()),
            ],
        ]);

        $franchise = $input['franchise_id'] === null
            ? null
            : Franchise::query()->findOrFail($input['franchise_id']);

        // Reach, not just existence. Naming a franchise somebody cannot act on
        // would let them move accountability onto a branch they have no
        // authority over.
        if ($franchise !== null && ! $request->user()->canActOnFranchise($franchise)) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot put a listing on that branch.');
        }

        $before = $location->franchise_id;

        DB::transaction(function () use ($location, $franchise, $before) {
            $location->franchise_id = $franchise?->getKey();

            $this->audit->logChange('business_location.franchise_set', $location);

            $location->save();

            // Its reviews, and their complaints, sit where the listing sits (2026-10-22).
            \App\Support\CustomerExperience\Places::fileReviewsOf($location);

            // Raised, not merely audited. Somebody deciding which branch answers
            // for a shop front is exactly the kind of deliberate act a rule
            // should be able to hang off — and declaring it as never raised
            // while the code named it was the guardrail's complaint.
            $this->events->emit('business_location.franchise_set', $location, [
                'before' => ['franchise_id' => $before],
                'after'  => ['franchise_id' => $location->franchise_id],
            ]);
        });

        return response()->json(['data' => $this->present($location->fresh())]);
    }

    /** POST /api/v1/business-profile/sync */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);

        $user = $request->user();

        $connections = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleBusinessProfile->value)
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
                'message' => 'No Business Profile is connected yet, so there is nothing to fetch.',
            ], Response::HTTP_CONFLICT);
        }

        $totals   = ['fetched' => 0, 'created' => 0, 'updated' => 0];
        $failures = [];

        foreach ($connections as $connection) {
            try {
                $result = $this->locations->sync($connection);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
            } catch (RuntimeException $e) {
                $failures[] = [
                    'connection' => $connection->display_name,
                    'because'    => $e->getMessage(),
                ];
            }
        }

        $this->audit->log('business_location.synced', $request->user()->organization, metadata: $totals);

        // Failed only when EVERY profile failed. Zero reviews is the honest
        // answer for a profile with nothing Google will list, not a failure - the same
        // conflation LeadsController::sync was corrected for. And the reason
        // goes on top as `message`, which is the one key the client renders;
        // buried in data.failures it never reached the screen.
        $failedOut = $failures !== [] && count($failures) === $connections->count();

        $payload = ['data' => [...$totals, 'failures' => $failures]];

        if ($failedOut) {
            $payload['message'] = (string) ($failures[0]['because'] ?? 'Google would not return listings.');
        }

        return response()->json($payload, $failedOut ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function present(BusinessLocation $location, bool $detailed = false): array
    {
        $row = [
            'id'                 => $location->id,
            'title'              => $location->title,
            'label'              => $location->label(),
            'address'            => $location->addressLine(),
            'phone'              => $location->phone,
            'whatsapp_link'      => BusinessProfileListingController::whatsappLink(),
            'website'            => $location->website,
            'primary_category'   => $location->primary_category,
            'verification_state' => $location->verification_state,
            'is_published'       => $location->is_published,
            'auto_reply'         => (bool) $location->auto_reply,
            'auto_post'          => (bool) $location->auto_post,
            'is_live'            => $location->isLive(),
            'synced_at'          => $location->synced_at?->toIso8601String(),
            'company'            => $location->company?->only(['id', 'name']),
            'franchise'          => $location->franchise?->only(['id', 'name']),
        ];

        if ($detailed) {
            $row['hours'] = $location->hours;
            $row['address_parts'] = [
                'line1'       => $location->address_line1,
                'line2'       => $location->address_line2,
                'city'        => $location->city,
                'state'       => $location->state,
                'postal_code' => $location->postal_code,
                'country'     => $location->country,
            ];
        }

        return $row;
    }
}
