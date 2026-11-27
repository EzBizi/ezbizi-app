<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Support\Web\SeoHelper\PageAnalysis;
use App\Support\Web\SeoHelper\SiteOverview;
use App\Support\Web\SeoHelper\SiteSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The SEO Helper: each page's search title, description and focus keyphrase,
 * checked and edited from EzBizi. Jose, 2026-09-28, in place of A1's editor.
 *
 * The website keeps the values (its SEO plugin prints them); EzBizi reads and
 * writes them through the site's plugin and keeps only a ten-minute copy of the
 * page list, so the overview does not re-read a thousand pages on every look.
 */
class SeoHelperController extends Controller
{
    private const TTL = 600;

    public function __construct(private readonly SiteSeo $seo) {}

    /** GET /api/v1/web/sites/{site}/seo-helper — the whole site's health, and every page. */
    public function index(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return $this->guard($site, function () use ($request, $site) {
            $all = $this->pages($site, $request->boolean('fresh'));

            return ['data' => SiteOverview::build($all['pages'], $all['site'])];
        });
    }

    /** GET /api/v1/web/sites/{site}/seo-helper/{page} — one page, checked against its live copy. */
    public function show(WebSite $site, int $page): JsonResponse
    {
        $this->authorize('view', $site);

        return $this->guard($site, function () use ($site, $page) {
            $row = $this->seo->one($site, $page);

            return ['data' => $this->withAnalysis($site, $row, SiteOverview::analysable($row))];
        });
    }

    /**
     * POST /api/v1/web/sites/{site}/seo-helper/{page}/analyze — a draft, checked
     * as it would print, without saving. The screen calls this as somebody types.
     */
    public function analyze(Request $request, WebSite $site, int $page): JsonResponse
    {
        $this->authorize('view', $site);
        $input = $this->draft($request);

        return $this->guard($site, function () use ($site, $page, $input) {
            $row = $this->seo->one($site, $page);
            $printed = $this->seo->preview($site, $page, $input['title'] ?? $row['seo']['title'], $input['description'] ?? $row['seo']['description']);
            $row['seo'] = array_merge($row['seo'], $input);
            $row['final'] = ['title' => (string) ($printed['title'] ?? ''), 'description' => (string) ($printed['description'] ?? '')];

            return ['data' => $this->withAnalysis($site, $row, SiteOverview::analysable($row))];
        });
    }

    /** PUT /api/v1/web/sites/{site}/seo-helper/{page} — saves the fields sent; an empty one is cleared. */
    public function update(Request $request, WebSite $site, int $page): JsonResponse
    {
        $this->authorize('update', $site);
        $input = $this->draft($request);

        // A title or description that cannot be meant is refused before it prints
        // on the site; analyze still shows it as a draft.
        foreach (['title', 'description', 'social_title', 'social_description', 'twitter_title', 'twitter_description'] as $field) {
            if (PageAnalysis::malformed((string) ($input[$field] ?? ''))) {
                throw ValidationException::withMessages([$field => 'That is not a '.str_replace(['twitter_', '_'], ['X ', ' '], $field).'. Write words, or leave it empty to use the default.']);
            }
        }

        return $this->guard($site, function () use ($site, $page, $input) {
            $row = $this->seo->save($site, $page, $input);
            Cache::forget($this->key($site));

            return ['data' => $this->withAnalysis($site, $row, SiteOverview::analysable($row))];
        });
    }

    /**
     * PUT /api/v1/web/sites/{site}/seo-helper/site — the site's default titles,
     * as a site issue's `fix` suggests (a year written out → the year variable).
     */
    public function updateSite(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);
        $input = $request->validate([
            'page_title_template' => ['sometimes', 'string', 'max:300'],
            'post_title_template' => ['sometimes', 'string', 'max:300'],
            'social_page_title_template' => ['sometimes', 'nullable', 'string', 'max:300'],
            'social_post_title_template' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        return $this->guard($site, function () use ($site, $input) {
            $this->seo->saveSite($site, $input);
            $all = $this->pages($site, true);

            return ['data' => SiteOverview::build($all['pages'], $all['site'])];
        });
    }

    /** @return array<string, mixed> */
    private function draft(Request $request): array
    {
        return $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'keyphrase' => ['sometimes', 'nullable', 'string', 'max:191'],
            'noindex' => ['sometimes', 'boolean'],
            'canonical' => ['sometimes', 'nullable', 'url', 'max:500'],
            'social_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'social_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'social_image' => ['sometimes', 'nullable', 'url', 'max:500'],
            'twitter_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'twitter_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'twitter_image' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);
    }

    /** The page as saved or drafted, with its checks against the live page and the rest of the site. */
    private function withAnalysis(WebSite $site, array $row, array $page): array
    {
        $others = $this->pages($site)['pages'];

        $sharing = SiteOverview::sharingPages($row, $others);

        $analysis = PageAnalysis::run($page, $this->html($site, (string) $page['url']), array_map('count', $sharing));

        return $row + [
            'analysis' => $analysis + [
                // The overview's score for this page: the same rules over the same
                // checks, so the list and the page never disagree about the same thing.
                'metadata_score' => PageAnalysis::score(PageAnalysis::metadataChecks($analysis['seo'])),
                'rules' => PageAnalysis::RULES,
                'analyzed_at' => now()->toIso8601String(),
            ],
            // The pages behind a "shared" check, so the screen can name and open them.
            'shared_with' => $sharing,
        ];
    }

    /** @return array{pages: list<array<string, mixed>>, site: array<string, mixed>} */
    private function pages(WebSite $site, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->key($site));
        }

        return Cache::remember($this->key($site), self::TTL, fn () => $this->seo->all($site));
    }

    /**
     * The page as a search engine gets it, or null when it cannot be read.
     * Kept a few minutes: a draft is re-checked on every pause in typing, and
     * the page itself does not change while somebody types a title.
     */
    private function html(WebSite $site, string $url): ?string
    {
        // Only this site's own pages, and no redirects (audit 2026-11-27, SSRF):
        // the URL comes from the customer's WordPress, so it could name any host.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $own = strtolower(preg_replace('/^www\./', '', (string) $site->host));

        if (preg_match('~^https?://~i', $url) !== 1 || preg_replace('/^www\./', '', $host) !== $own) {
            return null;
        }

        return Cache::remember('seo-helper:html:'.sha1($url), 300, function () use ($url) {
            try {
                $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['User-Agent' => 'EzBizi SEO Helper'])->get($url);

                return $response->successful() ? $response->body() : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    private function key(WebSite $site): string
    {
        return 'seo-helper:pages:'.$site->getKey();
    }

    /**
     * A website we do not run answering badly is a 502 with a sentence, as in
     * ContentController; a page it says does not exist is our 404. `reason` and
     * `site_status` tell a missing page from a refused account (Rodrigo's audit F8).
     */
    private function guard(WebSite $site, callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException $e) {
            $status = (int) $e->getCode();
            $reason = match (true) {
                $status === 404 => 'not_found',
                in_array($status, [401, 403], true) => 'refused',
                $status === 0 => 'unreachable',
                default => 'site_error',
            };

            return response()->json(['message' => $e->getMessage(), 'reason' => $reason, 'site_status' => $status ?: null],
                $status === 404 ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_GATEWAY);
        }
    }
}
