<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Branding\Branding;
use App\Support\Web\SiteRefresh;
use App\Support\Web\WidgetDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading and writing how a site's widgets look and what they ask.
 *
 * The screen behind this is Web Management → Widgets. Everything a visitor sees
 * is decided here and shipped down with the values, so a shortcode on the
 * customer's site is `[ezbizi_chat]` and nothing else — changing the colour or
 * adding a question never means editing a WordPress page.
 */
class WidgetsController extends Controller
{
    /**
     * POST /api/v1/web/sites/{site}/widgets/chat — the chat, across a company.
     *
     * ── The morning this exists for ──
     *
     * Jose switched the live chat off on `a-1chimney.com` on 2026-09-23 and
     * went on receiving chats all day. Nothing was broken: the switch is per
     * SITE, A-1 Chimney has 47 of them, and the other 46 follow the primary —
     * which was still on. The enquiries were coming from a-1chimneysweep.com,
     * ny.a-1chimney.com and chi.a-1chimney.com, and every one of them read
     * "Website chat · A-1 Chimney" in Leads, so the screen could not tell him
     * either.
     *
     * A switch whose blast radius is one of forty-seven, on a screen that
     * shows one site at a time, is a switch somebody will believe covers the
     * business. So this covers the business.
     *
     * ── Why it writes a row for every site rather than only the primary ──
     *
     * Switching the primary off would carry every site that FOLLOWS it and
     * silently miss every site that has a config of its own — which is exactly
     * the shape of the bug being fixed, one level up. Writing each site is the
     * only version where "off everywhere" means off everywhere.
     */
    public function chat(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'scope' => ['required', 'in:site,company'],
        ]);

        $sites = $input['scope'] === 'company'
            ? WebSite::query()->where('company_id', $site->company_id)->get()
            : collect([$site]);

        foreach ($sites as $one) {
            // Each site individually, because `update` is a per-record
            // permission and a company-wide action must not reach a site this
            // person could not have opened on their own.
            $this->authorize('update', $one);
        }

        foreach ($sites as $one) {
            $existing = WebWidget::query()->where('site_id', $one->getKey())->first();
            $config = $existing !== null && is_array($existing->config) ? $existing->config : [];
            $chat = isset($config['chat']) && is_array($config['chat']) ? $config['chat'] : [];

            $chat['enabled'] = $input['enabled'];
            $config['chat'] = $chat;

            WebWidget::query()->updateOrCreate(
                ['site_id' => $one->getKey()],
                ['config' => WidgetDefaults::resolve($config)],
            );
        }

        return response()->json([
            'changed' => $sites->count(),
            'enabled' => $input['enabled'],
            ...WebWidget::forEditor($site->fresh()),
            'defaults' => WidgetDefaults::all($this->brand($site)),
            'brand' => $this->brand($site),
            'field_types' => WidgetDefaults::FIELD_TYPES,
        ]);
    }

    /**
     * POST /api/v1/web/sites/{site}/widgets/refresh — ask the sites to redraw.
     *
     * ── What this can and cannot promise ──
     *
     * EzBizi cannot reach into a customer's website. The plugin PULLS, every
     * five minutes, and since 1.13.0 it clears the site's page cache whenever
     * what it pulled differs from what it had. So a saved change already
     * reaches visitors on its own — the lag is that pull.
     *
     * This shortens nothing and admits it. What it does is GUARANTEE the next
     * pull counts as a change, by stamping the config, which matters when the
     * cached page is stale for a reason EzBizi never saw — somebody editing
     * WordPress directly, or a value changed here before 1.13.0 existed.
     *
     * So the response says how many sites were asked and the screen says "in
     * the next few minutes". A button that claimed the cache was cleared would
     * be claiming something this side cannot observe.
     */
    public function refresh(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'scope' => ['required', 'in:site,company'],
        ]);

        $sites = $input['scope'] === 'company'
            ? WebSite::query()->where('company_id', $site->company_id)->get()
            : collect([$site]);

        foreach ($sites as $one) {
            $this->authorize('update', $one);
        }

        foreach ($sites as $one) {
            $existing = WebWidget::query()->where('site_id', $one->getKey())->first();
            $config = $existing !== null && is_array($existing->config) ? $existing->config : [];

            /*
             * OUTSIDE the resolved shape on purpose. `WidgetDefaults::resolve()`
             * keeps only keys it knows, so a stamp put inside would be dropped
             * on the next save and this button would quietly stop working —
             * the failure being that nothing happens, which nobody reports.
             */
            $config['refreshed_at'] = now()->timestamp;

            WebWidget::query()->updateOrCreate(
                ['site_id' => $one->getKey()],
                ['config' => $config],
            );
        }

        // "Rebuild" means now: each site re-reads and drops its cached pages.
        SiteRefresh::later(...$sites->all());

        return response()->json(['asked' => $sites->count()]);
    }

    /** GET /api/v1/web/sites/{site}/widgets */
    public function show(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json([
            ...WebWidget::forEditor($site),
            'defaults' => WidgetDefaults::all($this->brand($site)),
            /*
             * So the screen can say WHERE an untouched accent came from. Sent
             * with the config rather than fetched separately: it is already
             * resolved here to build the defaults, and a second request for one
             * string would be a second thing to fail.
             */
            'brand' => $this->brand($site),
            'field_types' => WidgetDefaults::FIELD_TYPES,
        ]);
    }

    /**
     * PUT /api/v1/web/sites/{site}/widgets
     *
     * The body is stored as given and read back through the sanitiser, rather
     * than being validated field by field here. Two reasons: the shape has
     * nested parts whose rules live in WidgetDefaults already, and a config
     * written by an older version of the screen must keep working — so the
     * sanitiser is the one gate, and it runs on the way out as well as in.
     */
    public function update(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'config' => ['required', 'array'],
        ]);

        $clean = WidgetDefaults::resolve($input['config']);

        /*
         * Carry the server's own keys across the save.
         *
         * `resolve()` keeps only what it knows, which is exactly right for
         * anything the CLIENT sends — and wrong for `refreshed_at`, which this
         * side writes and no client ever sends. Without this the refresh
         * button worked once and stopped the next time somebody edited a
         * colour, silently, which is the failure nobody reports.
         *
         * A test predicted this and then caught it, because the prediction was
         * in a docblock and the protection was not: putting the stamp outside
         * the resolved shape keeps `resolve()` from MANGLING it and does
         * nothing about a save that replaces the whole row.
         */
        $existing = WebWidget::query()->where('site_id', $site->getKey())->first();

        foreach (['refreshed_at'] as $ours) {
            if ($existing !== null && is_array($existing->config) && isset($existing->config[$ours])) {
                $clean[$ours] = $existing->config[$ours];
            }
        }

        WebWidget::query()->updateOrCreate(
            ['site_id' => $site->getKey()],
            ['config' => $clean],
        );

        // A primary's look is inherited, so its company's sites read it too.
        SiteRefresh::company($site);

        return response()->json([
            ...WebWidget::forEditor($site),
            'defaults' => WidgetDefaults::all($this->brand($site)),
            /*
             * So the screen can say WHERE an untouched accent came from. Sent
             * with the config rather than fetched separately: it is already
             * resolved here to build the defaults, and a second request for one
             * string would be a second thing to fail.
             */
            'brand' => $this->brand($site),
            'field_types' => WidgetDefaults::FIELD_TYPES,
        ]);
    }

    /**
     * DELETE /api/v1/web/sites/{site}/widgets
     *
     * Stop dressing this site yourself and follow the primary again. Not the
     * same as clearing every field: an empty config would still be this site's
     * own answer, and a blank one at that.
     */
    public function destroy(WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        WebWidget::query()->where('site_id', $site->getKey())->delete();
        SiteRefresh::later($site);

        return response()->json([
            ...WebWidget::forEditor($site),
            'defaults' => WidgetDefaults::all($this->brand($site)),
            /*
             * So the screen can say WHERE an untouched accent came from. Sent
             * with the config rather than fetched separately: it is already
             * resolved here to build the defaults, and a second request for one
             * string would be a second thing to fail.
             */
            'brand' => $this->brand($site),
            'field_types' => WidgetDefaults::FIELD_TYPES,
        ]);
    }

    /**
     * The colour this site's owner publishes under, if they have told us one.
     *
     * Null means genuinely unknown, which the screen says differently from a
     * colour somebody chose — a default presented as the customer's when it is
     * ours is the whole thing this feature exists to stop.
     */
    private function brand(WebSite $site): ?string
    {
        $site->loadMissing('company');

        return Branding::colour($site->company, $site->company?->organization);
    }
}
