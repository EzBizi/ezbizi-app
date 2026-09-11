<?php

namespace App\Support\Dashboard;

/**
 * Wire shape for one dashboard box (spec §11).
 *
 * The client draws slices / series or a sentence; it does not aggregate. Chart
 * kinds and coming-soon travel with the payload so a switcher can hide itself
 * without a second round trip.
 */
final class GlancePayload
{
    /**
     * ── What `$data['empty']` means, since it is the one people get wrong ──
     *
     * It asks whether this box is a STATEMENT or a READING. It is not a
     * sentence count and it is not a hint.
     *
     *   true   the client draws NO chart and NO money lines, whatever the
     *          payload carries, and sets every sentence in the muted note
     *          style. With no sentence at all it writes "Nothing to show."
     *   false  a chart when there is something to plot, every sentence as
     *          ordinary text, and a line for every money list.
     *
     * **Both render every sentence.** They did not until 2026-09-11: the
     * `true` branch drew the FIRST one and silently discarded the rest. That
     * cost three weeks of a customer seeing one of two dead inboxes named on
     * their home screen, and it is why the rule is written here rather than
     * in a note somebody has to have read. If you are setting `empty` to
     * dodge a renderer behaviour, the renderer is the thing to fix.
     *
     * The consequence to watch is the opposite one now: `true` SUPPRESSES a
     * chart and any figures. A box with slices to plot must not set it.
     *
     * ── What the client actually draws out of `$data` ──────────────────
     *
     * Everything else you put here is carried over the wire and rendered by
     * nothing. `$data` is free-form and nothing validates it, so a key the
     * client has never heard of costs a query and a payload and produces no
     * pixels — silently, which is the mirror of the renderer dropping what it
     * was handed.
     *
     *   empty            the statement/reading switch above
     *   sentence         one line, or
     *   sentences        a list of them; both render in full
     *   slices           pie and bar, drawn when one total is above zero
     *   series           line and timeline, each with `points`
     *   status_choices   the Jobs earned-status chips
     *   any list of {amount_minor, currency}   drawn as money, under ANY key
     *
     * Measured 2026-09-11: `organization_total` is sent by FIVE glances —
     * BusinessProfile, Customers, Leads, Reviews, Web Management — and read
     * by nothing in `client/lib`. A pattern copied four times, not a slip.
     * Each is an extra count on every dashboard load for a figure nobody
     * sees. Their modules' to keep or drop; it is listed here so the next
     * author does not copy it a sixth time.
     *
     * And a key nothing draws is still SHIPPED. An organization-wide total
     * reaches a viewer whose reach is one franchise — invisible on screen,
     * readable in the response. Customers argues that one deliberately in its
     * own docblock (§5.2 shares the book); the others carry no such argument.
     * Scoping what a box DRAWS constrains nothing about what it SENDS.
     *
     * @param  string[]  $allowedPresentations
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $presentation,
        public readonly array $allowedPresentations,
        public readonly bool $snapshot,
        public readonly bool $comingSoon,
        public readonly array $options,
        public readonly array $data,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'presentation' => $this->presentation,
            'allowed_presentations' => array_values($this->allowedPresentations),
            'snapshot' => $this->snapshot,
            'coming_soon' => $this->comingSoon,
            'options' => $this->options,
            'data' => $this->data,
        ];
    }
}
