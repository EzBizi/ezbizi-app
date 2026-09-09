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
     * ── Before you copy another glance's test fixture, read this ──────
     *
     * "Reach" is not one rule on this surface. Measured across the
     * seventeen live glances on 2026-09-09, there are three families, and
     * a fixture written for one proves nothing about another:
     *
     *   ViewScope        the CURRENT VIEW — thirteen glances. A user with
     *                    no stored `navigation.scope` is in the ORGANIZATION
     *                    view however they are assigned, and correctly sees
     *                    everything beneath it.
     *   assignment       where the person is POSTED, regardless of the view
     *                    they are standing in — Fleet, and HR through its
     *                    widgets.
     *   a module's own   MailboxAccess, SocialConnectionAccess,
     *   Access helper    PhoneNumberAccess. Use the module's, never a second
     *                    answer to "which of these are mine".
     *
     * `AutomationsGlance` is deliberately none of them: organization-scoped
     * with no franchise filter, said in as many words in its own docblock.
     *
     * This paragraph exists because the confusion is not theoretical. A
     * glance shipped in September copied Fleet's fixture onto a
     * view-scoped box; the test expected 1 and got 3, and the tempting
     * reading was that the glance leaked. It did not — the FIXTURE was
     * testing the wrong rule. That failure looks exactly like the one it
     * is not, which is the worst shape a false alarm can take.
     *
     * Two things worth pairing with whichever rule you obey. Stand the
     * same person in the organization as a positive control, or a fixture
     * that quietly failed to create the out-of-reach rows produces the
     * number you wanted and reads as proof. And remember that scoping the
     * CONTENT constrains nothing about the PEOPLE: "4 people are due" is a
     * disclosure about people even when the records counted are not, which
     * is Training's finding and applies to any glance that counts anything
     * a person did.
     *
     * @param  array<string, mixed>  $options
     */
    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload;
}
