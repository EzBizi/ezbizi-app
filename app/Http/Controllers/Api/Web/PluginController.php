<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\SeoFields;
use App\Support\Web\ValuePayload;
use App\Support\Tenancy\TenantContext;
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
    ) {}

    /** GET /api/v1/web/plugin/values */
    public function values(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->refuse('This site has no EzBizi token yet.');
        }

        /*
         * No tenant is known until the token resolves — the row is what names
         * the organization. Same shape as the invitation and OAuth callbacks:
         * find without a tenant, then enter one for everything after.
         */
        $site = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()
                ->where('token_hash', hash('sha256', $token))
                ->with('company:id,name,organization_id')
                ->first(),
        );

        if ($site === null) {
            return $this->refuse('That token is not valid. Rotate it in EzBizi and paste the new one.');
        }

        $reported = $request->query('host');

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

        $organization = $this->tenant->runWithoutTenant(
            fn () => $site->organization,
        );

        return $this->tenant->run($organization, function () use ($site, $request): JsonResponse {
            $rows = WebValue::query()
                ->where('site_id', $site->getKey())
                ->get(['path', 'key', 'value']);

            $version = $request->query('plugin_version');

            $site->forceFill([
                'connected_at' => $site->connected_at ?? now(),
                'last_seen_at' => now(),
                'plugin_version' => is_string($version) ? substr($version, 0, 32) : $site->plugin_version,
            ])->save();

            return response()->json([
                'site' => [
                    'host' => $site->host,
                    'label' => $site->label,
                    'company' => $site->company?->name,
                ],
                'fields' => SeoFields::catalogue(),
                ...ValuePayload::forRows($rows),
            ]);
        });
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
