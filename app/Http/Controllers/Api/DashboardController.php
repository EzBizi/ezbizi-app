<?php

namespace App\Http\Controllers\Api;

use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    /**
     * This dashboard — the composed one, whose layout IS its list of panels.
     *
     * Named rather than assumed, so the one endpoint that saves a layout can say
     * which dashboard it is saving without a second route.
     */
    public const MAIN = 'main';

    /**
     * The dashboards a user may keep an order for, besides the main one.
     *
     * A fixed list, and deliberately not "any name the client sends". This
     * writes into `users.preferences`, which is a JSON column read on every
     * request that returns /me — an unbounded set of surface names would let a
     * client grow that blob without limit, and every entry naming a screen that
     * does not exist would stay there forever with nothing to clean it up.
     *
     * Not something an organization configures: these are the app's own screens,
     * so the list moves when a dashboard is built, not when a customer decides
     * something.
     */
    public const ORDERABLE = ['hr'];

    /**
     * Where a per-surface order lives inside a user's preferences.
     *
     * One key holding a map of surfaces rather than a key per surface, so a
     * third dashboard is an entry and not a migration of everybody's blob.
     * The MAIN dashboard is not in here — see saveLayout().
     */
    public const ORDER_PREFERENCE = 'dashboard.order';

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
     *
     * ── One endpoint, because the order is one kind of fact ──────
     *
     * On the MAIN dashboard, choosing a panel and placing it are the same act:
     * the layout is an ordered list, index() renders it by walking that list, so
     * the order has always been stored here and dragging a card is a save of the
     * list somebody already had. A second preference holding "the order" would
     * be that fact written twice, and the first time the two disagreed the
     * dashboard would draw itself in an order nobody chose.
     *
     * Other dashboards fix their own panels — Human Resources draws the five
     * the HR plan names — so there is no list to choose, only a sequence to
     * remember. `surface` says which dashboard is being saved, and for those the
     * KEYS are stored under ORDER_PREFERENCE. It is the same store, the same
     * user preference (§2.1 category 6) and the same endpoint; what differs is
     * that one of them is a layout and the rest are just an order.
     *
     * ── Why a surface order is not checked against the registry ──
     *
     * The main layout is, and must be: index() hands its keys to resolve(), so
     * an unchecked key there is a widget resolved for somebody who may not see
     * it. A surface order resolves nothing. The panels on those screens are
     * decided by their own endpoint under its own policy, and this list only
     * says what sequence to draw whatever survives that in — including panels
     * that have no registry widget at all, such as HR's "Expiring
     * certifications", which is on screen, has a place in the row, and would
     * otherwise be the one card nobody could move.
     */
    public function saveLayout(Request $request): array
    {
        $user = $request->user();

        $input = $request->validate([
            // Absent means the main dashboard, so every client written before
            // there was more than one keeps working unchanged.
            'surface'             => ['sometimes', 'string', Rule::in([self::MAIN, ...self::ORDERABLE])],
            'widgets'             => ['present', 'array', 'max:20'],
            'widgets.*.key'       => ['required', 'string', 'max:64'],
            'widgets.*.options'   => ['array'],
        ]);

        $surface = $input['surface'] ?? self::MAIN;

        if ($surface !== self::MAIN) {
            return $this->saveOrder($request, $surface, array_column($input['widgets'], 'key'));
        }

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
     * The order somebody put a fixed dashboard's panels in.
     *
     * Cleaned rather than refused, throughout. This is one person's preference
     * about one of their own screens, nothing downstream trusts it for anything
     * but sequence, and there is no reading of a duplicate or an over-long name
     * that makes it worth dead-ending a save with a 422 somebody cannot act on:
     *
     *   - a repeated key is honoured once, so a bad write cannot draw a panel
     *     twice
     *   - the list is capped by the same `max:20` the layout is
     *
     * A name for a panel that no longer exists is KEPT rather than dropped, and
     * that is the deliberate one. A panel can be missing for the afternoon —
     * somebody's role is being edited, a module is switched off and back on —
     * and forgetting where it sat because it happened to be absent when they
     * dragged something else would lose an arrangement they never changed. The
     * client passes over a name it cannot place (see `panelsInSavedOrder`), so
     * an unknown key costs a string and nothing else.
     *
     * @param  list<string>  $keys
     * @return array{surface: string, widgets: list<array{key: string}>}
     */
    private function saveOrder(Request $request, string $surface, array $keys): array
    {
        $user  = $request->user();
        $keys  = array_values(array_unique($keys));
        $order = $user->preferences[self::ORDER_PREFERENCE] ?? [];

        // A stored value written by an older version of this app, or by nothing
        // at all. Anything that is not a map of surfaces is replaced rather than
        // merged into, because merging into a string is a 500.
        if (! is_array($order)) {
            $order = [];
        }

        $order[$surface] = $keys;

        // Not audited, for saveLayout's reason: §10 records what people do to
        // the BUSINESS, and this is somebody rearranging their own screen.
        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], [self::ORDER_PREFERENCE => $order]),
        ])->save();

        return [
            'surface' => $surface,
            'widgets' => array_map(fn (string $key) => ['key' => $key], $keys),
        ];
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
