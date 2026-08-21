<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\SocialConnection;
use App\Models\SocialSignIn;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialAccount;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialOAuth;
use App\Support\Social\SocialOAuthDrivers;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialOAuthSignIns;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * OAuth start / status / callback for every social network that uses a consent
 * window. The callback is public; the state row is what identifies anybody.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialOAuthDrivers $drivers,
        private readonly SocialOAuthSignIns $signIns,
        private readonly SocialConnectionAccess $access,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Request $request, string $network): JsonResponse
    {
        $oauth = $this->driverOrAbort($network);
        $user  = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('connect a social account')->message(),
        );

        $notReady = $oauth->notReadyBecause();

        if ($notReady !== null) {
            return response()->json(['message' => $notReady], Status::HTTP_NOT_IMPLEMENTED);
        }

        [$scopeType, $scopeId] = $this->bindToView($user);

        if (! $this->access->reachesScope($user, $scopeType, $scopeId)) {
            abort(Status::HTTP_FORBIDDEN, Refusal::permission('connect a social account')->message());
        }

        try {
            $begun = $this->signIns->begin($user, $oauth, $scopeType, $scopeId);
        } catch (SocialOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $this->audit->log('social.sign_in_started', $user->organization, [
            'provider'   => $oauth->provider()->value,
            'scope_type' => $scopeType,
        ]);

        return response()->json(['data' => [
            'authorization_url' => $begun['url'],
            'flow'              => $begun['flow'],
            'expires_at'        => $begun['expires_at']->toIso8601String(),
            'scopes'            => $oauth->scopes(),
        ]]);
    }

    public function status(Request $request, string $network): JsonResponse
    {
        $this->driverOrAbort($network);
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('connect a social account')->message(),
        );

        $flow = $request->query('flow');

        return response()->json([
            'data' => $this->signIns->outcome($user, is_string($flow) ? $flow : null),
        ]);
    }

    public function callback(Request $request, string $network): Response
    {
        $oauth  = $this->driverOrAbort($network);
        $label  = $oauth->provider()->label();
        $state  = (string) $request->query('state', '');
        $signIn = null;

        try {
            if ($state === '') {
                throw SocialOAuthRefused::noOpenSignIn($label);
            }

            $signIn = $this->signIns->complete($state, $label);

            if ($request->query('error') !== null) {
                throw SocialOAuthRefused::declined($label);
            }

            $code = (string) $request->query('code', '');

            if ($code === '') {
                throw SocialOAuthRefused::exchangeFailed($label);
            }

            return $this->finish($oauth, $signIn, $code);
        } catch (SocialOAuthRefused $e) {
            if ($signIn !== null) {
                $this->signIns->record($signIn, SocialOAuthSignIns::REFUSED, $e->getMessage());
            }

            return $this->page('That did not work', $e->getMessage(), $e->status);
        }
    }

    /**
     * @throws SocialOAuthRefused
     */
    private function finish(SocialOAuth $oauth, SocialSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;
        $label        = $oauth->provider()->label();

        if ($organization === null) {
            throw SocialOAuthRefused::noLongerPermitted(
                'The business this sign-in belongs to is no longer set up in EzBizi.',
            );
        }

        if ($signIn->provider !== $oauth->provider()->value) {
            throw SocialOAuthRefused::noOpenSignIn($label);
        }

        return $this->tenant->run($organization, function () use ($oauth, $signIn, $code, $label) {
            $user = User::find($signIn->user_id);

            if ($user === null || ! $user->is_active
                || ! $user->hasPermission('marketing.edit')
                || ! $this->access->reachesScope($user, $signIn->scope_type, $signIn->scope_id)) {
                throw SocialOAuthRefused::noLongerPermitted(
                    'The person who started it is no longer able to connect a social account. '
                    .'Somebody who can manage Marketing needs to connect it.',
                );
            }

            $grant    = $oauth->exchange($code, $signIn->code_verifier);
            $accounts = $oauth->identify($grant);

            if ($oauth->requiresRefreshToken() && ($grant->refreshToken === null || $grant->refreshToken === '')) {
                throw SocialOAuthRefused::noLastingPermission($label);
            }

            $connections = $this->store($user, $signIn, $oauth->provider(), $grant, $accounts);
            $first       = $connections[0];
            $said        = count($connections) === 1
                ? sprintf(
                    '%s is connected to EzBizi as a %s account. You can close this window and '
                    .'compose from Social Media Management.',
                    $first->display_name,
                    $label,
                )
                : sprintf(
                    '%d %s Pages are connected to EzBizi. You can close this window and compose '
                    .'from Social Media Management.',
                    count($connections),
                    $label,
                );

            $this->signIns->record($signIn, SocialOAuthSignIns::CONNECTED, $said);

            return $this->page('Connected', $said, Status::HTTP_OK);
        });
    }

    /**
     * @param  SocialAccount[]  $accounts
     * @return SocialConnection[]
     *
     * @throws SocialOAuthRefused
     */
    private function store(
        User $user,
        SocialSignIn $signIn,
        SocialProvider $provider,
        SocialOAuthGrant $grant,
        array $accounts,
    ): array {
        try {
            return DB::transaction(function () use ($user, $signIn, $provider, $grant, $accounts) {
                $saved = [];

                foreach ($accounts as $account) {
                    $connection = SocialConnection::query()
                        ->where('provider', $provider->value)
                        ->where('remote_id', $account->remoteId)
                        ->first();

                    if ($connection === null) {
                        $connection = new SocialConnection;
                        $connection->forceFill([
                            'organization_id' => $signIn->organization_id,
                            'scope_type'      => $signIn->scope_type,
                            'scope_id'        => $signIn->scope_id,
                            'provider'        => $provider->value,
                            'remote_id'       => $account->remoteId,
                        ]);
                    }

                    $connection->forceFill([
                        'display_name' => $account->displayName,
                        'handle'       => $account->handle,
                    ]);

                    $tokens = [
                        'refresh_token' => $account->refreshToken ?? $grant->refreshToken,
                        'access_token'  => $account->accessToken ?? $grant->accessToken,
                        'scopes'        => $grant->scopes,
                    ];

                    if ($grant->expiresIn !== null) {
                        $tokens['expires_at'] = now()->addSeconds($grant->expiresIn)->toIso8601String();
                    }

                    $connection->connect($tokens);
                    $connection->save();

                    $this->audit->log('social.connected', $connection, [
                        'provider'     => $provider->value,
                        'display_name' => $connection->display_name,
                        'handle'       => $connection->handle,
                        'scope_type'   => $connection->scope_type,
                    ], actor: $user);

                    $saved[] = $connection;
                }

                return $saved;
            });
        } catch (SubscriptionLapsedException) {
            throw SocialOAuthRefused::noLongerPermitted(
                'This EzBizi subscription has ended, so nothing new can be saved until it is '
                .'renewed.',
            );
        }
    }

    private function driverOrAbort(string $network): SocialOAuth
    {
        $oauth = $this->drivers->byRouteKey($network);

        abort_if($oauth === null, Status::HTTP_NOT_FOUND);

        return $oauth;
    }

    /** @return array{0: string, 1: string} */
    private function bindToView(User $user): array
    {
        $view = ViewScope::forUser($user);

        if ($view->isOrganization()) {
            return [SocialConnection::SCOPE_ORGANIZATION, $user->organization_id];
        }

        return [$view->type, (string) $view->id];
    }

    private function page(string $heading, string $sentence, int $status): Response
    {
        $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>EzBizi — {$this->escape($heading)}</title>
        <style>
        body { margin: 0; padding: 2rem 1.25rem; background: #f6f7f9; color: #1c1e21;
               font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 34rem; margin: 0 auto; background: #fff; border: 1px solid #e2e5e9;
               border-radius: 10px; padding: 1.5rem; }
        h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
        p { margin: 0; }
        </style>
        </head>
        <body>
        <main>
        <h1>{$this->escape($heading)}</h1>
        <p>{$this->escape($sentence)}</p>
        </main>
        </body>
        </html>
        HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function escape(string $value): string
    {
        return e(mb_substr($value, 0, 2000));
    }
}
