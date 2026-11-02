<?php

namespace App\Support\Dashboard;

/**
 * A glance whose six descriptive answers are constants rather than methods.
 *
 * Every live glance answered `key`, `label`, `permission`,
 * `allowedPresentations`, `defaultPresentation` and `snapshot` with a method
 * whose entire body was `return <literal>;`. Fourteen classes times six
 * methods is 336 lines that say what six constants say.
 *
 * Constants and not constructor arguments, because these are properties of the
 * CLASS and not of an instance: `GlanceRegistry` resolves each glance out of
 * the container, so anything passed in would have to be bound there instead,
 * which moves the fact away from the file that owns it.
 *
 * A subclass that omits one gets an Error naming the missing constant the
 * first time the dashboard is drawn — {@see GlanceRegistry::all()} calls
 * `key()` on every glance at boot, so a mistake here cannot reach a user
 * quietly. {@see ComingSoonGlance} deliberately does NOT extend this: its
 * three values arrive per instance, which is the one case constants cannot
 * serve.
 */
abstract class DescribedGlance implements Glance
{
    /** Medium unless the module says otherwise; a quiet module says small. */
    protected const DEFAULT_SIZE = 'medium';

    public function defaultSize(): string
    {
        return static::DEFAULT_SIZE;
    }

    public function key(): string
    {
        return static::KEY;
    }

    public function label(): string
    {
        return static::LABEL;
    }

    public function permission(): string
    {
        return static::PERMISSION;
    }

    /** @return string[] */
    public function allowedPresentations(): array
    {
        return static::PRESENTATIONS;
    }

    public function defaultPresentation(): string
    {
        return static::DEFAULT_PRESENTATION;
    }

    public function snapshot(): bool
    {
        return static::SNAPSHOT;
    }

    /**
     * The payload a live glance answers with: its own description around
     * whatever data it measured.
     *
     * @param  array<string, mixed>  $data
     */
    protected function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }
}
