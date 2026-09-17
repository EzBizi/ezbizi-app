<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Glances\AutomationsGlance;
use App\Support\Dashboard\Glances\BusinessProfileGlance;
use App\Support\Dashboard\Glances\CustomersGlance;
use App\Support\Dashboard\Glances\EmailGlance;
use App\Support\Dashboard\Glances\FleetGlance;
use App\Support\Dashboard\Glances\HrGlance;
use App\Support\Dashboard\Glances\InspectionGlance;
use App\Support\Dashboard\Glances\InventoryGlance;
use App\Support\Dashboard\Glances\JobsGlance;
use App\Support\Dashboard\Glances\LeadsGlance;
use App\Support\Dashboard\Glances\MarketingGlance;
use App\Support\Dashboard\Glances\PhoneGlance;
use App\Support\Dashboard\Glances\ReportsGlance;
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
        'automations', 'monitoring', 'newsletter', 'reports', 'ads_performance',

        // After ads_performance, matching the order of the chips inside
        // Reports. A glance the COMING_SOON list carries but ORDER does not is
        // silently dropped by all() - which is how this arrived first as a
        // missing glance rather than a misplaced one.
        'profit_loss',
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
        /*
         * A placeholder on purpose, and the reason is quota rather than taste.
         *
         * A live spend glance would have to ask Google on every dashboard load:
         * one query for the account level and one for the campaign level, per
         * connected account. A-1 Chimney has FIFTY-THREE connected accounts, so
         * that is 106 operations per render against a Basic Access ceiling of
         * 15,000 a day - 141 dashboard loads and the day's quota is gone,
         * before the lead sync that actually matters has had any.
         *
         * The obvious answer, caching the figures, is the one thing this module
         * must not do. It reads live and stores nothing by design, and that is
         * not a preference - it is what we have told Google in the Standard
         * Access answer and in the OAuth justification now under review.
         *
         * So the honest sentence is Newsletter's shape: the numbers live in the
         * tab, and the tab is one click away. A live glance registered later
         * wins on this key without this entry being removed first - and if it
         * ever is, it needs a quota story, not just a query.
         */
        'ads_performance' => [
            'label' => 'Google Ads',
            'sentence' => 'Google Ads spend, clicks and conversions are in the Google Ads tab under Reports, per account and per campaign. They are read from Google when you open it, so they are not summarised here.',
        ],
        /*
         * Profit & Loss, and this is Google Ads' reason rather than a
         * placeholder borrowed from it.
         *
         * Every figure in that tab is read from a business's own Airtable base
         * when somebody opens the screen. EzBizi stores none of them, so a
         * glance here would not be a query against our database — it would be
         * a third party's API called once per dashboard load per person, for a
         * number nobody asked to see yet.
         *
         * And the tab's whole point does not summarise. It reports the weeks
         * where a sheet disagrees with ITSELF and resolves nothing; a single
         * profit figure on a dashboard is exactly the silent resolution the
         * reader refuses to make. Measured on A-1's own base: one week in
         * thirty is internally inconsistent by $1,613.
         *
         * A live glance can win on this key later without this entry being
         * removed first. If one is ever written it needs an answer for what it
         * costs to fetch and what it shows for a week that does not add up —
         * not just a query.
         */
        'profit_loss' => [
            'label' => 'Profit & Loss',
            'sentence' => 'Profit and loss is read from the Airtable base a business has connected, '
                .'week by week, when you open the Profit & Loss tab under Reports. It is not '
                .'summarised here: the figures are not stored, and a week where the sheet does '
                .'not add up is worth seeing beside the categories rather than behind a total.',
        ],
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
        ReportsGlance::class,
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
