<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
