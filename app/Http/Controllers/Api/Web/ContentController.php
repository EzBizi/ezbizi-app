<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\PageKinds;
use App\Support\Web\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pages and posts on a customer's website.
 *
 * Read live from the site every time. Nothing is stored, so there is no
 * migration here and nothing to keep in step — the website is the record and
 * this is a window onto it.
 */
class ContentController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    /** GET /api/v1/web/sites/{site}/content */
    public function index(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        $input = $request->validate([
            'type' => ['required', 'string', 'in:page,post'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            /*
             * City pages belong to the Cities pane, not here. Two exclusions,
             * because there are two ways to be one (see `PageKinds`):
             *
             *   the AUTHOR signal, handed to WordPress so its own count and
             *   its own pager agree with what comes back;
             *
             *   the VALUES signal, applied after, for the page that has a city
             *   typed into it but was authored by the brand account. That is
             *   rare on the fleet — she works on author-filed pages — so it
             *   removes a row or two from a batch rather than emptying it.
             *
             * Posts are not filtered at all. A post is not a city page and
             * asking a customer's site to exclude authors from its blog would
             * be a question about pages asked of the wrong list.
             */
            $isPages = $input['type'] === 'page';

            $batch = $this->content->list(
                $site,
                $input['type'],
                (int) ($input['page'] ?? 1),
                (string) ($input['search'] ?? ''),
                $isPages ? $this->content->cityAuthorIds($site) : [],
            );

            if ($isPages) {
                $batch = $this->withoutCityValues($site, $batch);
            }

            return response()->json($batch);
        } catch (RuntimeException $e) {
            /*
             * 502 rather than 500: nothing here is broken, a machine we do not
             * run gave an answer we cannot use. The sentence is written for the
             * person looking at the screen and says which website, because
             * somebody managing six of them needs to know which one to go and
             * look at.
             */
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    /**
     * The same batch with any page Cities already claims by its values taken
     * out, and said out loud in `moved_to_cities` so the screen can explain a
     * short list rather than leaving somebody counting.
     *
     * @param  array{items: array<int, array<string, mixed>>, total: int, pages: int}  $batch
     * @return array<string, mixed>
     */
    private function withoutCityValues(WebSite $site, array $batch): array
    {
        $cityPaths = PageKinds::cityPathsFrom(
            WebValue::query()
                ->where('site_id', $site->getKey())
                ->where('key', 'city')
                ->where('path', '<>', '')
                ->get(['path', 'key', 'value']),
        );

        if ($cityPaths === []) {
            return [...$batch, 'moved_to_cities' => 0];
        }

        $kept = array_values(array_filter(
            $batch['items'],
            fn (array $item) => ! isset($cityPaths[PageKinds::pathOf((string) $item['link'])]),
        ));

        return [
            ...$batch,
            'items' => $kept,
            'moved_to_cities' => count($batch['items']) - count($kept),
        ];
    }

    /**
     * GET /api/v1/web/sites/{site}/cities
     *
     * The city pages of one site, grouped the way their owner already groups
     * them, for the Cities pane.
     *
     * ── Why this reads our own database and the Pages list does not ──
     *
     * Pages is a window onto the website because the website is the record of
     * what pages exist. A CITY page is a window onto something else: the facts
     * Toula has published about a town. Those are ours, all 8,956 of them, and
     * reading them here means the pane opens instantly, needs no credential,
     * and still works on the afternoon somebody's WordPress is down — which is
     * exactly when a person wants to check what EzBizi is publishing.
     *
     * ── `?scan=1` ──
     *
     * The other half of the job: which city pages exist on the website that
     * EzBizi holds nothing for. That needs the live list and the author names,
     * so it costs a round trip per fifty pages — thirteen of them on the
     * biggest site. An explicit request rather than part of the load, because a
     * pane that takes three seconds to open is a pane nobody opens.
     *
     * A failed scan is a NOTE on a successful response, never an error: the
     * values half has already succeeded by then, and replacing a list of 609
     * cities with a red box because a third-party site was slow would be
     * throwing away the answer to keep the footnote.
     */
    public function cities(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        $input = $request->validate([
            'scan' => ['sometimes', 'boolean'],
        ]);

        $rows = WebValue::query()
            ->where('site_id', $site->getKey())
            ->where('path', '<>', '')
            ->get(['path', 'key', 'value']);

        $byPath = [];

        foreach ($rows as $row) {
            if (trim((string) $row->value) !== '') {
                $byPath[(string) $row->path][(string) $row->key] = (string) $row->value;
            }
        }

        $cities = [];

        foreach ($byPath as $path => $values) {
            if (! PageKinds::valuesNameCity($values)) {
                continue;
            }

            $cities[$path] = [
                'path' => $path,
                'city' => $values['city'] ?? '',
                'state' => $values['state'] ?? '',
                'phone' => $values['phone'] ?? '',
                'group' => $values['group'] ?? '',
                // How much of this page somebody has answered. The pane sorts
                // the thin ones to the top of a group, because that is the
                // work — a city with a name and nothing else is the next job.
                'filled' => count($values),
                'in_ezbizi' => true,
                'title' => null,
            ];
        }

        $note = null;

        if ($input['scan'] ?? false) {
            [$cities, $note] = $this->scanForCities($site, $cities);
        }

        $cities = array_values($cities);

        usort($cities, fn (array $a, array $b) => [$a['group'] === '', $a['group'], $a['city'], $a['path']]
            <=> [$b['group'] === '', $b['group'], $b['city'], $b['path']]);

        return response()->json([
            'cities' => $cities,
            'scanned' => (bool) ($input['scan'] ?? false),
            'note' => $note,
        ]);
    }

    /**
     * The website's own city pages folded in, marked by whether EzBizi holds
     * anything for them.
     *
     * @param  array<string, array<string, mixed>>  $cities  keyed by path
     * @return array{0: array<string, array<string, mixed>>, 1: string|null}
     */
    private function scanForCities(WebSite $site, array $cities): array
    {
        try {
            $authors = [];

            foreach ($this->content->options($site)['authors'] as $author) {
                $authors[(int) $author['id']] = (string) $author['name'];
            }

            $page = 1;
            $seen = 0;

            do {
                $batch = $this->content->list($site, 'page', $page);

                foreach ($batch['items'] as $item) {
                    $seen++;
                    $name = $authors[(int) ($item['author'] ?? 0)] ?? null;

                    if (! PageKinds::authorNamesCity($name)) {
                        continue;
                    }

                    $path = PageKinds::pathOf((string) $item['link']);

                    if (isset($cities[$path])) {
                        // Already hers. Only the title is new, and it is worth
                        // having: "Toms River, NJ" reads better in a list than
                        // the slug does.
                        $cities[$path]['title'] = $item['title'];

                        continue;
                    }

                    $cities[$path] = [
                        'path' => $path,
                        'city' => '',
                        'state' => '',
                        'phone' => '',
                        'group' => '',
                        'filled' => 0,
                        // The row that means "this town is on the website and
                        // EzBizi publishes nothing about it".
                        'in_ezbizi' => false,
                        'title' => $item['title'],
                    ];
                }

                $page++;

                /*
                 * A ceiling, because this walks somebody else's server. 24
                 * batches of fifty is 1,200 pages, and the biggest site on the
                 * estate has 609 — so it is a stop for a runaway rather than a
                 * limit anybody meets.
                 */
            } while ($page <= min((int) $batch['pages'], 24));

            return [$cities, $seen === 0 ? 'The website returned no pages.' : null];
        } catch (RuntimeException $e) {
            return [$cities, $e->getMessage()];
        }
    }

    /**
     * GET /api/v1/web/sites/{site}/content/options
     *
     * The authors and categories the SITE has, for the two dropdowns on a
     * page's own screen. Read live for the same reason the pages are: a list
     * typed in EzBizi would drift from the website the first time somebody left.
     */
    public function options(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        try {
            return response()->json($this->content->options($site));
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }
}
