<?php

namespace App\Support\Modules;

use App\Models\Organization;
use App\Models\PlatformModuleFlag;
use InvalidArgumentException;

/**
 * "Is this module switched on for this organization?" (plan §2.1, layer 3).
 *
 * The first gate in the chain. All four must pass before a user may act:
 *
 *   1. release flag   — EzBizi staff        ← this class
 *   2. entitlement    — billing              (not built yet; §13)
 *   3. permission     — org administrator    (Role::hasPermission)
 *   4. assignment     — org administrator    (User::canActOnFranchise)
 *
 * Deliberately NOT merged with permissions. They answer different questions for
 * different owners: this one is "does this feature exist right now", theirs is
 * "is this person allowed to use it". Merging them means an org admin editing a
 * role could, through a bug, affect what every other customer can see.
 */
class ModuleAvailability
{
    /** @var array<string, PlatformModuleFlag>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * A module with no flag row is AVAILABLE.
     *
     * Fail-open is the right default here, and only here: a module exists in
     * code, so the absence of a flag means nobody has ever restricted it — not
     * that it's forbidden. Failing closed would make every new module invisible
     * until someone remembered to insert a row, which is the kind of silent
     * breakage that teaches people to distrust the system.
     *
     * Note this is the opposite of the permission default, which fails CLOSED.
     * Different question, different safe answer.
     */
    public function isAvailable(string $moduleKey, ?Organization $organization = null): bool
    {
        if ($this->registry->get($moduleKey) === null) {
            // Not in the registry at all — no code behind it, so nothing to grant.
            return false;
        }

        $flag = $this->flags()[$moduleKey] ?? null;

        return $flag === null || $flag->availableTo($organization);
    }

    /** Availability for the module a permission key belongs to: `jobs.create` → `jobs`. */
    public function isPermissionAvailable(string $permissionKey, ?Organization $organization = null): bool
    {
        $moduleKey = str_contains($permissionKey, '.')
            ? strstr($permissionKey, '.', true)
            : $permissionKey;

        return $this->isAvailable($moduleKey, $organization);
    }

    // ── staff operations (§4.2) ──────────────────────────────────

    /**
     * Set a module's platform state.
     *
     * `reason` is mandatory for anything except 'enabled'. An unexplained
     * disabled module is indistinguishable from a mistake once whoever flipped
     * it has moved on — and the person who finds it is usually mid-incident.
     */
    public function setState(
        string $moduleKey,
        string $state,
        ?string $reason = null,
        ?string $changedBy = null,
        array $betaOrganizationIds = [],
    ): PlatformModuleFlag {
        if ($this->registry->get($moduleKey) === null) {
            throw new InvalidArgumentException(
                "Unknown module '{$moduleKey}'. Flags reference the registry, which lives in code."
            );
        }

        if (! in_array($state, [
            PlatformModuleFlag::ENABLED,
            PlatformModuleFlag::BETA,
            PlatformModuleFlag::DISABLED,
        ], strict: true)) {
            throw new InvalidArgumentException("Unknown module state '{$state}'.");
        }

        if ($state !== PlatformModuleFlag::ENABLED && blank($reason)) {
            throw new InvalidArgumentException(
                "A reason is required when setting '{$moduleKey}' to '{$state}'."
            );
        }

        if ($state === PlatformModuleFlag::BETA && $betaOrganizationIds === []) {
            throw new InvalidArgumentException(
                "Beta state for '{$moduleKey}' needs at least one organization, "
                .'otherwise it is just a confusingly-named disable.'
            );
        }

        $this->cache = null;

        return PlatformModuleFlag::updateOrCreate(
            ['module_key' => $moduleKey],
            [
                'state' => $state,
                'reason' => $reason,
                'changed_by' => $changedBy,
                'beta_organization_ids' => $state === PlatformModuleFlag::BETA
                    ? array_values($betaOrganizationIds)
                    : null,
            ]
        );
    }

    public function flushCache(): void
    {
        $this->cache = null;
    }

    /** @return array<string, PlatformModuleFlag> */
    private function flags(): array
    {
        return $this->cache ??= PlatformModuleFlag::all()->keyBy('module_key')->all();
    }
}
