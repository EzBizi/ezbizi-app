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
