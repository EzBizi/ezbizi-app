<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Branding\Branding;
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

        WebWidget::query()->updateOrCreate(
            ['site_id' => $site->getKey()],
            ['config' => $clean],
        );

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
