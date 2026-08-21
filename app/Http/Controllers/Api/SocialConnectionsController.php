<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

class SocialConnectionsController extends Controller
{
    public function __construct(private readonly SocialConnectionAccess $access) {}

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
}
