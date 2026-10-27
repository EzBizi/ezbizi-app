<?php

namespace App\Http\Controllers\Api;

use App\Models\GoogleWorkspaceConnection;
use App\Models\PhoneConnection;
use App\Models\SocialConnection;
use App\Support\Integrations\Dependents\DependentRegistry;
use App\Support\Integrations\Dependents\IntegrationRef;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * GET /api/v1/integrations/{kind}/{id}/dependents
 *
 * What stops working if this connection is disconnected — read by the
 * disconnect panel before it asks for the authenticator code. Jose,
 * 2026-10-21: "list the amount of services - automations - etc that will stop
 * working once the disconnection is through".
 *
 *   kind = social    id = social_connections.id
 *   kind = phone     id = phone_connections.id
 *   kind = workspace id = "{scope_type}:{scope_id}" (DELETE /email/workspace is scope-addressed)
 *
 * Whoever may see the connection may ask; a connection outside the viewer's
 * reach (or another organization's) is a 404, the same as its DELETE.
 */
final class IntegrationDependentsController extends Controller
{
    public function __construct(
        private readonly DependentRegistry $registry,
        private readonly SocialConnectionAccess $access,
    ) {}

    public function show(Request $request, string $kind, string $id): JsonResponse
    {
        $user = $request->user();
        $org = (string) $user->organization_id;

        $ref = match ($kind) {
            'social' => $this->social($request, $id, $org),
            'phone' => $this->phone($request, $id, $org),
            'workspace' => $this->workspace($request, $id, $org),
            default => abort(Status::HTTP_NOT_FOUND),
        };

        return response()->json(['data' => [
            'kind' => $kind,
            'id' => $id,
            // What stops working, by feature (breaks | degrades), then what is
            // counted against this connection today.
            'features' => $this->registry->features($ref),
            'dependents' => $this->registry->for($ref, $user),
        ]]);
    }

    private function social(Request $request, string $id, string $org): IntegrationRef
    {
        $row = SocialConnection::query()->where('organization_id', $org)->find($id);

        abort_if($row === null || ! $this->access->inView($request->user(), $row), Status::HTTP_NOT_FOUND);

        $others = SocialConnection::query()
            ->where('organization_id', $org)
            ->where('provider', $row->provider)
            ->whereKeyNot($row->getKey())
            ->exists();

        return new IntegrationRef('social', (string) $row->provider, (string) $row->getKey(), $org, $row, ! $others);
    }

    private function phone(Request $request, string $id, string $org): IntegrationRef
    {
        $row = PhoneConnection::query()->where('organization_id', $org)->find($id);

        abort_if($row === null, Status::HTTP_NOT_FOUND);
        $this->authorize('view', $row);

        $others = PhoneConnection::query()->where('organization_id', $org)->whereKeyNot($row->getKey())->exists();

        return new IntegrationRef('phone', 'twilio', (string) $row->getKey(), $org, $row, ! $others);
    }

    private function workspace(Request $request, string $id, string $org): IntegrationRef
    {
        abort_unless($request->user()->hasPermission('email.edit'), Status::HTTP_FORBIDDEN);

        [$type, $scopeId] = array_pad(explode(':', $id, 2), 2, '');

        $row = GoogleWorkspaceConnection::query()
            ->where('organization_id', $org)
            ->where('scope_type', $type)
            ->where('scope_id', $scopeId)
            ->first();

        abort_if($row === null, Status::HTTP_NOT_FOUND);

        $others = GoogleWorkspaceConnection::query()->where('organization_id', $org)->whereKeyNot($row->getKey())->exists();

        return new IntegrationRef('workspace', 'google_workspace', (string) $row->getKey(), $org, $row, ! $others);
    }
}
