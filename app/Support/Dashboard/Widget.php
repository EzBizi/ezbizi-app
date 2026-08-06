<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * One thing a dashboard can show (plan §18 Phase 4).
 *
 * ── Why widgets are a registry and not a screen ──────────────────
 *
 * The standing rule is that nothing is hardcoded: "everything in the app should
 * be configurable inside the App". A dashboard built as a fixed column of cards
 * would break that on day one — every new number would be a code change and a
 * client release, and no two businesses want the same six.
 *
 * So a widget declares what it is and what it can be asked, the registry
 * collects them, and the user's own choice of which to show is a preference
 * (§2.1 category 6). Adding one is a class; changing your dashboard is a click.
 *
 * ── What every widget must do ────────────────────────────────────
 *
 * Two obligations, both easy to forget and both invisible when broken:
 *
 *   - Answer for the caller's REACH, not the organization (§4). A franchise
 *     manager's dashboard showing organization-wide totals is a data leak that
 *     looks like a feature.
 *   - Never sum across currencies. Amounts are minor units with a currency
 *     attached; adding 100 USD to 100 CAD produces a number that is wrong in a
 *     way nobody notices until a client reconciles it.
 */
interface Widget
{
    /** Stable identifier — stored in the user's preference, so it cannot change. */
    public function key(): string;

    public function label(): string;

    /** The permission required to see it. Reach is checked inside resolve(). */
    public function permission(): string;

    /**
     * What this widget can be asked, for the configuration UI.
     *
     * Returned per user because the choices are often the organization's own
     * data — job statuses are tenant configuration (§2.1 category 5), so the
     * options are whatever that organization decided they are.
     *
     * @return array<string, array{label: string, type: string, options?: array, default: mixed}>
     */
    public function options(User $user): array;

    /** @return array the widget's data, shaped for the client */
    public function resolve(User $user, array $options): array;
}
