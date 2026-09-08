<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

class SocialConnectionsController extends Controller
{
    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Marketing')->message(),
        );

        $rows = SocialConnection::query()
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        return response()->json([
            'data' => $rows->map(fn (SocialConnection $c) => [
                'id'              => $c->getKey(),
                'provider'        => $c->provider,
                'provider_label'  => $c->providerLabel(),
                'display_name'    => $c->display_name,
                'handle'          => $c->handle,
                'scope_type'      => $c->scope_type,
                'scope_id'        => $c->scope_id,
                'status'          => $c->status,
                'status_reason'   => $c->status_reason,
                'last_reached_at' => $c->last_reached_at?->toIso8601String(),

                /*
                 * Connected is not the same as postable: four of the nine
                 * networks a tenant can connect have no publisher yet. §4 says a
                 * control that is offered must work, so the composer needs to
                 * know which accounts it may offer — and it must not decide that
                 * from the provider key, which would be a second copy of the
                 * publisher map that goes stale the day one is added.
                 */
                'can_publish'            => Publishers::refusalFor($c->providerEnum()) === null,
                'cannot_publish_because' => Publishers::refusalFor($c->providerEnum()),

                /*
                 * What posting here actually does, when it is not what somebody
                 * would assume. Null for every network that holds no surprise,
                 * so the screen draws nothing rather than a reassurance.
                 */
                'posting_note'           => Publishers::postingNoteFor($c->providerEnum()),
            ])->all(),
        ]);
    }

    /**
     * PATCH /api/v1/social/connections/{connection}/leads-franchise
     *
     * Which franchise owns the enquiries this account produces.
     *
     * Separate from `scope_id`, which says who can SEE the connection, and
     * deliberately so: re-scoping the connection itself would hide it from the
     * company that just assigned it, because inView() makes a franchise-scoped
     * connection invisible to the company above. Null puts it back at company
     * level, which is where every account starts.
     */
    public function placeLeads(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
        );

        // Same gate as connecting and disconnecting: the module that reads the
        // account decides where its leads go.
        $provider = SocialProvider::tryFrom($row->provider);

        abort_unless(
            $user->hasPermission($provider?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('place a connected account')->message(),
        );

        $input = $request->validate([
            'leads_franchise_id' => ['present', 'nullable', 'uuid'],
        ]);

        $franchiseId = $input['leads_franchise_id'] ?? null;

        if ($franchiseId !== null) {
            /*
             * The franchise must be in THIS connection's organization.
             *
             * A foreign key only proves the row exists; it says nothing about
             * whose it is. Without this, a valid-looking id belonging to
             * another tenant would route one customer's enquiries into
             * another customer's list — a cross-tenant write dressed as a
             * dropdown choice.
             */
            $owned = Franchise::query()
                ->whereKey($franchiseId)
                ->where('organization_id', $row->organization_id)
                ->exists();

            abort_unless($owned, Status::HTTP_NOT_FOUND);
        }

        $before = $row->leads_franchise_id;

        if ($before === $franchiseId) {
            return response()->json(['data' => $this->placement($row)]);
        }

        $row->forceFill(['leads_franchise_id' => $franchiseId])->save();

        /*
         * Retroactive, on Jose's ruling of 2026-09-08: assigning an account
         * moves the leads it has ALREADY produced, not only the next ones. A
         * manager setting this up expects the branch to see its own history
         * rather than an empty list.
         *
         * The cost is real and was accepted with it stated: a lead's
         * franchise_id stops recording where it arrived and starts reflecting
         * the account's current assignment, so re-assigning later moves the
         * history again.
         *
         * Chunked rather than one UPDATE because 400 leads today is not 400
         * forever, and an unbounded write on a growing table is a lock somebody
         * eventually notices. One audit row at the end, not one per chunk: the
         * event is the re-assignment, not the batching.
         */
        $moved = 0;

        Lead::query()
            ->where('connection_id', $row->getKey())
            ->select('id')
            ->chunkById(500, function ($leads) use ($franchiseId, &$moved) {
                DB::transaction(function () use ($leads, $franchiseId, &$moved) {
                    $moved += Lead::query()
                        ->whereIn('id', $leads->pluck('id'))
                        ->update(['franchise_id' => $franchiseId]);
                });
            });

        $this->audit->log('social.leads_placed', $row, [
            'provider'     => $row->provider,
            'display_name' => $row->display_name,
            'before'       => $before,
            'after'        => $franchiseId,
            'leads_moved'  => $moved,
        ], actor: $user);

        return response()->json(['data' => $this->placement($row->fresh())]);
    }

    /** @return array<string, mixed> */
    private function placement(SocialConnection $row): array
    {
        return [
            'id'                 => $row->getKey(),
            'provider'           => $row->provider,
            'display_name'       => $row->display_name,
            'leads_franchise_id' => $row->leads_franchise_id,
        ];
    }

    /**
     * Forget a connection.
     *
     * The row goes, tokens and all. It does NOT revoke the app at the network's
     * end — only the network's own settings can do that, which is what the
     * privacy policy tells tenants. Reconnecting makes a fresh row.
     */
    public function destroy(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
        );

        /*
         * The row is found FIRST because the permission depends on which
         * provider it is, and this used to be one blanket `marketing.edit` for
         * everything the controller can delete.
         *
         * That was half a fix left behind. connectPermission() moved the two
         * Google providers to the modules that actually read them — Google Ads
         * to Leads, Business Profile to Reviews — but disconnecting stayed with
         * Marketing, so the desk that could connect an account could not remove
         * it, and a desk with no access to Leads at all could. Jose connected
         * the wrong Google Ads account and had to go to a module he was not
         * working in to undo it.
         *
         * Asking the provider keeps the two ends symmetrical by construction:
         * a provider added later is gated the same way at both, and the nine
         * publishing networks are untouched because theirs already answers
         * `marketing.edit`.
         */
        $provider = SocialProvider::tryFrom($row->provider);

        abort_unless(
            $user->hasPermission($provider?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('disconnect a social account')->message(),
        );

        $this->audit->log('social.disconnected', $row, [
            'provider'     => $row->provider,
            'display_name' => $row->display_name,
            'scope_type'   => $row->scope_type,
        ], actor: $user);

        $row->delete();

        return response()->json(['data' => ['id' => $connection]]);
    }
}
