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
