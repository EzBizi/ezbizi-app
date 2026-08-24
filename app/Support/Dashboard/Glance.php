<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * One dashboard box, keyed by a sidebar module (spec §9).
 *
 * A glance is not a widget and not a shortcut. It answers what that module looks
 * like right now under the caller's reach and the page's one period. Empty
 * allowedPresentations means sentence-only — coming soon, or nothing to plot.
 */
interface Glance
{
    /** Module key — stored in the user's preference, so it cannot change. */
    public function key(): string;

    public function label(): string;

    /** `{module}.view` — reach is checked inside resolve(). */
    public function permission(): string;

    /**
     * Chart kinds this glance offers. Empty means sentence-only (no switcher).
     *
     * @return string[]
     */
    public function allowedPresentations(): array;

    public function defaultPresentation(): string;

    /** True when the chart ignores the dashboard period. */
    public function snapshot(): bool;

    /**
     * @param  array<string, mixed>  $options
     */
    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload;
}
