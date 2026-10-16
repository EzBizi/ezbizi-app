<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DashboardTemplates;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\GlanceRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The dashboard: one glance per reachable sidebar module (spec §4, §11).
 *
 * What somebody sees is every module they can open, in sidebar order overlaid
 * with their own preference (§2.1 category 6). They cannot hide a box. The
 * data is computed per request; snapshots ignore the page period.
 */
class DashboardController extends Controller
{
    /** What a box can be sized to (Jose, 2026-10-05, the sizes gallery). */
    private const SIZES = ['small', 'medium', 'large'];

    public function __construct(private GlanceRegistry $registry) {}

    /**
     * What can be drawn, and how.
     *
     * Kept so an old client does not 404 mid-deploy. Returns glances, not
     * widgets — GET /dashboard already carries allowed presentations.
     */
    public function catalogue(Request $request): array
    {
        $glances = $this->registry->availableTo($request->user());

        return [
            // Recommended layouts, built for this person's reach; applying one
            // is a PUT of its glances (DashboardTemplates).
            'templates' => DashboardTemplates::forReach($glances),
            'glances' => array_map(fn (Glance $g) => [
                'key' => $g->key(),
                'label' => $g->label(),
                'allowed_presentations' => array_values($g->allowedPresentations()),
                'default_presentation' => $g->defaultPresentation(),
                'default_size' => $g->defaultSize(),
            ], $glances),
        ];
    }

    /** Every reachable glance, with its data, under the page period. */
    public function index(Request $request): array
    {
        $user = $request->user();
        $period = $this->periodFor($user);
        $glances = [];

        foreach ($this->layoutFor($user) as $entry) {
            $glance = $this->registry->find($entry['key'], $user);

            // Silently skipped rather than errored. A layout outlives the
            // permission that allowed it: somebody moved between roles, or a
            // module was switched off platform-wide, and their dashboard should
            // quietly show less rather than refuse to load. The name stays in
            // the stored list so the place is remembered when it comes back.
            if ($glance === null) {
                continue;
            }

            $presentation = $this->declaredPresentation($glance, $entry['presentation'] ?? null);
            $size = $this->declaredSize($glance, $entry['size'] ?? null);

            // Hidden from this person's dashboard (Customize, 2026-10-05):
            // listed so "Add a box" can offer it back, but not resolved —
            // a box nobody looks at spends no queries.
            if (($entry['hidden'] ?? false) === true) {
                $glances[] = [
                    'key' => $glance->key(),
                    'label' => $glance->label(),
                    'hidden' => true,
                    'size' => $size,
                    'presentation' => $presentation,
                ];

                continue;
            }

            /*
             * One glance must never take the dashboard down with it.
             *
             * Before 2026-09-28 a throw in any single resolve() became a 500 for
             * the whole endpoint, and the client drew one error and "Try again"
             * where every card should have been. UI/UX saw it live on a local
             * database: a peer's not-yet-migrated table made one Ads glance
             * throw and all nineteen boxes vanished. In production the same
             * happens after any partial deploy or any single glance's bug, and
             * the module that broke is rarely the one the reader came to see.
             *
             * Reported, so it is not silent to us; drawn as a failed panel, so
             * it is not silent to the reader either.
             */
            try {
                $payload = $glance->resolve($user, $period, [])->toArray();
            } catch (Throwable $e) {
                report($e);
                $payload = $this->failedPanel($glance);
            }
            $payload['presentation'] = $presentation;
            $payload['size'] = $size;
            $payload['hidden'] = false;

            // No glance takes options: the last one, Jobs' earned statuses, left
            // with the money on 2026-09-29. Always empty, so whatever a glance
            // puts here never round-trips into a person's saved layout.
            $payload['options'] = [];

            $glances[] = $payload;
        }

        return [
            'period' => $period->key,
            'period_starts_on' => $period->startsOn->toIso8601String(),
            'period_ends_on' => $period->endsOn->toIso8601String(),
            'glances' => $glances,
        ];
    }

    /**
     * Save order, period, and chart type.
     *
     * `surface` is ignored, not refused: an old HR-tab client that still posts
     * it must not 422 on the same deploy. HR order is gone; the chip is one box.
     *
     * Omitting a reachable key appends it with the glance's default presentation
     * (200), it does not 422. Unknown or unreachable keys stay in the stored
     * list so a brief permission loss does not forget the place; they are not
     * drawn.
     *
     * Not audited, and the reason is PreferencesController's: this writes to
     * `user.preferences`, it is category 6, and §10 records what people do to
     * the BUSINESS. Said here because a write path with no audit call is
     * otherwise indistinguishable from one where somebody forgot.
     */
    public function saveLayout(Request $request): array
    {
        $user = $request->user();

        $input = $request->validate([
            'period' => ['sometimes', 'string', Rule::in(DashboardPeriod::VALUES)],
            'glances' => ['sometimes', 'array'],
            'glances.*.key' => ['required', 'string', 'max:64'],
            'glances.*.presentation' => ['sometimes', 'nullable', 'string', 'max:32'],
            'glances.*.size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'glances.*.hidden' => ['sometimes', 'boolean'],
            'glances.*.options' => ['sometimes', 'array'],
        ]);

        $preferences = $user->preferences ?? [];
        if (! is_array($preferences)) {
            $preferences = [];
        }

        if (array_key_exists('period', $input)) {
            $preferences['dashboard.period'] = $input['period'];
        }

        // Period-only saves must not replace the stored layout. Sending `{hr}`
        // (or an empty list) appends every other module at defaults.
        if (array_key_exists('glances', $input)) {
            $seen = [];
            $layout = [];

            // An entry WITHOUT size, hidden or presentation means "unchanged",
            // not "reset": every build that predates sizes, and the HR tab's
            // chip, save {key, presentation} on a reorder, and must not undo a
            // size or a hide made on a newer build; a template names no chart
            // type for a Small box, and must not reset that one. Only a
            // present value replaces the stored one.
            $stored = [];
            foreach (is_array($preferences['dashboard.glances'] ?? null) ? $preferences['dashboard.glances'] : [] as $old) {
                if (is_array($old) && is_string($old['key'] ?? null)) {
                    $stored[$old['key']] = $old;
                }
            }

            foreach ($input['glances'] as $entry) {
                $key = $entry['key'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                foreach (['size', 'hidden', 'presentation'] as $kept) {
                    if (! array_key_exists($kept, $entry) && array_key_exists($kept, $stored[$key] ?? [])) {
                        $entry[$kept] = $stored[$key][$kept];
                    }
                }
                $layout[] = $this->storedEntry($entry, $this->registry->find($key, $user));
            }

            foreach ($this->registry->availableTo($user) as $glance) {
                if (isset($seen[$glance->key()])) {
                    continue;
                }
                $layout[] = [
                    'key' => $glance->key(),
                    'presentation' => $glance->defaultPresentation(),
                ];
            }

            $preferences['dashboard.glances'] = $layout;
        }

        $user->forceFill(['preferences' => $preferences])->save();

        return [
            'period' => $preferences['dashboard.period'] ?? 'day',
            'glances' => $preferences['dashboard.glances'] ?? [],
        ];
    }

    /**
     * What to render: saved order honoured, new reachable keys appended.
     *
     * A saved key that is not currently reachable is skipped here (not drawn)
     * and kept in the preference. A reachable key the saved order has never
     * heard of is appended — dropping the unnamed ones would make every new tab
     * invisible to everybody who had ever rearranged their dashboard.
     *
     * @return list<array{key: string, presentation?: string, options?: array}>
     */
    private function layoutFor(User $user): array
    {
        $saved = $user->preferences['dashboard.glances'] ?? null;

        if (! is_array($saved)) {
            $saved = array_map(
                fn (Glance $g) => [
                    'key' => $g->key(),
                    'presentation' => $g->defaultPresentation(),
                ],
                $this->registry->availableTo($user),
            );
        }

        return $this->inSavedOrder($user, $saved);
    }

    /**
     * @param  list<array{key?: mixed, presentation?: mixed, options?: mixed}>  $saved
     * @return list<array{key: string, presentation?: string, options?: array}>
     */
    private function inSavedOrder(User $user, array $saved): array
    {
        $remaining = [];
        foreach ($this->registry->availableTo($user) as $glance) {
            $remaining[$glance->key()] = $glance;
        }

        $ordered = [];
        $seen = [];

        foreach ($saved as $entry) {
            $key = $entry['key'] ?? null;
            if (! is_string($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (! isset($remaining[$key])) {
                continue;
            }
            unset($remaining[$key]);
            $ordered[] = $entry;
        }

        foreach ($remaining as $glance) {
            $ordered[] = [
                'key' => $glance->key(),
                'presentation' => $glance->defaultPresentation(),
            ];
        }

        return $ordered;
    }

    /**
     * @param  array{key: string, presentation?: mixed, options?: mixed}  $entry
     * @return array{key: string, presentation?: string, options?: array}
     */
    private function storedEntry(array $entry, ?Glance $glance): array
    {
        if ($glance === null) {
            $row = ['key' => $entry['key']];
            if (isset($entry['presentation']) && is_string($entry['presentation'])) {
                $row['presentation'] = $entry['presentation'];
            }
            if (isset($entry['size']) && is_string($entry['size'])) {
                $row['size'] = $entry['size'];
            }
            if (($entry['hidden'] ?? false) == true) {
                $row['hidden'] = true;
            }
            if (isset($entry['options']) && is_array($entry['options'])) {
                $row['options'] = $entry['options'];
            }

            return $row;
        }

        $row = [
            'key' => $glance->key(),
            'presentation' => $this->declaredPresentation($glance, $entry['presentation'] ?? null),
            'size' => $this->declaredSize($glance, $entry['size'] ?? null),
        ];

        // Stored only when true, so a layout saved before Customize existed
        // and one that never hid anything read the same.
        if (($entry['hidden'] ?? false) == true) {
            $row['hidden'] = true;
        }

        return $row;
    }

    /** A size nobody offers drops to the glance's default, like presentation. */
    private function declaredSize(Glance $glance, mixed $given): string
    {
        return in_array($given, self::SIZES, true) ? $given : $glance->defaultSize();
    }

    private function declaredPresentation(Glance $glance, mixed $given): string
    {
        $allowed = $glance->allowedPresentations();
        $default = $glance->defaultPresentation();

        // Empty allowed list is sentence-only (coming soon). A kind the glance
        // never offered drops to the default rather than refusing the save.
        if ($allowed === [] || ! is_string($given) || ! in_array($given, $allowed, true)) {
            return $default;
        }

        return $given;
    }

    /**
     * A box that could not be computed, drawn with fields every shipped client
     * already understands.
     *
     * `empty` + `sentence` is the statement branch the renderer has always
     * had, so an installed desktop or phone build that predates this draws it
     * correctly with no client release. `failed` is ADDED beside them, not
     * instead of them, for the sized dashboard (2026-10-05), which draws a
     * failed box differently from an empty one; older builds ignore it.
     *
     * `allowed_presentations` is empty so no chart menu sits on a broken box.
     * What it does NOT do is reset `presentation`: the caller overwrites it
     * with the person's own value after this returns, and that is
     * load-bearing — the client echoes it straight back on the next reorder,
     * so a failed panel carrying `text` would ERASE this person's chart choice
     * for that box because of an outage they did not cause and cannot see the
     * cause of.
     *
     * `key()` and `label()` are constants on every glance, so nothing here can
     * throw the same way resolve() just did.
     *
     * @return array<string, mixed>
     */
    private function failedPanel(Glance $glance): array
    {
        return (new GlancePayload(
            key: $glance->key(),
            label: $glance->label(),
            presentation: 'text',
            allowedPresentations: [],
            snapshot: true,
            comingSoon: false,
            options: [],
            data: [
                'empty' => true,
                'sentence' => 'This panel could not load right now.',
            ],
        ))->toArray() + ['failed' => true];
    }

    private function periodFor(User $user): DashboardPeriod
    {
        $key = $user->preferences['dashboard.period'] ?? 'day';
        if (! in_array($key, DashboardPeriod::VALUES, true)) {
            $key = 'day';
        }

        return DashboardPeriod::for($user, $key);
    }
}
