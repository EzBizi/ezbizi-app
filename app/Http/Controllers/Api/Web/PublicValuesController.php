<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\SeoFields;
use App\Support\Web\ValueResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a browser may read, for sites with nowhere to keep a secret.
 *
 * The WordPress plugin runs on a server and holds the secret token. A static
 * site, a hand-written page, a Squarespace block — none of them have anywhere
 * to put one, so they identify themselves with the site's PUBLIC key instead.
 *
 * ── This endpoint is world-readable and that is the design ──
 *
 * Everything it returns is already printed on the customer's own public
 * website. There is no version of "leaking" a phone number that is published in
 * the footer of the page making the request. So there is no auth, and the
 * response is cacheable and CORS-open.
 *
 * ── What it deliberately does NOT return ──
 *
 * The authenticated plugin endpoint answers with `site.host`, `site.label` and
 * `site.company`. None of those appear here. The values are public; the shape
 * of the account behind them is not, and a company NAME plus a key harvested
 * from page source would let somebody map which businesses use EzBizi and how
 * their sites are grouped. A browser needs the values and the field list to
 * render; it never needs to know whose account they came from.
 *
 * ── Why the key rather than the hostname ──
 *
 * Keying on `?host=` would have been simpler and would have let anyone read any
 * customer's values by guessing domains, and enumerate our customer list by
 * trying them. A random key is unguessable, and it can be rotated without the
 * customer changing their domain.
 */
class PublicValuesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/web/public/values?key=pk_... */
    public function show(Request $request): JsonResponse
    {
        $key = $request->query('key');

        /*
         * No key at all is a DIFFERENT answer from a key that does not match,
         * and the oracle argument below does not apply to it: nobody supplied
         * anything to probe with. Saying so plainly matters because the deploy
         * session read `404 {"message":"That key is not valid."}` on a request
         * with no key and reasonably concluded the ROUTE was missing — same
         * status, opposite meaning. 400 cannot be confused with the router.
         */
        if (! is_string($key) || $key === '') {
            return response()->json(
                ['message' => 'No key was given. Add ?key=pk_... from Web Management.'],
                Response::HTTP_BAD_REQUEST,
            )->withHeaders($this->browserHeaders());
        }

        /*
         * No tenant is known until the key resolves — the row names the
         * organization. Same shape as the plugin endpoint and the invitation
         * callbacks.
         */
        $site = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()->where('public_key', $key)->first(),
        );

        if ($site === null) {
            return $this->refuse();
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site): JsonResponse {
            $rows = WebValue::query()
                ->where('site_id', $site->getKey())
                ->get(['path', 'key', 'value']);

            return response()->json([
                'fields' => SeoFields::catalogue(),
                ...ValueResolver::forReaders($rows, ValueResolver::inheritedRowsFor($site)),
            ])->withHeaders($this->browserHeaders());
        });
    }

    /**
     * The same answer for a key that never existed and one that was rotated
     * away, for the reason the plugin endpoint gives: two different answers is
     * an oracle telling a caller which keys are real.
     */
    private function refuse(): JsonResponse
    {
        return response()->json(
            ['message' => 'That key is not valid.'],
            Response::HTTP_NOT_FOUND,
        )->withHeaders($this->browserHeaders());
    }

    /**
     * CORS and caching, which this endpoint needs and the plugin one does not.
     *
     * `*` is right rather than lazy: the callers are unknown websites we do not
     * host, the data is public, and there is no cookie or credential for a
     * permissive origin to be borrowed against.
     *
     * Five minutes matches the plugin's cache, so both clients see a value
     * change at about the same time and a support answer does not depend on
     * which one somebody used.
     *
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ];
    }
}
