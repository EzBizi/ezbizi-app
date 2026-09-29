<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Widgets\AutomationHealth;
use App\Support\Dashboard\Widgets\CustomersAdded;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Dashboard\Widgets\Hr\ExpiringCertifications;
use App\Support\Dashboard\Widgets\Hr\Headcount;
use App\Support\Dashboard\Widgets\Hr\InvitationsStalled;
use App\Support\Dashboard\Widgets\Hr\OnboardingsInFlight;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
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
         * tab shows a fixed subset of them (HrDashboardController), in the plan's
         * sequence until somebody drags a card and saves an order of their own;
         * the main dashboard lets people compose the same ones. Two surfaces,
         * one computation, one place to correct it.
         *
         * COMPOSE, not receive: they are absent from ON_BY_DEFAULT below, so
         * declaring them here offered four new panels rather than switching
         * them on for everybody who has never opened the panel picker.
         */
        Headcount::class,
        OnboardingsInFlight::class,
        InvitationsStalled::class,
        StartersAndLeavers::class,
        ExpiringCertifications::class,
    ];

    /**
     * What the main dashboard opens as before anybody has chosen.
     *
     * A named subset, and it has to be a subset. The default used to be
     * "everything you have permission for", which was invisible while the
     * registry held five widgets and became a real change the moment HR added
     * four more: every user in every organization who had never touched the
     * panel picker would have found four HR panels on their dashboard, put
     * there by a registration rather than by a decision. Adding a widget should
     * OFFER it.
     *
     * The HR widgets are deliberately out. They have a tab of their own that
     * shows them in the plan's order, and one of them — starters and leavers —
     * only reads correctly beside the sentences that tab prints about what the
     * app cannot date.
     */
    private const ON_BY_DEFAULT = [
        JobsByStatus::class,
        UpcomingJobs::class,
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

    /**
     * The dashboard somebody sees on their first visit.
     *
     * Falls back to everything they may see when the default set reaches none
     * of it. A role holding `hr.view` and nothing else would otherwise open the
     * product on a blank page, which is the single thing a default exists to
     * prevent — better four panels they did not ask for than none at all, and
     * they can then choose.
     *
     * @return Widget[]
     */
    public function defaultLayoutFor(User $user): array
    {
        $available = $this->availableTo($user);

        $defaults = array_values(array_filter(
            $available,
            fn (Widget $w) => in_array($w::class, self::ON_BY_DEFAULT, true),
        ));

        return $defaults === [] ? $available : $defaults;
    }

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
