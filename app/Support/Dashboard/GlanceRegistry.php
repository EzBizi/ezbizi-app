<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Glances\AutomationsGlance;
use App\Support\Dashboard\Glances\CustomersGlance;
use App\Support\Dashboard\Glances\EmailGlance;
use App\Support\Dashboard\Glances\FleetGlance;
use App\Support\Dashboard\Glances\HrGlance;
use App\Support\Dashboard\Glances\InspectionGlance;
use App\Support\Dashboard\Glances\InventoryGlance;
use App\Support\Dashboard\Glances\JobsGlance;
use App\Support\Dashboard\Glances\BusinessProfileGlance;
use App\Support\Dashboard\Glances\LeadsGlance;
use App\Support\Dashboard\Glances\ReviewsGlance;
use App\Support\Dashboard\Glances\ServicesGlance;
use App\Support\Dashboard\Glances\WebManagementGlance;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;

/**
 * Every glance the platform knows about (spec §4, §9).
 *
 * Declared, not scanned — same reason as ModuleDefinition and WidgetRegistry.
 * ORDER matches the sidebar's tab modules minus dashboard. A key with no class
 * yet is skipped; later tasks only add a class, they do not restack the list.
 */
class GlanceRegistry
{
    /**
     * Sidebar order for tab modules, excluding dashboard.
     *
     * @var string[]
     */
    public const ORDER = [
        'hr', 'customers', 'jobs', 'scheduling', 'inspection', 'marketing',
        'web_management', 'phone', 'fleet_management', 'inventory',
        'services', 'leads', 'reviews', 'business_profile', 'email', 'training',
        'automations', 'monitoring',
    ];

    /**
     * Coming-soon modules.
     *
     * Each entry is a module that has a tab but no live glance class yet. Some
     * arrived with copy taken verbatim from a plan; Training's sentence was
     * written here, because no plan puts a Training summary on the home screen
     * at all. Do not read this list as a queue — an entry is only a promise
     * that the tab exists, not that a glance is coming.
     *
     * @var array<string, array{label: string, sentence: string}>
     */
    private const COMING_SOON = [
        'marketing' => [
            'label' => 'Marketing',
            'sentence' => 'Marketing will show scheduled and published posts here once compose is live.',
        ],
        'phone' => [
            'label' => 'Phone',
            'sentence' => 'Phone is where the team places and receives calls, and sends texts. Open the Phone tab for the desk.',
        ],
        /*
         * Added with the Training module, not with a Training glance.
         *
         * A tab and a dashboard box are one thing here: the completeness test
         * asserts every sidebar tab has a glance, so declaring the module
         * without an entry turns the suite red at a line about the dashboard.
         * Training has its own dashboard INSIDE the tab — who has been assigned
         * what, who is overdue, who is due for retaking — and no plan puts a
         * summary of it on the home screen, so a placeholder is the honest
         * answer rather than a temporary one. A live glance registered later
         * wins on this key without this entry being removed first.
         */
        'training' => [
            'label' => 'Training',
            'sentence' => 'Training is where guides are read and tests are taken. Open the Training tab to see who has been assigned what.',
        ],
        'monitoring' => [
            'label' => 'Monitoring',
            'sentence' => 'Monitoring will show who is online and on which device here. Open the Monitoring tab for the roster.',
        ],
        'scheduling' => [
            'label' => 'Scheduling',
            'sentence' => 'Scheduling will show what is booked here once the Schedule tab is live.',
        ],
    ];

    /**
     * Live glance classes, keyed later by each class's key().
     *
     * Coming-soon placeholders stay in COMING_SOON; a live class here wins on
     * the same key when all() merges.
     *
     * @var class-string<Glance>[]
     */
    private const GLANCES = [
        HrGlance::class,
        CustomersGlance::class,
        JobsGlance::class,
        InspectionGlance::class,
        FleetGlance::class,
        InventoryGlance::class,
        WebManagementGlance::class,
        ServicesGlance::class,
        LeadsGlance::class,
        BusinessProfileGlance::class,
        ReviewsGlance::class,
        EmailGlance::class,
        AutomationsGlance::class,
    ];

    public function __construct(
        private readonly ModuleAvailability $availability,
    ) {}

    /** @return Glance[] */
    public function all(): array
    {
        $byKey = [];

        // Coming soon first, live last — a live class must win if somebody
        // registers it and forgets to drop the placeholder entry.
        foreach ($this->comingSoon() as $key => $meta) {
            $byKey[$key] = new ComingSoonGlance($key, $meta['label'], $meta['sentence']);
        }

        foreach ($this->liveClasses() as $class) {
            $glance = app($class);
            $byKey[$glance->key()] = $glance;
        }

        $ordered = [];

        foreach (self::ORDER as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, array{label: string, sentence: string}>
     */
    protected function comingSoon(): array
    {
        return self::COMING_SOON;
    }

    /**
     * @return class-string<Glance>[]
     */
    protected function liveClasses(): array
    {
        return self::GLANCES;
    }

    /**
     * What this person may see: permission + module availability.
     *
     * Enters the user's organization so hasPermission can read the role outside
     * a request the same way DashboardPeriod::for reads settings.
     *
     * @return Glance[]
     */
    public function availableTo(User $user): array
    {
        return $this->available[$user->getKey()] ??= app(TenantContext::class)->run(
            $user->organization,
            fn () => array_values(array_filter(
                $this->all(),
                fn (Glance $glance) => $user->hasPermission($glance->permission())
                    && $this->availability->isAvailable($glance->key(), $user->organization),
            )),
        );
    }

    /** @var array<string, Glance[]> */
    private array $available = [];

    public function find(string $key, User $user): ?Glance
    {
        foreach ($this->availableTo($user) as $glance) {
            if ($glance->key() === $key) {
                return $glance;
            }
        }

        return null;
    }
}
