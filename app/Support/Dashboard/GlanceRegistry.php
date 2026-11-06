<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Dashboard\Glances\AutomationsGlance;
use App\Support\Dashboard\Glances\BusinessProfileGlance;
use App\Support\Dashboard\Glances\CompanyGlance;
use App\Support\Dashboard\Glances\CustomersGlance;
use App\Support\Dashboard\Glances\EmailGlance;
use App\Support\Dashboard\Glances\FieldGlance;
use App\Support\Dashboard\Glances\FleetGlance;
use App\Support\Dashboard\Glances\HrGlance;
use App\Support\Dashboard\Glances\InspectionGlance;
use App\Support\Dashboard\Glances\InventoryGlance;
use App\Support\Dashboard\Glances\JobsGlance;
use App\Support\Dashboard\Glances\LeadsGlance;
use App\Support\Dashboard\Glances\MarketingGlance;
use App\Support\Dashboard\Glances\PhoneGlance;
use App\Support\Dashboard\Glances\ReportsGlance;
use App\Support\Dashboard\Glances\AdsPerformanceGlance;
use App\Support\Dashboard\Glances\ReviewsGlance;
use App\Support\Dashboard\Glances\SchedulingGlance;
use App\Support\Dashboard\Glances\ServicesGlance;
use App\Support\Dashboard\Glances\TrainingGlance;
use App\Support\Dashboard\Glances\WebManagementGlance;
use App\Support\Dashboard\Glances\WorkHoursGlance;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;

/**
 * Every glance the platform knows about (spec §4, §9).
 *
 * Declared, not scanned — same reason as ModuleDefinition.
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
        // work_hours beside hr, where its sidebar entry sits (2026-09-24).
        'hr', 'work_hours', 'customers', 'jobs', 'scheduling', 'inspection', 'marketing',
        'web_management', 'phone', 'fleet_management', 'inventory',
        // Sales before Leads, where its sidebar entry sits (2026-10-14).
        'services', 'leads',
        // The container Reviews and Business Profile moved into (2026-09-25).
        'customer_experience', 'reviews', 'business_profile',
        'email', 'training',
        'automations', 'monitoring', 'newsletter', 'reports', 'ads_performance',
        // Its sidebar entry arrived after the others (2026-09-24).
        'ai_assistance',
        // Guidelines, beside AI Assistance like its sidebar entry (2026-10-14).
        'guidelines',

        // Profit & Loss AFTER Google Ads, matching the order of the chips
        // inside Reports — the module registry was changed to that order
        // deliberately (a new tab goes on the end rather than moving one people
        // already click). Dashboard's copy of this line arrived from main with
        // the old order, which was mine before the decision, not theirs.
        'profit_loss',
        // Field, on the end like its sidebar entry (2026-09-25).
        'field',
        // Financials, before Task Board like its sidebar entry (2026-10-14).
        'financials',
        // Task Board, on the end (2026-10-13).
        'tasks',
        // Company (design 175, 2026-11-03).
        'company',
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
        /*
         * Reports' other child, and the same answer as Google Ads above for
         * the same reason rather than by copying it.
         *
         * The figures are read from the Airtable base a business connected,
         * when somebody opens the screen, and are never stored — the module's
         * own `automationExemptBecause` says so in as many words. A dashboard
         * box would therefore have to make that call on every home-screen
         * load, for every viewer, to summarise numbers nobody asked for yet.
         *
         * I wrote that as "the permanent answer and not a queue entry", and
         * that part was not mine to decide. Whether a business owner wants a
         * profit figure on the home screen is REPORTS' call and Jose's, not
         * the dashboard's — I own the surface, not what a module puts on it.
         * The reasoning above is offered, not imposed.
         *
         * THIS ENTRY DOES NOT BLOCK A REAL GLANCE. A live class registered on
         * this key wins over the placeholder without anybody removing it —
         * `test_a_live_glance_wins_when_coming_soon_still_lists_the_same_key`
         * pins that. It exists so a module can reach the sidebar without the
         * completeness test holding a release.
         *
         * The one case where it must come out: if Reports decides Profit &
         * Loss should NOT be its own sidebar module, this entry and its ORDER
         * line go in the SAME commit, or the completeness test fails the
         * other way round — a glance for a tab that no longer exists.
         */
        // The Library is reference material and the assistant is a
        // conversation; neither has a figure worth a home-screen box yet.
        'ai_assistance' => [
            'label' => 'AI Assistance',
            'sentence' => 'Answers for your team, on WhatsApp and in the app.',
        ],
        // Reference cards; nothing to count on the home screen.
        'guidelines' => [
            'label' => 'Guidelines',
            'sentence' => 'Step-by-step answers for the situations your team meets.',
        ],
        'profit_loss' => [
            'label' => 'Profit & Loss',

            /*
             * REPORTS' OWN SENTENCE, taking Dashboard up on the offer above.
             *
             * Their reading is right and the second half is the module's half:
             * this tab does not summarise BY DESIGN. It reports the weeks where
             * a sheet disagrees with itself and resolves nothing, so a single
             * profit figure on a home screen is exactly the silent resolution
             * the reader refuses to make. Measured on A-1's own base, one week
             * in thirty is internally inconsistent by $1,613.
             *
             * So if a live glance is ever written it owes two answers, not one:
             * what the fetch costs, and what it shows for a week that does not
             * add up.
             */
            'sentence' => 'Profit and loss is read from the Airtable base a business has connected, '
                .'week by week, when you open the Profit & Loss tab under Reports. It is not '
                .'summarised here: the figures are not stored, and a week where the sheet does '
                .'not add up is worth seeing beside the categories rather than behind a total.',
        ],
        // A container: Reviews and Business Profile each have a live box of
        // their own, so a summary here would restate them (2026-09-25).
        'customer_experience' => [
            'label' => 'Customer Experience',
            'sentence' => 'Reviews and Business Profile have their own boxes on this screen. Open the Customer Experience tab for both.',
        ],
        'newsletter' => [
            'label' => 'Newsletter',
            'sentence' => 'Newsletter keeps its own dashboard inside the tab: the month ahead, what has gone out, and who it reached. Open the Newsletter tab for it.',
        ],
        'monitoring' => [
            'label' => 'Monitoring',
            'sentence' => 'Monitoring will show who is online and on which device here. Open the Monitoring tab for the roster.',
        ],
        // Financials (2026-10-14): placeholder until a live glance is built.
        'financials' => [
            'label' => 'Financials',
            'sentence' => 'Your bases, tables and records live in the Financials tab. Open it to work in them.',
        ],
        // Task Board (2026-10-13): placeholder until a live glance is built.
        'tasks' => [
            'label' => 'Task Board',
            'sentence' => 'Your tasks and your team\'s boards live in the Task Board tab. Open it to see what is due.',
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
        WorkHoursGlance::class,
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
        // Live since 2026-09-28: certification and paused campaigns, from
        // what the watchers stored. Spend stays in the tab.
        AdsPerformanceGlance::class,
        SchedulingGlance::class,
        EmailGlance::class,
        AutomationsGlance::class,
        PhoneGlance::class,
        MarketingGlance::class,
        ReportsGlance::class,
        FieldGlance::class,
        CompanyGlance::class,
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
