<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Models\WebWidget;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\ChatPeople;
use App\Support\Web\PageFields;
use App\Support\Web\SeoFields;
use App\Support\Web\SiteLists;
use App\Support\Web\Turnstile;
use App\Support\Web\ValueResolver;
use App\Support\Web\WebsiteEnquiries;
use App\Support\Web\WidgetPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one endpoint the WordPress plugin talks to.
 *
 * Public, because a plugin on somebody else's server has no session and no
 * user — its bearer token IS the credential, and it is a token for one site.
 *
 * ── Why the whole payload comes back in one call ──
 *
 * The plugin caches this for a few minutes and renders from the cache, so a
 * page view costs the customer's site nothing and costs us nothing. Handing
 * back the field catalogue alongside the values means a field added here
 * becomes a working shortcode on every installed plugin at the next refresh,
 * with nobody updating anything — which is the difference between adding a
 * field and shipping a plugin release.
 *
 * ── The host check, and what it is actually for ──
 *
 * A token copied onto a second website would quietly publish one company's
 * phone number on a site nobody registered. When the plugin reports its own
 * host and that host is not this site's, the read is refused with a sentence
 * saying which host the token belongs to. A plugin too old to report a host is
 * served, because refusing it would break installs that pre-date the check;
 * that is a stated hole, not an oversight, and it closes when the floor moves.
 */
class PluginController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WebsiteEnquiries $enquiries,
    ) {}

    /**
     * POST /api/v1/web/plugin/form — a form the website drew itself (an
     * Elementor Pro form), sent on by the plugin from the server.
     *
     * Jose, 2026-09-28: the sites' other contact forms should reach Leads too.
     * Not through the public `web/form`: that is limited per ADDRESS, and every
     * one of these comes from the web host's one address, so a busy hour across
     * a hundred sites would start dropping leads. The site's own token is the
     * identity here, and the limit is per token.
     */
    public function form(Request $request): JsonResponse
    {
        $site = $this->siteForToken($request, $request->input('host'));

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:40'],
            'fields.*' => ['nullable', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'page_url' => ['nullable', 'string', 'max:500'],
            // From the form's ZIP question, or the box the plugin adds (2026-10-08).
            'zip' => ['nullable', 'string', 'max:20'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input): JsonResponse {
            $this->enquiries->submitForm($site, $input['fields'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'zip' => $input['zip'] ?? null,
                'page_url' => $input['page_url'] ?? null,
                // An Elementor form's own acceptance box says nothing about
                // marketing, so no consent is recorded from it.
                'marketing_consent' => false,
                'marketing_consent_text' => null,
            ]);

            return response()->json(['received' => true], Response::HTTP_CREATED);
        });
    }

    /** GET /api/v1/web/plugin/values */
    public function values(Request $request): JsonResponse
    {
        $site = $this->siteForToken($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $organization = $this->tenant->runWithoutTenant(
            fn () => $site->organization,
        );

        return $this->tenant->run($organization, function () use ($site, $request): JsonResponse {
            $rows = WebValue::query()
                ->where('site_id', $site->getKey())
                ->get(['path', 'key', 'value']);

            $version = $request->query('plugin_version');
            // 1/0 from a plugin that counts its booking widget (1.26.23+); absent from older ones.
            $booking = $request->query('has_booking');

            $site->forceFill([
                'connected_at' => $site->connected_at ?? now(),
                'last_seen_at' => now(),
                /*
                 * Back, so the next silence is reported as a new one. Cleared
                 * in the same write that records the sighting: the pair reads
                 * `last_seen_at` is the fact, `went_quiet_at` is whether
                 * anybody has been told about it.
                 */
                'went_quiet_at' => null,
                'plugin_version' => is_string($version) ? substr($version, 0, 32) : $site->plugin_version,
                'has_booking' => in_array($booking, ['0', '1'], true) ? $booking === '1' : $site->has_booking,
            ])->save();

            return response()->json([
                'site' => [
                    'host' => $site->host,
                    'label' => $site->label,
                    'company' => $site->company?->name,
                ],
                /*
                 * The site's own invented page fields ride along, because the
                 * plugin registers a shortcode for every key in this list —
                 * which is what makes `[ezbizi_home_field_1]` work on the
                 * customer's page with no plugin release.
                 */
                'fields' => [...SeoFields::catalogue(), ...PageFields::catalogueFor($site)],

                /*
                 * So the chat and form shortcodes can post as this site without
                 * a second thing for somebody to paste. Safe to hand the plugin
                 * and safe in page source: it starts an enquiry and reads
                 * published values, and nothing else.
                 */
                'public_key' => $site->public_key,

                /*
                 * The Turnstile box for this site's domain, or null. The secret
                 * rides along because Elementor's forms post to WordPress, not
                 * here, so the plugin checks those itself. This payload goes only
                 * to the site holding the token, never to a browser.
                 * ponytail: the secret is shared by every domain on the widget;
                 * a per-site proxy check if that ever matters.
                 */
                'turnstile' => Turnstile::for($site),

                /*
                 * The whole look and shape of the widgets, so a shortcode on
                 * the customer's site is `[ezbizi_chat]` and nothing else. It
                 * arrives with the values and refreshes with them, which is why
                 * changing a colour in EzBizi reaches the site without anybody
                 * editing a page or updating a plugin.
                 */
                // With the rules a per-widget override is checked by.
                'widgets' => [...self::withChatPeople(WebWidget::publishedFor($site), $site), 'schema' => WidgetPresets::schema()],
                // The lists the team, branches and store-locator widgets draw.
                'team' => SiteLists::team($site),
                'locations' => SiteLists::locations($site),
                'items' => SiteLists::items($site),
                'reviews' => SiteLists::reviews($site),
                /*
                 * So the plugin can revoke its own application password when
                 * EzBizi has let go of the pairing. Without this, unlinking here
                 * would leave a live credential on the customer's site that
                 * nothing on our side remembers.
                 */
                'service_account' => ['linked' => $site->isServiceLinked()],
                ...$this->published($site, $rows),
            ]);
        });
    }

    /**
     * POST /api/v1/web/plugin/service-account
     *
     * The site hands over an account EzBizi can publish with. Authenticated by
     * the same token the reads use, so a credential can only be attached to the
     * site that proved it holds that site's token.
     */
    public function serviceAccount(Request $request): JsonResponse
    {
        $site = $this->siteForToken($request, $request->input('host'));

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'user_login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
            'rest_url' => ['required', 'url', 'max:255'],
            'capabilities' => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * The REST endpoint must live on the host this token belongs to. Without
         * this a site could hand us a credential pointed at somebody else's
         * WordPress, and every later publish would be a request we make, with
         * our IP, against a third party who never asked for it.
         */
        $restHost = WebSite::normalizeHost((string) parse_url($input['rest_url'], PHP_URL_HOST));

        if ($restHost !== $site->host) {
            return response()->json([
                'message' => 'The REST address must be on '.$site->host.'.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input): JsonResponse {
            $site->forceFill([
                'service_user_login' => $input['user_login'],
                'service_password' => $input['password'],
                'service_rest_url' => $input['rest_url'],
                'service_capabilities' => $input['capabilities'] ?? null,
                'service_linked_at' => now(),
            ])->save();

            return response()->json(['linked' => true], Response::HTTP_CREATED);
        });
    }

    /**
     * The site a bearer token names, or the refusal to send instead.
     *
     * No tenant is known until the token resolves — the row is what names the
     * organization. Same shape as the invitation and OAuth callbacks: find
     * without a tenant, then enter one for everything after.
     */
    private function siteForToken(Request $request, ?string $reported = null): WebSite|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->refuse('This site has no EzBizi token yet.');
        }

        $site = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()
                ->where('token_hash', hash('sha256', $token))
                ->with('company:id,name,organization_id')
                ->first(),
        );

        if ($site === null) {
            return $this->refuse('That token is not valid. Rotate it in EzBizi and paste the new one.');
        }

        $reported ??= $request->query('host');

        if (is_string($reported) && trim($reported) !== '') {
            $reportedHost = WebSite::normalizeHost($reported);

            if ($reportedHost !== $site->host) {
                return $this->refuse(sprintf(
                    'This token belongs to %s, not %s. Register %s in EzBizi to give it its own token.',
                    $site->host,
                    $reportedHost,
                    $reportedHost,
                ));
            }
        }

        return $site;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * What the website publishes, with the derived values filled in.
     *
     * `website_url` is this site's own address and nobody types it — the host
     * is already the record, and a typed copy is a second version of one fact
     * that goes stale the day a domain changes. Derived on the way out, so
     * there is no row to drift.
     *
     * @return array{values: object, pages: object}
     */
    private function published(WebSite $site, $rows): array
    {
        $payload = ValueResolver::forReaders($rows, ValueResolver::inheritedRowsFor($site));

        $payload['values'] = (object) ValueResolver::withWebsiteUrl(
            (array) $payload['values'],
            $site->host,
        );

        return $payload;
    }

    /**
     * The chat's faces and company name, for the plugin only.
     *
     * They travel with the settings it reads every five minutes rather than
     * being asked for on every visitor's page view, and never on the public
     * endpoint, where a scraped key must not name the business.
     */
    private static function withChatPeople(array $published, WebSite $site): array
    {
        if (isset($published['chat'])) {
            $published['chat']['team'] = ChatPeople::team($site);
            $published['chat']['company'] = (string) ($site->company?->name ?? '');
        }

        return $published;
    }
}
