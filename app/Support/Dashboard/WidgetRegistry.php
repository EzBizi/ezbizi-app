<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Widgets\AutomationHealth;
use App\Support\Dashboard\Widgets\CustomersAdded;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Dashboard\Widgets\Hr\Headcount;
use App\Support\Dashboard\Widgets\Hr\InvitationsStalled;
use App\Support\Dashboard\Widgets\Hr\OnboardingsInFlight;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
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

        /*
         * Human Resources (EZBIZI-HR-PLAN.md — Dashboard).
         *
         * Declared HERE rather than in a registry of their own, which was the
         * decision worth writing down. HR's Dashboard tab could have been a
         * screen with four hand-built cards and its own endpoint, and that would
         * have put every one of these figures in two places the first time
         * somebody wanted headcount on their main dashboard as well.
         *
         * A widget is not "a card on the main dashboard" — it is one question,
         * asked once, answered under the caller's permissions and reach. HR's
         * tab shows a fixed subset of them in the order the plan names
         * (HrDashboardController); the main dashboard lets people compose the
         * same ones. Two surfaces, one computation, one place to correct it.
         */
        Headcount::class,
        OnboardingsInFlight::class,
        InvitationsStalled::class,
        StartersAndLeavers::class,
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
