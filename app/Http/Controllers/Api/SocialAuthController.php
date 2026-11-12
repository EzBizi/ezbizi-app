<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Jobs\OnboardWhatsAppNumber;
use App\Jobs\RegisterThumbtackWebhook;
use App\Jobs\SyncBusinessProfileConnection;
use App\Jobs\SubscribeMetaMessages;
use App\Models\SocialConnection;
use App\Models\SocialSignIn;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Social\BusinessProfileOAuth;
use App\Support\Social\ConnectSetup;
use App\Support\Social\FacebookAdsOAuth;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\SocialAccount;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialOAuth;
use App\Support\Social\SocialOAuthDrivers;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialOAuthSignIns;
use App\Support\Social\SocialProvider;
use App\Support\Social\YouTubeOAuth;
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
        private readonly ConnectSetup $setup,
    ) {}

    public function start(Request $request, string $network): JsonResponse
    {
        $oauth = $this->driverOrAbort($network);
        $user = $request->user();

        // Only the products chosen before the sign-in (design 183).
        if ($oauth instanceof FacebookOAuth && $request->filled('products')) {
            $oauth = $oauth->withProducts($request->validate([
                'products' => ['array'],
                'products.*' => ['in:'.implode(',', array_keys(FacebookOAuth::PRODUCTS))],
            ])['products']);
        }

        // The permission follows the module that USES the connection, not this
        // controller. A Google Ads account is connected by whoever runs Leads.
        abort_unless(
            $user->hasPermission($oauth->provider()->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission($oauth->provider()->connectAct())->message(),
        );

        // Google the same way; Gmail is listed but never signed in to.
        if ($oauth instanceof GoogleAdsOAuth && $request->filled('products')) {
            $chosen = array_values(array_filter(
                $request->validate([
                    'products' => ['array'],
                    'products.*' => ['in:'.implode(',', array_keys(GoogleAdsOAuth::PRODUCTS))],
                ])['products'],
                fn (string $k) => GoogleAdsOAuth::PRODUCTS[$k]['scopes'] !== [],
            ));

            abort_if($chosen === [], Status::HTTP_UNPROCESSABLE_ENTITY,
                'Choose Business Profile, Google Ads or YouTube. Gmail is set up through Google Workspace in Email settings.');

            $oauth = $oauth->withProducts($chosen);
        }

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
            'provider' => $oauth->provider()->value,
            'scope_type' => $scopeType,
        ]);

        return response()->json(['data' => [
            'authorization_url' => $begun['url'],
            'flow' => $begun['flow'],
            'expires_at' => $begun['expires_at']->toIso8601String(),
            'scopes' => $oauth->scopes(),
        ]]);
    }

    public function status(Request $request, string $network): JsonResponse
    {
        $oauth = $this->driverOrAbort($network);
        $user = $request->user();

        abort_unless(
            $user->hasPermission($oauth->provider()->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission($oauth->provider()->connectAct())->message(),
        );

        $flow = $request->query('flow');

        return response()->json([
            'data' => $this->signIns->outcome($user, is_string($flow) ? $flow : null),
        ]);
    }

    public function callback(Request $request, string $network): Response
    {
        $oauth = $this->driverOrAbort($network);
        $label = $oauth->provider()->label();
        $state = (string) $request->query('state', '');
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

            return $this->page('That did not work', $e->getMessage(), $e->status, $oauth->provider());
        }
    }

    /**
     * @throws SocialOAuthRefused
     */
    private function finish(SocialOAuth $oauth, SocialSignIn $signIn, string $code): Response
    {
        $organization = $signIn->organization;
        $label = $oauth->provider()->label();

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
                || ! $user->hasPermission($oauth->provider()->connectPermission())
                || ! $this->access->reachesScope($user, $signIn->scope_type, $signIn->scope_id)) {
                throw SocialOAuthRefused::noLongerPermitted(sprintf(
                    'The person who started it is no longer able to %s. Somebody who still can '
                    .'needs to connect it.',
                    $oauth->provider()->connectAct(),
                ));
            }

            $grant = $oauth->exchange($code, $signIn->code_verifier);

            if ($oauth->provider() === SocialProvider::GoogleAds) {
                return $this->finishGoogle($user, $signIn, $grant);
            }

            $accounts = $oauth->identify($grant);

            if ($oauth->requiresRefreshToken() && ($grant->refreshToken === null || $grant->refreshToken === '')) {
                throw SocialOAuthRefused::noLastingPermission($label);
            }

            $connections = $this->store($user, $signIn, $oauth->provider(), $grant, $accounts);
            $first = $connections[0];

            /*
             * ONE FACEBOOK CONNECT BRINGS THE AD ACCOUNTS TOO (Jose, 2026-10-14:
             * "if i am a random business ... i connect my facebook account i
             * should see my financials"). The same grant asks for ads_read; the
             * ad accounts it can read become Facebook Ads connections beside the
             * Pages. None readable (ads_read not granted, or no ad account) costs
             * the Page connect nothing.
             */
            if ($oauth->provider() === SocialProvider::FacebookPage
                && $user->hasPermission(SocialProvider::FacebookAds->connectPermission())) {
                try {
                    $this->store($user, $signIn, SocialProvider::FacebookAds, $grant, app(FacebookAdsOAuth::class)->identify($grant));
                } catch (SocialOAuthRefused) {
                    // No ad account this login can read.
                }
            }

            // The provider says what it just connected and where it lands. This
            // was two sprintf() calls here, saying "Pages" and "Social Media
            // Management" whoever had connected — which announced 37 Google Ads
            // "Pages" and sent somebody to a module those enquiries never reach.
            $said = $oauth->provider()->connectedSentence(
                count($connections),
                (string) $first->display_name,
            );

            $this->signIns->record($signIn, SocialOAuthSignIns::CONNECTED, $said);

            return $this->page('Connected', $said, Status::HTTP_OK, $oauth->provider());
        });
    }

    /**
     * ONE GOOGLE SIGN-IN, EVERY GOOGLE PRODUCT (Jose, 2026-11-05: "one
     * Integrations card per sign-in"). The consent asks for Ads, Business
     * Profile and YouTube together; each product the person left ticked,
     * may connect, and owns something in becomes rows. One that brings
     * nothing costs the others nothing, as Facebook's ad accounts do.
     */
    private function finishGoogle(User $user, SocialSignIn $signIn, SocialOAuthGrant $grant): Response
    {
        $family = [
            [SocialProvider::GoogleAds, GoogleAdsOAuth::class, GoogleAdsOAuth::SCOPE],
            [SocialProvider::GoogleBusinessProfile, BusinessProfileOAuth::class, BusinessProfileOAuth::SCOPES[0]],
            [SocialProvider::YouTube, YouTubeOAuth::class, YouTubeOAuth::SCOPE_UPLOAD],
        ];
        $said = [];
        $refused = null;

        foreach ($family as [$provider, $driver, $scope]) {
            if (($grant->scopes !== [] && ! in_array($scope, $grant->scopes, true))
                || ! $user->hasPermission($provider->connectPermission())) {
                continue;
            }

            try {
                $rows = $this->store($user, $signIn, $provider, $grant, app($driver)->identify($grant));
            } catch (SocialOAuthRefused $e) {
                $refused ??= $e;

                continue;
            }

            $said[] = count($rows) === 1
                ? $rows[0]->display_name.' ('.$provider->label().')'
                : count($rows).' '.$provider->label().' accounts';
        }

        if ($said === []) {
            throw $refused ?? SocialOAuthRefused::unidentified('Google');
        }

        $sentence = 'Connected to EzBizi: '.implode(', ', $said).'. You can close this window.';
        $this->signIns->record($signIn, SocialOAuthSignIns::CONNECTED, $sentence);

        return $this->page('Connected', $sentence, Status::HTTP_OK, SocialProvider::GoogleAds);
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
        // Refused HERE, before any row is written: a number another tenant
        // routes must not gain a connection (and a token) in this one. The
        // onboarding job checks again as a backstop.
        if ($provider === SocialProvider::WhatsApp
            && WhatsAppNumber::query()->withoutGlobalScopes()
                ->whereIn('phone_number_id', array_map(fn (SocialAccount $a) => $a->remoteId, $accounts))
                ->where('organization_id', '!=', $signIn->organization_id)
                ->exists()) {
            throw SocialOAuthRefused::numberTaken();
        }

        try {
            $saved = DB::transaction(function () use ($user, $signIn, $provider, $grant, $accounts) {
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
                            'scope_type' => $signIn->scope_type,
                            'scope_id' => $signIn->scope_id,
                            'provider' => $provider->value,
                            'remote_id' => $account->remoteId,
                        ]);
                    }

                    $connection->forceFill([
                        'display_name' => $account->displayName,
                        'handle' => $account->handle,
                        // Google Ads: the manager this account is read through,
                        // null when the grant reaches it directly. Written on
                        // every connect so a row that gained a direct route
                        // drops the manager and one that lost it gains one.
                        'login_customer_id' => $account->loginCustomerId,
                        'is_manager' => $account->isManager,
                    ]);

                    // Only Meta issues one, and only Meta's callbacks need it.
                    // Written on every reconnect so a row made before the
                    // column existed picks it up rather than staying unmatched.
                    $metaUser = $grant->extra['meta_user_id'] ?? null;

                    // Google: the login this grant is (GoogleToken::exchange).
                    // Kept when a reconnect's consent carried none.
                    if (is_string($grant->extra['account_email'] ?? null)) {
                        $connection->forceFill(['account_email' => $grant->extra['account_email']]);
                    }

                    if (is_string($metaUser) && $metaUser !== '') {
                        $connection->forceFill(['meta_user_id' => $metaUser]);
                    }

                    $tokens = [
                        'refresh_token' => $account->refreshToken ?? $grant->refreshToken,
                        'access_token' => $account->accessToken ?? $grant->accessToken,
                        'scopes' => $grant->scopes,
                    ];

                    // The one Google sign-in: every row renews through its client.
                    if ($signIn->provider === SocialProvider::GoogleAds->value) {
                        $tokens['oauth_service'] = 'google_ads';
                    }

                    if ($grant->expiresIn !== null) {
                        $tokens['expires_at'] = now()->addSeconds($grant->expiresIn)->toIso8601String();
                    }

                    // WhatsApp: which WABA the number is in, and the two-step
                    // PIN it was registered with, kept so a reconnect can
                    // register it again with the same one (connect() replaces
                    // the credentials whole).
                    if ($account->wabaId !== null) {
                        $tokens['waba_id'] = $account->wabaId;
                        $pin = $connection->credentials['pin'] ?? null;

                        if (is_string($pin) && $pin !== '') {
                            $tokens['pin'] = $pin;
                        }
                    }

                    $connection->connect($tokens);
                    $connection->save();

                    /*
                     * Thumbtack has to be told where to send enquiries, and
                     * connecting does not tell it.
                     *
                     * Their supply API cannot list negotiations, so a connected
                     * account with no registered webhook receives nothing, for
                     * ever, with no way to go back for what it missed. On
                     * 2026-09-08 twelve businesses connected and the screen said
                     * Connected while nothing was ever going to arrive.
                     *
                     * Queued rather than done here: one registration is three
                     * round trips and that first connect returned twelve
                     * accounts. Inline, this screen would have waited on about
                     * thirty-six sequential calls, and one failing business
                     * would have taken the other eleven with it.
                     */
                    if ($provider === SocialProvider::Thumbtack) {
                        RegisterThumbtackWebhook::dispatch((string) $connection->getKey());
                        // And its enquiries from before a disconnect come back.
                        app(ThumbtackLeads::class)->reclaim($connection);
                    }

                    // A Business Profile reads its listings now, not at tomorrow's sync (Jose, 2026-10-29).
                    if ($provider === SocialProvider::GoogleBusinessProfile) {
                        SyncBusinessProfileConnection::start($connection);
                    }

                    // Meta the same way: a Page or Instagram account sends its
                    // messages only once it has subscribed the app.
                    if (in_array($provider, [SocialProvider::FacebookPage, SocialProvider::InstagramProfessional], true)) {
                        SubscribeMetaMessages::dispatch((string) $connection->getKey());
                    }

                    // A WhatsApp number sends and receives nothing until its
                    // WABA subscribes the app, the number is registered and
                    // the whatsapp_numbers row routes it to this tenant.
                    if ($provider === SocialProvider::WhatsApp && $account->wabaId !== null) {
                        OnboardWhatsAppNumber::dispatch((string) $connection->getKey(), $account->wabaId);
                    }

                    $this->audit->log('social.connected', $connection, [
                        'provider' => $provider->value,
                        'display_name' => $connection->display_name,
                        'handle' => $connection->handle,
                        'scope_type' => $connection->scope_type,
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

        // The whole process, not just the token (Jose, 2026-11-12): default
        // choices, then the pass/fail check the card shows. After the commit,
        // so a slow provider holds no transaction open. Every row here came
        // from one grant, so one provider call answers for all of them: a
        // Google consent can bring forty-six.
        $this->setup->afterConnect($saved);

        return $saved;
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

    private function page(
        string $heading,
        string $sentence,
        int $status,
        ?SocialProvider $provider = null,
    ): Response {
        $ok = $status === Status::HTTP_OK;

        // How far the wire gets. A refusal draws a line that stops short and
        // goes red — the picture says the same thing the sentence does, which
        // is the whole point of having a picture.
        $reach = $ok ? '100%' : '46%';
        $wire = $ok ? 'var(--cyan)' : '#C2412D';

        // The palette is the client's, transcribed. This page cannot read
        // EzBiziTheme — it is server-rendered HTML in a popup, outside Flutter
        // entirely — so the hexes live here and nowhere else in PHP. If the
        // brand moves, `client/lib/core/theme.dart` is the original.
        $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>EzBizi — {$this->escape($heading)}</title>
        <style>
        :root {
          --cyan: #00AEEF; --ink: #060808; --muted: #5B6672;
          --bg: #F5F8FA; --surface: #fff; --line: #E9EDF1;
        }
        @media (prefers-color-scheme: dark) {
          :root {
            --ink: #fff; --muted: #97A3B0;
            --bg: #0B0F14; --surface: #10161B; --line: #1E262E;
          }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               padding: 2rem 1.25rem; background: var(--bg); color: var(--ink);
               font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 27rem; background: var(--surface);
               border: 1px solid var(--line); border-radius: 14px;
               padding: 2rem; text-align: center; }
        /* EzBizi ——— network. The two things being joined, and the joining.
           No wordmark above it: the cyan Ez tile already carries the brand, and
           two of them on one card is a logo competing with itself. */
        .link { display: flex; align-items: center; justify-content: center;
                margin: .25rem 0 1.5rem; }
        .node { position: relative; width: 58px; height: 58px; flex: none;
                border-radius: 17px; display: grid; place-items: center;
                background: var(--surface); border: 1px solid var(--line);
                box-shadow: 0 1px 3px rgba(6, 8, 8, .07);
                animation: pop .4s both; }
        .node svg { width: 31px; height: 31px; }
        .node.ez { background: var(--cyan); border-color: var(--cyan); color: #fff;
                   font-weight: 800; font-size: 21px; letter-spacing: -.5px; }
        .node.far { animation-delay: .55s; }
        /* Direct child only: the badge is an svg too, and greying the whole
           node took the red out of the cross that says it failed. */
        .node.far.cold > svg { filter: grayscale(1); opacity: .4; }

        .wire { position: relative; width: 84px; height: 3px; flex: none;
                background: var(--line); border-radius: 2px; }
        .wire i { position: absolute; top: 0; bottom: 0; left: 0; width: 0;
                  border-radius: 2px; background: {$wire};
                  animation: fill .75s .25s forwards cubic-bezier(.4, 0, .2, 1); }
        .wire b { position: absolute; top: 50%; left: 0; width: 8px; height: 8px;
                  margin: -4px 0 0 -4px; border-radius: 50%; background: var(--cyan);
                  opacity: 0; animation: travel 1.9s 1.2s infinite; }

        .badge { position: absolute; right: -7px; bottom: -7px; width: 24px;
                 height: 24px; border-radius: 50%; display: grid;
                 place-items: center; border: 2px solid var(--surface);
                 background: {$this->dotFill($ok)}; opacity: 0;
                 animation: pop .35s {$this->badgeDelay($ok)} both; }
        .badge svg { width: 12px; height: 12px; stroke: #fff; stroke-width: 3;
                     fill: none; stroke-linecap: round; stroke-linejoin: round; }

        @keyframes pop { from { opacity: 0; transform: scale(.75); }
                         to   { opacity: 1; transform: none; } }
        @keyframes fill { to { width: {$reach}; } }
        @keyframes travel { 0% { left: 0; opacity: 0; } 12% { opacity: 1; }
                            88% { opacity: 1; } 100% { left: 100%; opacity: 0; } }

        /* Motion is decoration here; the picture reads fine standing still. */
        @media (prefers-reduced-motion: reduce) {
          .node, .badge, .wire i { animation: none; opacity: 1; }
          .wire i { width: {$reach}; }
          .wire b { display: none; }
        }

        h1 { margin: 0 0 .5rem; font-size: 1.35rem; font-weight: 700;
             letter-spacing: -.2px; }
        p.said { margin: 0; color: var(--muted); }
        </style>
        </head>
        <body>
        <main>
        {$this->linkGraphic($ok, $provider)}
        <h1>{$this->escape($heading)}</h1>
        <p class="said">{$this->escape($sentence)}</p>
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

    private function dotFill(bool $ok): string
    {
        // Green and red are the only non-brand colours on the page, because a
        // refusal that looks the same as a success is worse than off-palette.
        return $ok ? '#1E9E62' : '#C2412D';
    }

    /** The badge lands after the wire has finished making its point. */
    private function badgeDelay(bool $ok): string
    {
        return $ok ? '.95s' : '.8s';
    }

    /**
     * EzBizi ——— the network being connected.
     *
     * The network's own mark is read from `client/assets/social`, the very same
     * file the Flutter catalogue draws. The whole repo ships to the box, so
     * there is no second copy of nine SVGs to keep in step — and no request
     * off this page, which is a popup that may be closed a second from now.
     */
    private function linkGraphic(bool $ok, ?SocialProvider $provider): string
    {
        $mark = $provider === null ? null : $this->networkMark($provider);

        if ($mark === null) {
            return '';
        }

        $cold = $ok ? '' : ' cold';
        $pulse = $ok ? '<b></b>' : '';

        return <<<HTML
        <div class="link" role="img" aria-label="EzBizi and {$this->escape($provider->label())}">
        <span class="node ez">Ez</span>
        <span class="wire"><i></i>{$pulse}</span>
        <span class="node far{$cold}">{$mark}<span class="badge">{$this->dotGlyph($ok)}</span></span>
        </div>
        HTML;
    }

    private function networkMark(SocialProvider $provider): ?string
    {
        $file = base_path('client/assets/social/'.$provider->routeKey().'.svg');

        if (! is_file($file)) {
            return null;
        }

        // Drop the XML prolog — it is only legal at the top of a document, and
        // this one is about to be inlined halfway down an HTML body.
        return preg_replace('/<\?xml.*?\?>\s*/s', '', (string) file_get_contents($file));
    }

    private function dotGlyph(bool $ok): string
    {
        return $ok
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    }
}
