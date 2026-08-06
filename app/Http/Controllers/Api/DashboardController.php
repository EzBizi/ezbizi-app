<?php

namespace App\Http\Controllers\Api;

use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The dashboard (plan §18 Phase 4).
 *
 * Three endpoints and no fixed layout: what somebody sees is their own
 * preference (§2.1 category 6), the widgets available are whatever the registry
 * holds and their permissions allow, and the data is computed per request.
 *
 * The plan is explicit that dashboards come AFTER modules produce data worth
 * showing — "a page of empty widgets teaches you nothing about whether the
 * foundation works". Customers and Jobs produce it now.
 */
class DashboardController extends Controller
{
    public function __construct(private WidgetRegistry $registry) {}

    /**
     * What can be put on a dashboard, and what each one can be asked.
     *
     * The configuration UI is built from this, so a widget added tomorrow is
     * configurable tomorrow without a client release.
     */
    public function catalogue(Request $request): array
    {
        $user = $request->user();

        return [
            'widgets' => array_map(fn (Widget $w) => [
                'key'     => $w->key(),
                'label'   => $w->label(),
                'options' => $w->options($user),
            ], $this->registry->availableTo($user)),
        ];
    }

    /** The user's chosen widgets, with their data. */
    public function index(Request $request): array
    {
        $user = $request->user();

        $panels = [];

        foreach ($this->layoutFor($request) as $entry) {
            $widget = $this->registry->find($entry['key'], $user);

            // Silently skipped rather than errored. A layout outlives the
            // permission that allowed it: somebody moved between roles, or a
            // module was switched off platform-wide, and their dashboard should
            // quietly show less rather than refuse to load.
            if ($widget === null) {
                continue;
            }

            $panels[] = [
                'key'   => $widget->key(),
                'label' => $widget->label(),

                // Echoed back, or the client has nothing to preserve when it
                // saves a changed selection — it read `options` off these panels
                // and got null every time, so turning one panel on wiped every
                // other panel's configuration.
                'options' => $entry['options'] ?? [],

                'data'  => $widget->resolve($user, $entry['options'] ?? []),
            ];
        }

        return ['panels' => $panels];
    }

    /**
     * Save a layout.
     *
     * Validated against the registry rather than stored as given — a preference
     * is user input that happens to have been saved earlier, and the widget keys
     * come back as a database read that the resolver would otherwise trust.
     */
    public function saveLayout(Request $request): array
    {
        $user = $request->user();

        $input = $request->validate([
            'widgets'             => ['present', 'array', 'max:20'],
            'widgets.*.key'       => ['required', 'string'],
            'widgets.*.options'   => ['array'],
        ]);

        $layout = [];

        foreach ($input['widgets'] as $entry) {
            $widget = $this->registry->find($entry['key'], $user);

            if ($widget === null) {
                throw ValidationException::withMessages([
                    'widgets' => "There is no widget called '{$entry['key']}' that you can see.",
                ]);
            }

            // Only declared options survive. Anything else would be stored and
            // handed straight back to resolve() on every later request.
            $declared = $widget->options($user);

            $layout[] = [
                'key'     => $widget->key(),
                'options' => array_intersect_key($entry['options'] ?? [], $declared),
            ];
        }

        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['dashboard.widgets' => $layout]),
        ])->save();

        return ['widgets' => $layout];
    }

    /**
     * What to render when somebody has never chosen.
     *
     * A default rather than an empty page: a new organization's first sight of
     * the product should not be a blank screen with a "configure me" button.
     */
    private function layoutFor(Request $request): array
    {
        $user   = $request->user();
        $stored = $user->preferences['dashboard.widgets'] ?? null;

        if (is_array($stored)) {
            return $stored;
        }

        return array_map(
            fn (Widget $w) => ['key' => $w->key(), 'options' => []],
            $this->registry->availableTo($user),
        );
    }
}
