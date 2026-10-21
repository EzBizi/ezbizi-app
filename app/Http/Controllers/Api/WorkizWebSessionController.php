<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
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
        $view = ViewScope::forUser($user);
        abort_if($view->type === ViewScope::FRANCHISE, Status::HTTP_FORBIDDEN,
            'The Workiz web login belongs to the company. Switch to the company view to change it.');
        // The company in view; the organization view names one.
        $company = $view->type === ViewScope::COMPANY ? $view->id : $request->validate(['company_id' => ['nullable', 'uuid']])['company_id'] ?? null;
        abort_if($company === null || ! Company::query()->whereKey($company)->exists(), Status::HTTP_UNPROCESSABLE_ENTITY,
            'Choose the company whose Workiz this is.');
        $this->mayConfigure($request, $company);

        $connection = SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)
            ->where('scope_type', SocialConnection::SCOPE_COMPANY)->where('scope_id', $company)->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $company, 'provider' => SocialProvider::WorkizWeb->value,
                'remote_id' => $company, 'display_name' => 'Workiz web login',
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
            // adopt() keeps only the session and the account map, so the
            // nonce goes with it: the link is spent.
            $refused = WorkizWebSession::adopt($row, $cookies);

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
            ->map(fn (string $name, string $id) => ['account' => $id, 'name' => $name, 'mapped' => isset($map[$id]),
                'franchise_id' => WorkizWebSession::franchiseFor($connection, $id, $name)?->getKey()])->values()]);
    }

    /** PUT /api/v1/social/workiz-web/{connection}/accounts {account, franchise_id|null}: tenant data, not code. */
    public function map(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->guard($request, $connection);
        $data = $request->validate(['account' => ['required', 'string', 'max:40'], 'franchise_id' => ['nullable', 'uuid']]);
        // Only a branch of the login's own company (and its Sub Companies).
        abort_if($data['franchise_id'] !== null && ! Franchise::query()->whereKey($data['franchise_id'])
            ->whereIn('company_id', Company::find($connection->scope_id)?->subtreeCompanyIds() ?? [])->exists(),
            Status::HTTP_UNPROCESSABLE_ENTITY, 'That branch is not in this company.');

        $map = array_filter([$data['account'] => $data['franchise_id']] + (array) ($connection->credentials['accounts'] ?? []));
        $connection->connect(['accounts' => $map] + (array) $connection->credentials);
        $connection->save();
        $this->audit->log('social.workiz_web_mapped', $connection, $data, actor: $request->user());

        return response()->json(['data' => ['accounts' => $map]]);
    }

    /**
     * PUT /api/v1/social/workiz-web/{connection}/company {company_id}: a login
     * sent before it belonged to a company (0.0.0466) moves to one, its
     * session kept, instead of being sent again. Organization view only.
     */
    public function moveToCompany(Request $request, SocialConnection $connection): JsonResponse
    {
        abort_unless($connection->provider === SocialProvider::WorkizWeb->value && $connection->scope_type === SocialConnection::SCOPE_ORGANIZATION,
            Status::HTTP_NOT_FOUND);
        $company = $request->validate(['company_id' => ['required', 'uuid']])['company_id'];
        abort_unless(Company::query()->whereKey($company)->exists(), Status::HTTP_UNPROCESSABLE_ENTITY, 'Choose the company whose Workiz this is.');
        abort_unless(ViewScope::forUser($request->user())->isOrganization(), Status::HTTP_FORBIDDEN,
            'Switch to the organization view to move this login to a company.');
        $this->mayConfigure($request, $company);
        abort_if(SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)
            ->where('scope_type', SocialConnection::SCOPE_COMPANY)->where('scope_id', $company)->exists(),
            Status::HTTP_CONFLICT, 'That company already has a Workiz web login.');

        // The map named branches anywhere in the organization; under a company it starts again.
        $connection->connect(array_diff_key((array) $connection->credentials, ['accounts' => 1]));
        $connection->forceFill(['scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $company, 'remote_id' => $company])->save();
        $this->audit->log('social.workiz_web_moved', $connection, ['company_id' => $company], actor: $request->user());

        return response()->json(['data' => ['id' => $connection->getKey(), 'company_id' => $company]]);
    }

    private function guard(Request $request, SocialConnection $connection): void
    {
        abort_unless($connection->provider === SocialProvider::WorkizWeb->value, Status::HTTP_NOT_FOUND);
        $this->mayConfigure($request, $connection->scope_type === SocialConnection::SCOPE_COMPANY ? $connection->scope_id : null);
    }

    /**
     * The login reads every Workiz account of one company (Jose, 2026-10-21:
     * "the workiz belongs to a-1chimney only"), so it is changed from that
     * company's view or the organization's, never a branch's (AGENTS.md).
     */
    private function mayConfigure(Request $request, ?string $company): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission(SocialProvider::WorkizWeb->connectPermission()), Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::WorkizWeb->connectAct())->message());
        $view = ViewScope::forUser($user);
        $reach = $view->isOrganization() ? $user->canActOnOrganization($user->organization) : $view->type === ViewScope::COMPANY;
        abort_unless($company !== null && $reach && $view->coversCompany($company),
            Status::HTTP_FORBIDDEN, 'The Workiz web login belongs to the company. Switch to the company view to change it.');
    }
}
