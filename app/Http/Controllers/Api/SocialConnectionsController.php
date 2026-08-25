<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialConnectionAccess;
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

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('disconnect a social account')->message(),
        );

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
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
