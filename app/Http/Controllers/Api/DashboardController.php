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

            // Cleaned on the way OUT as well as on the way in. saveLayout below
            // will not store a value a widget never offered, but a layout saved
            // before it checked is still in the database and layoutFor() hands
            // it straight to resolve() — the KEY is already re-checked here for
            // exactly that reason, and the values were not.
            $options = $this->asDeclared($entry['options'] ?? [], $widget->options($user));

            $panels[] = [
                'key'   => $widget->key(),
                'label' => $widget->label(),

                // Echoed back, or the client has nothing to preserve when it
                // saves a changed selection — it read `options` off these panels
                // and got null every time, so turning one panel on wiped every
                // other panel's configuration.
                //
                // Echoed CLEANED, so that same save writes the corrected layout
                // back rather than handing the discarded value in again.
                'options' => $options,

                'data'  => $widget->resolve($user, $options),
            ];
        }

        return ['panels' => $panels];
    }

    /**
     * Save a layout.
     *
     * Validated against the registry rather than stored as given — a preference
     * is user input that happens to have been saved earlier, and the widget keys
     * come back as a database read that the resolver would otherwise trust. The
     * same is true of the option VALUES; asDeclared() is where that is done, and
     * index() applies it again on the way out because a layout stored before it
     * existed is still user input nobody has checked.
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

            $layout[] = [
                'key'     => $widget->key(),
                'options' => $this->asDeclared($entry['options'] ?? [], $widget->options($user)),
            ];
        }

        // Not audited, and the reason is PreferencesController's: this writes to
        // `user.preferences`, it is category 6, and §10 records what people do to
        // the BUSINESS. Said here because a write path with no audit call is
        // otherwise indistinguishable from one where somebody forgot.
        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['dashboard.widgets' => $layout]),
        ])->save();

        return ['widgets' => $layout];
    }

    /**
     * A widget's options, reduced to what it actually declared — the names AND
     * the values.
     *
     * The names alone used to be the whole of this, and the values went
     * unchecked. `{"days": 1}` on a widget that offers 7, 30, 90 or 365 was
     * stored, handed back to resolve() on every later request, and drawn as
     * "Joined in the last 1 days". A widget's declared choices are the only list
     * the configuration UI is built from; they have to be the only list the
     * server keeps too, or the declaration is a suggestion.
     *
     * DROPPED rather than refused, which is the part worth defending. A value
     * nothing offered is the same kind of junk as an option name nothing
     * declared, which this has always discarded, and the widget's own default is
     * a defined answer to it. Refusing would also dead-end an ordinary save: a
     * choice list can be the organization's own data — jobs.revenue offers the
     * statuses that organization configured — and the client sends back the
     * options it was handed, so renaming a status would 422 somebody who had
     * merely ticked a different panel, over a value they never chose and cannot
     * see. What was kept comes back in the response, so nothing is corrected
     * behind anybody's back.
     *
     * @param  array<string, mixed>  $given
     * @param  array<string, array{label: string, type: string, options?: array, default: mixed}>  $declared
     * @return array<string, mixed>
     */
    private function asDeclared(array $given, array $declared): array
    {
        $kept = [];

        foreach (array_intersect_key($given, $declared) as $name => $value) {
            $offered = $declared[$name]['options'] ?? [];

            $value = match ($declared[$name]['type'] ?? '') {
                'enum' => $this->oneOffered($value, $offered),

                // The members still on offer — and an empty list rather than a
                // dropped name when none of them are: jobs.revenue reads "no
                // statuses" as "count nothing", which is the safe answer, where
                // dropping the name would quietly restore its default instead.
                'multi' => is_array($value)
                    ? array_values(array_filter(
                        array_map(fn ($one) => $this->oneOffered($one, $offered), $value),
                        fn ($one) => $one !== null,
                    ))
                    : [],

                // A tick box, not anything truthy. `(bool) "false"` is true and
                // `(bool) "0"` is false, which is the wrong way round for at
                // least one of them whichever way a client writes it.
                'bool' => is_bool($value) ? $value : null,

                // A kind of option nothing here knows how to check. Kept as
                // given rather than dropped, so a widget that declares a new one
                // keeps working until this learns about it.
                default => $value,
            };

            if ($value !== null) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * The declared choice a value names, or null if it names none of them.
     *
     * Compared as text and answered with the DECLARED value, so a client that
     * sends "30" for 30 is understood and what gets stored is the int the widget
     * published — resolve() then reads back the type it declared rather than
     * whatever the JSON happened to carry.
     */
    private function oneOffered(mixed $value, array $offered): mixed
    {
        if (! is_scalar($value)) {
            return null;
        }

        foreach ($offered as $choice) {
            if (is_scalar($choice) && (string) $choice === (string) $value) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * What to render when somebody has never chosen.
     *
     * A default rather than an empty page: a new organization's first sight of
     * the product should not be a blank screen with a "configure me" button.
     *
     * The registry names that default (WidgetRegistry::ON_BY_DEFAULT) rather
     * than it being "everything you may see", which is what this used to be.
     * That version made registering a widget switch it on for every user who
     * had never opened the panel picker — a new module silently rearranging a
     * screen nobody asked it to touch.
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
            $this->registry->defaultLayoutFor($user),
        );
    }
}
