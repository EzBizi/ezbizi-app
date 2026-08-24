<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * A granted module that is not built yet (spec §8).
 *
 * Sentence only — no chart switcher. Copy lives here so the dashboard is right
 * without a client release for a new placeholder tab.
 */
final class ComingSoonGlance implements Glance
{
    public function __construct(
        private readonly string $moduleKey,
        private readonly string $moduleLabel,
        private readonly string $sentence,
    ) {}

    public function key(): string
    {
        return $this->moduleKey;
    }

    public function label(): string
    {
        return $this->moduleLabel;
    }

    public function permission(): string
    {
        return $this->moduleKey.'.view';
    }

    public function allowedPresentations(): array
    {
        return [];
    }

    public function defaultPresentation(): string
    {
        return 'text';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: true,
            options: [],
            data: [
                'sentence' => $this->sentence,
                'empty' => true,
            ],
        );
    }
}
