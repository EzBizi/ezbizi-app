<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\SeoFields;
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
                'fields' => SeoFields::catalogue(),
                'values' => $this->mapFor($site, ''),
                'pages' => $this->pageMaps($site),
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
        $unknown = array_diff(array_keys($input['values']), SeoFields::keys());

        if ($unknown !== []) {
            return response()->json([
                'message' => 'This build does not publish '.implode(', ', $unknown).'.',
            ], 422);
        }

        $path = $this->normalizePath($input['path'] ?? '');

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
                'values' => $this->mapFor($site, ''),
                'pages' => $this->pageMaps($site),
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

    /** @return array<string, string> */
    private function mapFor(WebSite $site, string $path): array
    {
        return WebValue::query()
            ->where('site_id', $site->getKey())
            ->where('path', $path)
            ->pluck('value', 'key')
            ->all();
    }

    /** @return array<string, array<string, string>> */
    private function pageMaps(WebSite $site): array
    {
        return WebValue::query()
            ->where('site_id', $site->getKey())
            ->where('path', '!=', '')
            ->get(['path', 'key', 'value'])
            ->groupBy('path')
            ->map(fn ($rows) => $rows->pluck('value', 'key')->all())
            ->all();
    }
}
