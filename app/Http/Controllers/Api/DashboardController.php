<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
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
    public function __construct(private GlanceRegistry $registry) {}

    /**
     * What can be drawn, and how.
     *
     * Kept so an old client does not 404 mid-deploy. Returns glances, not
     * widgets — GET /dashboard already carries allowed presentations.
     */
    public function catalogue(Request $request): array
    {
        return [
            'glances' => array_map(fn (Glance $g) => [
                'key' => $g->key(),
                'label' => $g->label(),
                'allowed_presentations' => array_values($g->allowedPresentations()),
                'default_presentation' => $g->defaultPresentation(),
            ], $this->registry->availableTo($request->user())),
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
            $options = $this->asDeclared($glance, $entry['options'] ?? [], $user);

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
                $payload = $glance->resolve($user, $period, $options)->toArray();
            } catch (Throwable $e) {
                report($e);
                $payload = $this->failedPanel($glance);
            }
            $payload['presentation'] = $presentation;

            /*
             * ONE KEY, TWO MEANINGS — and this line silently picks one.
             *
             * A glance's `resolve()` may put its own options in the payload:
             * what the box is CONFIGURED with. This overwrites them with the
             * saved layout's copy: what this PERSON chose. Both are called
             * `options`, so the second always wins and the first can never
             * ship. `JobsGlance::wireOptions()` returns the organization's
             * real earned-status list today and no client will ever see it.
             * Raised by Jobs (2026-09-23) after their own change made it
             * visible; nothing is wrong right now, because with the chip
             * strip gone nothing writes a per-user value and the loop is
             * inert rather than lossy.
             *
             * DO NOT 'FIX' THIS BY DROPPING THE LINE. The client echoes
             * `options` straight back on the next layout PUT (`_entryFor` in
             * dashboard_screen.dart), and `saveLayout` stores what it is
             * sent. So shipping the glance's own options here makes the
             * ORGANIZATION's value round-trip into every user's stored
             * preference — a setting nobody chose, written per person, and
             * the authority mistake spec §8.2 exists to prevent.
             *
             * The real fix is two keys, because these are two facts: what
             * the box is set to, and what this reader asked for. That is a
             * wire change and wants deciding rather than patching.
             */
            $payload['options'] = $options;

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
        // (or an empty list) appends every other module at defaults and drops
        // Jobs earned statuses copied from the old widgets preference.
        if (array_key_exists('glances', $input)) {
            $seen = [];
            $layout = [];

            foreach ($input['glances'] as $entry) {
                $key = $entry['key'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $layout[] = $this->storedEntry($entry, $this->registry->find($key, $user), $user);
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
            'period' => $preferences['dashboard.period'] ?? 'month',
            'glances' => $preferences['dashboard.glances'] ?? [],
        ];
    }

    /**
     * What to render: saved order honoured, new reachable keys appended.
     *
     * Same algorithm as the client's `panelsInSavedOrder`. A saved key that is
     * not currently reachable is skipped here (not drawn) and kept in the
     * preference. A reachable key the saved order has never heard of is
     * appended — dropping the unnamed ones would make every new tab invisible
     * to everybody who had ever rearranged their dashboard.
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
            $saved = $this->copyEarnedStatusesFromWidgets($user, $saved);
        }

        return $this->inSavedOrder($user, $saved);
    }

    /**
     * One copy of an old `jobs.revenue` statuses list onto the Jobs glance, so
     * nobody re-ticks them. Only when `dashboard.glances` is absent; after that
     * `dashboard.widgets` is ignored.
     *
     * @param  list<array{key: string, presentation?: string, options?: array}>  $layout
     * @return list<array{key: string, presentation?: string, options?: array}>
     */
    private function copyEarnedStatusesFromWidgets(User $user, array $layout): array
    {
        $widgets = $user->preferences['dashboard.widgets'] ?? null;
        if (! is_array($widgets)) {
            return $layout;
        }

        $statuses = null;
        foreach ($widgets as $widget) {
            if (($widget['key'] ?? null) !== 'jobs.revenue') {
                continue;
            }
            $given = $widget['options']['statuses'] ?? null;
            if (is_array($given)) {
                $statuses = array_values($given);
                break;
            }
        }

        if ($statuses === null) {
            return $layout;
        }

        foreach ($layout as $i => $entry) {
            if (($entry['key'] ?? null) !== 'jobs') {
                continue;
            }
            $layout[$i]['options'] = array_merge($entry['options'] ?? [], [
                'earned_statuses' => $statuses,
            ]);
            break;
        }

        return $layout;
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
    private function storedEntry(array $entry, ?Glance $glance, User $user): array
    {
        if ($glance === null) {
            $row = ['key' => $entry['key']];
            if (isset($entry['presentation']) && is_string($entry['presentation'])) {
                $row['presentation'] = $entry['presentation'];
            }
            if (isset($entry['options']) && is_array($entry['options'])) {
                $row['options'] = $entry['options'];
            }

            return $row;
        }

        $row = [
            'key' => $glance->key(),
            'presentation' => $this->declaredPresentation($glance, $entry['presentation'] ?? null),
        ];

        $options = $this->asDeclared($glance, is_array($entry['options'] ?? null) ? $entry['options'] : [], $user);
        if ($options !== []) {
            $row['options'] = $options;
        }

        return $row;
    }

    private function declaredPresentation(Glance $glance, mixed $given): string
    {
        $allowed = $glance->allowedPresentations();
        $default = $glance->defaultPresentation();

        // Empty allowed list is sentence-only (coming soon). A kind the glance
        // never offered drops to the default — the asDeclared rule.
        if ($allowed === [] || ! is_string($given) || ! in_array($given, $allowed, true)) {
            return $default;
        }

        return $given;
    }

    /**
     * Options reduced to what the glance actually uses.
     *
     * Jobs `earned_statuses` intersect the organization's current vocabulary.
     * Dropped rather than refused: a renamed status would otherwise 422 somebody
     * who had merely reordered the board, over a value they never chose.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    private function asDeclared(Glance $glance, array $given, User $user): array
    {
        if ($glance->key() !== 'jobs' || ! array_key_exists('earned_statuses', $given)) {
            return [];
        }

        $value = $given['earned_statuses'];
        $offered = $this->jobStatuses($user);

        // An empty list rather than a dropped name: Jobs reads "no statuses" as
        // "count nothing", which is the safe answer, where dropping the name
        // would quietly restore counting nothing anyway but would also hide that
        // the control was set.
        return [
            'earned_statuses' => is_array($value)
                ? array_values(array_filter(
                    array_map(fn ($one) => $this->oneOffered($one, $offered), $value),
                    fn ($one) => $one !== null,
                ))
                : [],
        ];
    }

    /**
     * The declared choice a value names, or null if it names none of them.
     *
     * Compared as text and answered with the DECLARED value, so a client that
     * sends a string for a status is understood and what gets stored is the
     * vocabulary the organization published.
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

    /** @return string[] */
    private function jobStatuses(User $user): array
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $ids = ViewScope::forUser($user)->franchiseIds();
            $franchise = $user->reachableFranchises()->whereIn('id', $ids)->first()
                ?? $user->reachableFranchises()->first();

            return $franchise instanceof Franchise
                ? Job::allowedStatuses($franchise)
                : [];
        });
    }

    /**
     * A box that could not be computed, drawn with fields every shipped client
     * already understands.
     *
     * `empty` + `sentence` is the statement branch the renderer has always
     * had, so an installed desktop or phone build that predates this draws it
     * correctly with no client release. A new `failed` flag would be ignored
     * by exactly those builds, which are the ones still out there when a
     * partial deploy is the cause.
     *
     * `allowed_presentations` is empty so no chart menu sits on a broken box.
     * What it does NOT do is reset `presentation` or `options`: the caller
     * overwrites both with the person's own values after this returns, and
     * that is load-bearing — the client echoes them straight back on the next
     * reorder, so a failed panel carrying `text` and no options would ERASE
     * this person's chart choice and saved options for that box because of an
     * outage they did not cause and cannot see the cause of.
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
        ))->toArray();
    }
    private function periodFor(User $user): DashboardPeriod
    {
        $key = $user->preferences['dashboard.period'] ?? 'month';
        if (! in_array($key, DashboardPeriod::VALUES, true)) {
            $key = 'month';
        }

        return DashboardPeriod::for($user, $key);
    }
}
