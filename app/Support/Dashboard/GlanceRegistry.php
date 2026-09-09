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
use App\Support\Dashboard\Glances\MarketingGlance;
use App\Support\Dashboard\Glances\PhoneGlance;
use App\Support\Dashboard\Glances\ReviewsGlance;
use App\Support\Dashboard\Glances\SchedulingGlance;
use App\Support\Dashboard\Glances\ServicesGlance;
use App\Support\Dashboard\Glances\TrainingGlance;
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
        'automations', 'monitoring', 'newsletter',
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
        /*
         * Added with the Newsletter module, not with a Newsletter glance, and
         * the reasoning is Training's above rather than Marketing's below.
         *
         * Newsletter has its own DASHBOARD INSIDE the tab — the month with the
         * scheduled mailings on it, what went out, and how many it reached.
         * That was the owner's design, so a second summary on the home screen
         * would restate it a click away from the real one and then drift from
         * it. A placeholder is the honest answer here, not a temporary one.
         *
         * A live glance registered later wins on this key without this entry
         * being removed first.
         */
        'newsletter' => [
            'label' => 'Newsletter',
            'sentence' => 'Newsletter keeps its own dashboard inside the tab: the month ahead, what has gone out, and who it reached. Open the Newsletter tab for it.',
        ],
        'monitoring' => [
            'label' => 'Monitoring',
            'sentence' => 'Monitoring will show who is online and on which device here. Open the Monitoring tab for the roster.',
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
        TrainingGlance::class,
        WebManagementGlance::class,
        ServicesGlance::class,
        LeadsGlance::class,
        BusinessProfileGlance::class,
        ReviewsGlance::class,
        SchedulingGlance::class,
        EmailGlance::class,
        AutomationsGlance::class,
        PhoneGlance::class,
        MarketingGlance::class,
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
