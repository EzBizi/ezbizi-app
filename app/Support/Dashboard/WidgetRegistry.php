<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Widgets\AutomationHealth;
use App\Support\Dashboard\Widgets\CustomersAdded;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Dashboard\Widgets\Revenue;
use App\Support\Dashboard\Widgets\UpcomingJobs;

/**
 * Every widget the platform knows about (plan §18 Phase 4).
 *
 * Declared here rather than discovered, for the same reason ModuleDefinition
 * lists triggers and actions explicitly: a registry you can read is a registry
 * somebody can reason about, and directory scanning turns "what can this app
 * do" into a question only the runtime can answer.
 */
class WidgetRegistry
{
    private const WIDGETS = [
        JobsByStatus::class,
        UpcomingJobs::class,
        Revenue::class,
        CustomersAdded::class,
        AutomationHealth::class,
    ];

    /** @return Widget[] */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::WIDGETS);
    }

    /**
     * Only what this person may see.
     *
     * Filtered here so the catalogue, the layout validator and the data endpoint
     * cannot disagree — three separate permission checks would eventually differ
     * by one, and the one that differs is the one that leaks.
     *
     * @return Widget[]
     */
    public function availableTo(User $user): array
    {
        // Memoised per instance, because find() calls this once per panel in the
        // saved layout — five panels meant instantiating every widget five times
        // and asking the permission system twenty-five questions to render one
        // page. The registry is resolved once per request, so this lives exactly
        // as long as the answer stays true.
        return $this->available[$user->getKey()] ??= array_values(array_filter(
            $this->all(),
            fn (Widget $w) => $user->hasPermission($w->permission()),
        ));
    }

    /** @var array<string, Widget[]> */
    private array $available = [];

    public function find(string $key, User $user): ?Widget
    {
        foreach ($this->availableTo($user) as $widget) {
            if ($widget->key() === $key) {
                return $widget;
            }
        }

        return null;
    }
}
