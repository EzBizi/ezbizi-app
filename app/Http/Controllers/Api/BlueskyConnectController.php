<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Social\BlueskySession;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Bluesky has no developer portal. Connect is a handle + app password, stored
 * as a refresh JWT. The account password is never kept.
 */
class BlueskyConnectController extends Controller
{
    public function __construct(
        private readonly BlueskySession $bluesky,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('connect a social account')->message(),
        );

        $view = ViewScope::forUser($user);
        $scopeType = $view->isOrganization()
            ? SocialConnection::SCOPE_ORGANIZATION
            : $view->type;
        $scopeId = $view->isOrganization()
            ? $user->organization_id
            : (string) $view->id;

        if (! $this->access->reachesScope($user, $scopeType, $scopeId)) {
            abort(Status::HTTP_FORBIDDEN, Refusal::permission('connect a social account')->message());
        }

        $handle = (string) $request->input('handle', '');
        $password = (string) $request->input('app_password', '');

        try {
            $session = $this->bluesky->create($handle, $password);
        } catch (SocialOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Bluesky->value)
            ->where('remote_id', $session['did'])
            ->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => SocialProvider::Bluesky->value,
                'remote_id' => $session['did'],
            ]);
        }

        $connection->forceFill([
            'display_name' => $session['handle'],
            'handle' => $session['handle'],
        ]);
        $connection->connect([
            'refresh_token' => $session['refresh_jwt'],
            'access_token' => $session['access_jwt'],
        ]);
        $connection->save();

        $this->audit->log('social.connected', $connection, [
            'provider' => SocialProvider::Bluesky->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
        ]);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => SocialProvider::Bluesky->value,
            'provider_label' => SocialProvider::Bluesky->label(),
            'display_name' => $connection->display_name,
            'handle' => $connection->handle,
        ]]);
    }
}
