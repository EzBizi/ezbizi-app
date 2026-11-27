<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\Hours\WebsiteHours;
use App\Support\Web\Hours\WeekEdit;
use App\Support\Web\HoursWeek;
use App\Support\Web\PageFields;
use App\Support\Web\SeoFields;
use App\Support\Web\SiteRefresh;
use App\Support\Web\ValueResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'data' => $this->editorPayload($site),
        ]);
    }

    /** PUT /api/v1/web/sites/{site}/values */
    public function update(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'path' => ['sometimes', 'string', 'max:191'],
            // Required unless the request only changes `not_needed`, which may be [].
            'values' => [Rule::requiredIf(! $request->has('not_needed')), 'array'],
            // The whole list of fields this site marks "Not needed" (Jose, 2026-11-09).
            'not_needed' => ['sometimes', 'array'],
            'not_needed.*' => ['string', 'distinct'],
            'values.*' => ['nullable', 'string', 'max:2000'],
            // The zone `values.hours` was typed in; converted into the site's own.
            'hours_timezone' => ['nullable', 'timezone:all'],
        ]);

        /*
         * Every key must be one this build knows how to render. An unknown key
         * would be stored happily and then be a shortcode nobody wrote, which
         * on a live page is indistinguishable from a value somebody forgot to
         * fill in.
         */
        $path = $this->normalizePath($input['path'] ?? '');
        $input['values'] ??= [];

        if (array_key_exists('not_needed', $input)) {
            $bad = array_diff($input['not_needed'], SeoFields::keys());

            if ($bad !== []) {
                return response()->json(['message' => 'This build has no field '.implode(', ', $bad).'.'], 422);
            }

            $site->not_needed_fields = array_values($input['not_needed']) ?: null;
            $site->save();
        }

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
            fn (string $key) => ! (
                ($path !== '' && PageFields::isCustomKey($key))
                /*
                 * A template is site-wide and only site-wide. Allowed on a page
                 * it would be a shortcode meaning different things in different
                 * places, which is exactly what somebody reaches for a template
                 * to avoid — and they would find out by reading two pages side
                 * by side rather than from any error.
                 */
                || ($path === '' && PageFields::isTemplateKey($key))
            ),
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

        $templates = array_filter(array_keys($input['values']), PageFields::isTemplateKey(...));

        if (count($templates) > PageFields::MAX_TEMPLATES) {
            return response()->json([
                'message' => 'A site can hold at most '.PageFields::MAX_TEMPLATES.' templates.',
            ], 422);
        }

        if (($input['hours_timezone'] ?? null) !== null && trim((string) ($input['values']['hours'] ?? '')) !== '') {
            $converted = $this->convertHours($site, $input);

            if (is_string($converted)) {
                $input['values']['hours'] = $converted;
            } else {
                return $converted;
            }
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

        // The website reads its new values now rather than at its next five-minute
        // refresh — and when it is the primary, so do the sites that inherit from it.
        SiteRefresh::later(...($site->is_primary
            ? WebSite::query()->where('company_id', $site->company_id)->get()->all()
            : [$site]));

        return response()->json([
            'data' => $this->editorPayload($site),
        ]);
    }

    /**
     * The hours as typed in another zone, as this site's own wall clock.
     *
     * The bulk editor's conversion (WeekEdit), for one site: Rodrigo asked for
     * the same "Convert hours from" on a single site's hours.
     */
    private function convertHours(WebSite $site, array $input): string|JsonResponse
    {
        $week = HoursWeek::parse(trim($input['values']['hours']));
        $zone = trim((string) ($input['values']['timezone'] ?? (new WebsiteHours)->effective($site, 'timezone')));

        if ($week === null) {
            return response()->json(['message' => 'Only hours set day by day can be converted.'], 422);
        }

        if ($zone === '') {
            return response()->json(['message' => 'This site has no time zone, so its hours cannot be converted. Set its time zone first.'], 422);
        }

        $plan = WeekEdit::plan(
            null,
            array_map(fn (array $d) => $d['closed']
                ? ['action' => WeekEdit::CLOSED, 'open' => null, 'close' => null]
                : ['action' => WeekEdit::SET, 'open' => $d['open'], 'close' => $d['close']], $week),
            $zone,
            $input['hours_timezone'],
            null,
            now()->toDateTimeImmutable(),
        );

        return $plan['week'] === null
            ? response()->json(['message' => implode(' ', $plan['reasons'])], 422)
            : HoursWeek::format($plan['week']);
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
     * The editor's view of this site's values, with the derived ones filled in.
     *
     * One method rather than two call sites, because the GET and the PUT both
     * return this shape and the PUT's copy is the one that would quietly fall
     * behind.
     *
     * @return array<string, mixed>
     */
    private function editorPayload(WebSite $site): array
    {
        $payload = ValueResolver::forEditor(
            $this->rows($site),
            ValueResolver::inheritedRowsFor($site),
        );

        $payload['values'] = (object) ValueResolver::withWebsiteUrl(
            (array) $payload['values'],
            $site->host,
        );

        return [
            'fields' => [...SeoFields::catalogue(), ...PageFields::catalogueFor($site)],
            ...$payload,
            'not_needed' => $site->not_needed_fields ?? [],
        ];
    }

    /**
     * Every value row for this site, site-wide and per-page together.
     *
     * One query rather than two, because ValueResolver splits them — and
     * because two queries could disagree if somebody saved between them.
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
