<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\PageFields;
use App\Support\Web\SeoFields;
use App\Support\Web\ValueResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What one site publishes — the SEO panel behind Web Management.
 *
 * ── One PUT for the whole panel, not a save button per field ──
 *
 * The panel is a form of eight boxes somebody fills in once and edits rarely.
 * A per-field endpoint would mean eight requests, eight ways to half-save, and
 * a screen that has to reconcile which of them failed. The whole map arrives
 * together and lands in one transaction.
 *
 * ── Blank clears the value, and that is not the same as leaving it alone ──
 *
 * A key sent as null or an empty string DELETES the row, so the plugin renders
 * nothing rather than an empty string that looks like a bug on a live page. A
 * key not sent at all is untouched. That distinction is why the payload is a
 * map rather than a list of fields.
 *
 * ── Per-page overrides exist in the data and not yet on the screen ──
 *
 * `path` is stored and served, so a page override is one row and the plugin
 * already prefers it. Nothing in the client writes one yet; when that screen
 * arrives it needs no migration and no change here.
 */
class ValuesController extends Controller
{
    /** GET /api/v1/web/sites/{site}/values */
    public function index(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json([
            'data' => [
                'fields' => [...SeoFields::catalogue(), ...PageFields::catalogueFor($site)],
                ...ValueResolver::forEditor(
                    $this->rows($site),
                    ValueResolver::inheritedRowsFor($site),
                ),
            ],
        ]);
    }

    /** PUT /api/v1/web/sites/{site}/values */
    public function update(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'path' => ['sometimes', 'string', 'max:191'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * Every key must be one this build knows how to render. An unknown key
         * would be stored happily and then be a shortcode nobody wrote, which
         * on a live page is indistinguishable from a value somebody forgot to
         * fill in.
         */
        $path = $this->normalizePath($input['path'] ?? '');

        /*
         * Every key must be one this build knows how to render. An unknown key
         * would be stored happily and then be a shortcode nobody wrote, which
         * on a live page is indistinguishable from a value somebody forgot to
         * fill in.
         *
         * The exception is a page's own free-text fields, which are invented by
         * the customer and rendered because the plugin registers a shortcode
         * for every key the server sends. They are only accepted ON a page: a
         * site-wide `home_field_1` would be a shortcode whose name claims a
         * page it does not belong to.
         */
        $unknown = array_values(array_filter(
            array_diff(array_keys($input['values']), SeoFields::keys()),
            fn (string $key) => ! ($path !== '' && PageFields::isCustomKey($key)),
        ));

        if ($unknown !== []) {
            return response()->json([
                'message' => 'This build does not publish '.implode(', ', $unknown).'.',
            ], 422);
        }

        /*
         * A ceiling, because every one of these is a shortcode the plugin
         * registers on every page load of the customer's site.
         */
        $custom = array_filter(array_keys($input['values']), PageFields::isCustomKey(...));

        if (count($custom) > PageFields::MAX_PER_PAGE) {
            return response()->json([
                'message' => 'A page can hold at most '.PageFields::MAX_PER_PAGE.' text fields.',
            ], 422);
        }

        DB::transaction(function () use ($site, $path, $input): void {
            foreach ($input['values'] as $key => $value) {
                $value = is_string($value) ? trim($value) : null;

                if ($value === null || $value === '') {
                    WebValue::query()
                        ->where('site_id', $site->getKey())
                        ->where('path', $path)
                        ->where('key', $key)
                        ->delete();

                    continue;
                }

                WebValue::query()->updateOrCreate(
                    ['site_id' => $site->getKey(), 'path' => $path, 'key' => $key],
                    ['value' => $value],
                );
            }
        });

        return response()->json([
            'data' => [
                'fields' => SeoFields::catalogue(),
                ...ValueResolver::forEditor(
                    $this->rows($site),
                    ValueResolver::inheritedRowsFor($site),
                ),
            ],
        ]);
    }

    /**
     * A page path as the plugin will compare it: leading slash, no trailing
     * slash, no host, no query. `/contact/` and `contact?utm=x` are the same
     * page and must not become two rows that disagree.
     */
    private function normalizePath(string $raw): string
    {
        $path = trim($raw);

        if ($path === '' || $path === '/') {
            return '';
        }

        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        $path = '/'.trim($path, '/');

        return $path === '/' ? '' : strtolower($path);
    }

    /**
     * Every value row for this site, site-wide and per-page together.
     *
     * One query rather than the two this used to make, because ValuePayload
     * splits them — and because two queries could disagree if somebody saved
     * between them.
     *
     * @return \Illuminate\Support\Collection<int, WebValue>
     */
    private function rows(WebSite $site): \Illuminate\Support\Collection
    {
        return WebValue::query()
            ->where('site_id', $site->getKey())
            ->get(['path', 'key', 'value']);
    }
}
