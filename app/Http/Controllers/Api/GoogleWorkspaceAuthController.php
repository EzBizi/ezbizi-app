<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\GoogleWorkspaceConnection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Email\GoogleOAuth;
use App\Support\Email\GoogleWorkspace;
use App\Support\Email\GoogleWorkspaceRefused;
use App\Support\Email\GoogleWorkspaceSignIns;
use App\Support\Email\MailOAuthRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting a Google Workspace domain, then listing the Directory to pick inboxes.
 *
 * The per-inbox Google sign-in stays on {@see GoogleMailAuthController}. This
 * is the domain grant: a Workspace administrator proves they run the domain,
 * EzBizi stores no mailbox secret, and shared addresses are picked from the
 * Directory rather than typed.
 *
 * Authority is `email.edit` plus reach of the place being connected. A
 * franchise manager who can connect a branch IMAP inbox must not be able
 * to grant EzBizi the company domain.
 */
class GoogleWorkspaceAuthController extends Controller
{
    public function __construct(
        private readonly GoogleWorkspace $workspace,
        private readonly GoogleWorkspaceSignIns $signIns,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/email/workspace — whether this organization has linked a domain.
     */
    public function show(Request $request): JsonResponse
    {
        $this->assertMayManageWorkspace($request, 'open Google Workspace settings');

        $user   = $request->user();
        $target = $this->workspace->bindTarget($user);
        $listed = $this->workspace->connectionsVisibleTo($user)->map(
            fn (GoogleWorkspaceConnection $row) => $this->workspace->refreshStatus($row),
        );

        return response()->json([
            'data' => [
                'view' => $target,
                'can_connect' => ! $listed->contains(
                    fn (GoogleWorkspaceConnection $row) => $row->scope_type === $target['type']
                        && $row->scope_id === $target['id'],
                ),
                'marketplace_listing_url' => $this->workspace->marketplaceListingUrl(),
                'connections' => $listed->map(function (GoogleWorkspaceConnection $row) use ($target) {
                    $health = $this->workspace->scopeHealth($row);
                    $needsGuide = $row->status === GoogleWorkspaceConnection::STATUS_PENDING
                        || $health['status'] === 'needs_attention';

                    return [
                        'domain'      => $row->domain,
                        'admin_email' => $row->admin_email,
                        'status'      => $row->status,
                        'status_reason' => $row->status_reason,
                        'scope_type'  => $row->scope_type,
                        'scope_id'    => $row->scope_id,
                        'scope_label' => $this->workspace->scopeLabel($row),
                        'is_current_view' => $row->scope_type === $target['type']
                            && $row->scope_id === $target['id'],
                        'client_id_for_admin_console' => $this->workspace->clientIdForAdminConsole(),
                        'marketplace_listing_url' => $this->workspace->marketplaceListingUrl(),
                        'scopes' => array_column($this->workspace->requiredScopes(), 'scope'),
                        'health' => $health,
                        'setup_steps' => $needsGuide ? $this->workspace->setupSteps() : [],
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * GET /api/v1/email/workspace/directory — user accounts and aliases to pick from.
     */
    public function directory(Request $request): JsonResponse
    {
        $this->assertMayManageWorkspace($request, 'list Google Workspace addresses');

        $user   = $request->user();
        $target = $this->workspace->bindTarget($user);
        $row    = $this->workspace->connectionAt(
            $user->organization_id,
            $target['type'],
            $target['id'],
        );

        try {
            if ($row === null) {
                throw GoogleWorkspaceRefused::notConnected();
            }

            $listed = $this->workspace->directoryFor($row);
        } catch (GoogleWorkspaceRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['data' => $listed]);
    }

    /**
     * POST /api/v1/email/workspace/oauth/start — send a Workspace admin to Google.
     */
    public function start(Request $request): JsonResponse
    {
        $this->assertMayManageWorkspace($request, 'connect Google Workspace');
        $this->assertMayBindCurrentView($request, 'connect Google Workspace');

        $input = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $domain = strtolower(trim($input['domain']));
        $domain = preg_replace('/^@/', '', $domain) ?? $domain;

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
            return response()->json([
                'message' => 'That is not a company domain EzBizi can connect. Type the '
                    .'Workspace domain only (for example a-1chimney.com), without http or a path.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com', 'google.com'], true)) {
            return response()->json([
                'message' => GoogleWorkspaceRefused::notAWorkspace()->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (($notReady = $this->workspace->notReadyBecause()) !== null) {
            return response()->json(['message' => $notReady], Response::HTTP_NOT_IMPLEMENTED);
        }

        if (! is_string(config('services.google_mail.client_id'))
            || ! is_string(config('services.google_mail.client_secret'))) {
            return response()->json([
                'message' => 'EzBizi has no Google sign-in set up on this installation yet, so '
                    .'a Workspace administrator cannot prove they run the domain. Nothing is '
                    .'wrong with the domain — the missing piece is at EzBizi\'s end.',
            ], Response::HTTP_NOT_IMPLEMENTED);
        }

        $target = $this->workspace->bindTarget($request->user());
        $taken  = GoogleWorkspaceConnection::withoutGlobalScopes()
            ->where('domain', $domain)
            ->where(function ($query) use ($request, $target) {
                $query->where('organization_id', '!=', $request->user()->organization_id)
                    ->orWhere('scope_type', '!=', $target['type'])
                    ->orWhere('scope_id', '!=', $target['id']);
            })
            ->exists();

        if ($taken) {
            return response()->json(
                ['message' => GoogleWorkspaceRefused::domainTaken($domain)->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $target = $this->workspace->bindTarget($request->user());
            $begun  = $this->signIns->begin(
                $request->user(),
                $request->user()->organization,
                $domain,
                $target['type'],
                $target['id'],
            );
        } catch (MailOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $this->audit->log('workspace.google_sign_in_started', $request->user()->organization, [
            'domain' => $domain,
        ]);

        return response()->json(['data' => [
            'authorization_url' => $begun['url'],
            'flow'              => $begun['flow'],
            'expires_at'        => $begun['expires_at']->toIso8601String(),
            'sign_in_as'        => 'a Workspace administrator on '.$domain,
            'scopes'            => ['openid', 'email'],
        ]]);
    }

    /**
     * GET /api/v1/email/workspace/oauth/status — did the admin sign-in land?
     */
    public function status(Request $request): JsonResponse
    {
        $this->assertMayManageWorkspace($request, 'connect Google Workspace');

        $flow = $request->query('flow');

        return response()->json([
            'data' => $this->signIns->outcome(
                $request->user()->organization,
                is_string($flow) ? $flow : null,
            ),
        ]);
    }

    /**
     * DELETE /api/v1/email/workspace — drop the domain grant.
     *
     * Shared inboxes picked from the Directory stay on the list; they simply
     * cannot be opened until the domain is connected again. Deleting them would
     * take their grants with them.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->assertMayManageWorkspace($request, 'disconnect Google Workspace');

        $org    = $request->user()->organization;
        $target = $this->workspace->bindTarget($request->user());
        // input(), not query(): the disconnect panel sends the scope in the
        // body beside the authenticator code. A query string still works.
        $type   = (string) $request->input('scope_type', $target['type']);
        $id     = (string) $request->input('scope_id', $target['id']);

        $this->assertMayActOnScope($request->user(), $type, $id, 'disconnect Google Workspace');

        $connection = $this->workspace->connectionAt($org->getKey(), $type, $id);

        if ($connection === null) {
            return response()->json([
                'message' => 'This part of the business has not connected a Google Workspace.',
            ]);
        }

        app(\App\Support\Integrations\DisconnectCode::class)->require($request);

        $domain = $connection->domain;

        $this->audit->log('workspace.disconnected', $org, [
            'domain' => $domain,
            'scope'  => $type,
        ]);
        $this->workspace->unbind($org->getKey(), $type, $id);

        return response()->json([
            'message' => $domain.' is no longer connected to EzBizi. Shared inboxes that '
                .'were picked from that directory are still listed, but EzBizi cannot open '
                .'them until the Workspace is connected again. Per-inbox Google sign-in and '
                .'IMAP have not changed.',
        ]);
    }

    private function assertMayManageWorkspace(Request $request, string $act): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('email.edit'),
            Response::HTTP_FORBIDDEN,
            Refusal::permission($act)->message(),
        );

        $org = $user->organization;

        abort_unless(
            $org instanceof Organization,
            Response::HTTP_FORBIDDEN,
            Refusal::abovePosition($act)->message(),
        );
    }

    private function assertMayBindCurrentView(Request $request, string $act): void
    {
        $target = $this->workspace->bindTarget($request->user());

        $this->assertMayActOnScope($request->user(), $target['type'], $target['id'], $act);
    }

    private function assertMayActOnScope(User $user, string $type, string $id, string $act): void
    {
        $ok = $type === 'company'
            ? (($company = Company::find($id)) !== null && $user->canActOnCompany($company))
            : $user->canActOnOrganization($user->organization);

        abort_unless(
            $ok,
            Response::HTTP_FORBIDDEN,
            Refusal::abovePosition($act)->message(),
        );
    }
}
