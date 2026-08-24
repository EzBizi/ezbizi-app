<?php

namespace App\Support\Dashboard;

use App\Models\User;
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
        'hr', 'customers', 'jobs', 'inspection', 'marketing',
        'web_management', 'phone', 'fleet_management', 'inventory',
        'services', 'email', 'automations',
    ];

    /**
     * Coming-soon modules registered in Task 1 (copy is verbatim from the plan).
     *
     * @var array<string, array{label: string, sentence: string}>
     */
    private const COMING_SOON = [
        'marketing' => [
            'label' => 'Marketing',
            'sentence' => 'Marketing will show scheduled and published posts here once compose is live.',
        ],
        'web_management' => [
            'label' => 'Web Management',
            'sentence' => 'Web Management is where a company will edit and publish its public website from EzBizi — the same place it already runs the rest of the business. The editor is not here yet.',
        ],
        'phone' => [
            'label' => 'Phone',
            'sentence' => 'Phone is where the team will place and receive calls, and send texts, with customers without leaving EzBizi. Calling is not here yet.',
        ],
        'inventory' => [
            'label' => 'Inventory',
            'sentence' => 'Inventory is where the company will keep what it has on the shelf — parts, materials, and the stock that jobs draw from. The register is not here yet.',
        ],
        'services' => [
            'label' => 'Services',
            'sentence' => 'Services is where the company will name what it sells — the work a job is for, and the price it starts from. The catalogue is not here yet.',
        ],
    ];

    /**
     * Live glance classes, keyed later by each class's key().
     *
     * Empty in Task 1 — Jobs / HR / … register here in later tasks.
     *
     * @var class-string<Glance>[]
     */
    private const GLANCES = [];

    public function __construct(
        private readonly ModuleAvailability $availability,
    ) {}

    /** @return Glance[] */
    public function all(): array
    {
        $byKey = [];

        foreach (self::GLANCES as $class) {
            $glance = app($class);
            $byKey[$glance->key()] = $glance;
        }

        foreach (self::COMING_SOON as $key => $meta) {
            $byKey[$key] = new ComingSoonGlance($key, $meta['label'], $meta['sentence']);
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
