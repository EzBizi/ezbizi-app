<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * A Workiz web session for the box worker (Jose, 2026-10-20). EzBizi never
 * signs in to Workiz (its login is behind a CAPTCHA): a person signs in in
 * their own browser, and the "Send my Workiz session" extension posts the
 * session to a one-time link the Integrations card hands out.
 */
class WorkizWebSessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** POST /api/v1/social/workiz-web/link: a link the extension sends the session to, good for 30 minutes. */
    public function link(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->mayConfigure($request);

        $connection = SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $user->organization_id, 'provider' => SocialProvider::WorkizWeb->value,
                'remote_id' => $user->organization_id, 'display_name' => 'Workiz web login',
            ]);
            $connection->connect([]);
            $connection->forceFill(['status' => 'expired', 'status_reason' => 'Not signed in yet.'])->save();
        }

        // One use per link: a new link replaces the nonce, and a session that is
        // kept clears it, so a copied URL cannot re-point the reports later.
        $nonce = Str::random(40);
        $connection->connect(['handoff_nonce' => $nonce] + (array) $connection->credentials);
        $connection->save();

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'link' => URL::temporarySignedRoute('social.workiz-web.handoff', now()->addMinutes(30), ['connection' => $connection->getKey(), 'nonce' => $nonce]),
        ]]);
    }

    /** POST the signed link: the extension's cookies. No EzBizi sign-in; the signature is the authority. */
    public function handoff(Request $request, string $connection, TenantContext $tenant): JsonResponse
    {
        $row = SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::WorkizWeb->value)->findOrFail($connection);
        $cookies = $request->validate(['cookies' => ['required', 'array'], 'cookies.*' => ['string', 'max:2000']])['cookies'];
        $nonce = (string) ($row->credentials['handoff_nonce'] ?? '');
        abort_unless($nonce !== '' && hash_equals($nonce, (string) $request->query('nonce')), Status::HTTP_GONE,
            'This link has been used or replaced. Get a new one in EzBizi.');

        $refused = $tenant->run($row->organization()->withoutGlobalScopes()->firstOrFail(), function () use ($row, $cookies) {
            $refused = WorkizWebSession::adopt($row, $cookies);

            if ($refused === null) {
                $row->connect(array_diff_key((array) $row->credentials, ['handoff_nonce' => 1]));
                $row->save();
            }

            if ($refused === null) {
                $this->audit->log('social.connected', $row, ['provider' => SocialProvider::WorkizWeb->value, 'by' => 'session handoff']);
            }

            return $refused;
        });

        return $refused === null
            ? response()->json(['data' => ['connected' => true]])
            : response()->json(['message' => $refused], Status::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** GET /api/v1/social/workiz-web/{connection}/accounts: the accounts the login reaches and the branch each one's books belong to. */
    public function accounts(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->guard($request, $connection);
        $map = (array) ($connection->credentials['accounts'] ?? []);

        return response()->json(['data' => collect(WorkizWebSession::accounts($connection))
            ->map(fn (string $name, string $id) => ['account' => $id, 'name' => $name, 'franchise_id' => $map[$id] ?? null])->values()]);
    }

    /** PUT /api/v1/social/workiz-web/{connection}/accounts {account, franchise_id|null}: tenant data, not code. */
    public function map(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->guard($request, $connection);
        $data = $request->validate(['account' => ['required', 'string', 'max:40'], 'franchise_id' => ['nullable', 'uuid']]);
        abort_if($data['franchise_id'] !== null && ! Franchise::query()->whereKey($data['franchise_id'])->exists(), Status::HTTP_UNPROCESSABLE_ENTITY);

        $map = array_filter([$data['account'] => $data['franchise_id']] + (array) ($connection->credentials['accounts'] ?? []));
        $connection->connect(['accounts' => $map] + (array) $connection->credentials);
        $connection->save();
        $this->audit->log('social.workiz_web_mapped', $connection, $data, actor: $request->user());

        return response()->json(['data' => ['accounts' => $map]]);
    }

    private function guard(Request $request, SocialConnection $connection): void
    {
        abort_unless($connection->provider === SocialProvider::WorkizWeb->value, Status::HTTP_NOT_FOUND);
        $this->mayConfigure($request);
    }

    /** The login reads every account, so it belongs to the whole organization: changed only from its view (AGENTS.md). */
    private function mayConfigure(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission(SocialProvider::WorkizWeb->connectPermission()), Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::WorkizWeb->connectAct())->message());
        abort_unless(ViewScope::forUser($user)->isOrganization() && $user->canActOnOrganization($user->organization),
            Status::HTTP_FORBIDDEN, 'The Workiz web login belongs to the whole organization. Switch to the organization view to change it.');
    }
}
