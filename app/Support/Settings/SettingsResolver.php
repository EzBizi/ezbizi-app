<?php

namespace App\Support\Settings;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolve a setting for a scope by walking up the hierarchy (plan §6).
 *
 * ── The chain, and the step my first draft missed ────────────────
 *
 *   Franchise → owning Company OR Sub Company
 *             → parent Company (when the owner is a Sub Company)
 *             → Organization
 *             → definition default
 *
 * My original plan wrote this as Franchise → Company → Organization, which skips
 * Sub Companies entirely: a franchise under Serecor would have inherited from
 * Serenisima directly, silently ignoring every setting Serecor defined. Nothing
 * would error — the value would just quietly be wrong, which is the worst kind
 * of bug in a settings system because it looks like it's working.
 */
class SettingsResolver
{
    /** Resolved values per scope, for the life of the request. */
    private array $cache = [];

    public function __construct(
        private readonly SettingRegistry $registry,
    ) {}

    /**
     * An organization-level setting by organization id, without loading it:
     * the current tenant's when null, the default when there is neither (an
     * unsaved model). For commands that read several tenants' values.
     */
    public static function forOrganization(string $key, ?string $organizationId = null): mixed
    {
        $tenant = app(TenantContext::class);
        $organizationId ??= $tenant->id();
        $resolver = app(self::class);

        if ($organizationId === null) {
            return $resolver->registry->getOrFail($key)->default;
        }

        $organization = (new Organization)->forceFill(['id' => $organizationId]);

        return $organizationId === $tenant->id()
            ? $resolver->get($key, $organization)
            : $tenant->run($organization, fn () => $resolver->get($key, $organization));
    }

    /**
     * The effective value for this scope.
     *
     * @param  Organization|Company|Franchise  $scope
     */
    public function get(string $key, Model $scope): mixed
    {
        $definition = $this->registry->getOrFail($key);

        foreach ($this->chainFor($scope) as $link) {
            $override = $this->overridesFor($link)[$key] ?? null;

            if ($override !== null) {
                return $definition->cast($override);
            }
        }

        return $definition->default;
    }

    /** Every setting resolved for a scope — what a settings screen renders. */
    public function all(Model $scope): array
    {
        $resolved = [];

        foreach ($this->registry->all() as $key => $definition) {
            $resolved[$key] = $this->get($key, $scope);
        }

        return $resolved;
    }

    /**
     * Where a value actually comes from — the scope that set it, or null when
     * nothing has and the default applies.
     *
     * Worth having: "why is this franchise on Spanish?" is unanswerable if the
     * UI can only show the effective value. Without it, administrators change
     * the wrong level and wonder why nothing happened.
     */
    public function sourceOf(string $key, Model $scope): ?Model
    {
        $this->registry->getOrFail($key);

        foreach ($this->chainFor($scope) as $link) {
            if (isset($this->overridesFor($link)[$key])) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Set an override at a scope.
     *
     * Refuses scopes the definition doesn't allow — that's what stops a franchise
     * weakening an organization-level security policy.
     */
    public function set(string $key, Model $scope, mixed $value): Setting
    {
        $definition = $this->registry->getOrFail($key);
        $scopeType  = $this->scopeTypeOf($scope);

        if (! $definition->allowsScope($scopeType)) {
            throw new InvalidArgumentException(sprintf(
                "Setting '%s' cannot be set at %s level. Allowed: %s.",
                $key, $scopeType, implode(', ', $definition->allowedScopes)
            ));
        }

        // cast() validates — an out-of-range enum throws here rather than being
        // stored and failing mysteriously on read.
        $cast = $definition->cast($value);

        $this->flush();

        return Setting::updateOrCreate(
            ['scope_type' => $scopeType, 'scope_id' => $scope->getKey(), 'key' => $key],
            [
                'organization_id' => $scope instanceof Organization
                    ? $scope->getKey()
                    : $scope->organization_id,
                'value' => $cast,
            ]
        );
    }

    /**
     * Remove an override, so the scope inherits again.
     *
     * Distinct from setting the parent's current value: that would freeze today's
     * value as an explicit choice, and stop tracking future changes to the parent.
     */
    public function forget(string $key, Model $scope): void
    {
        $this->registry->getOrFail($key);
        $this->flush();

        Setting::where('scope_type', $this->scopeTypeOf($scope))
            ->where('scope_id', $scope->getKey())
            ->where('key', $key)
            ->delete();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * The scopes to consult, nearest first.
     *
     * @return Model[]
     */
    private function chainFor(Model $scope): array
    {
        if ($scope instanceof Organization) {
            return [$scope];
        }

        if ($scope instanceof Company) {
            // A Sub Company consults its parent before the organization.
            return array_values(array_filter([$scope, $scope->parent, $scope->organization]));
        }

        if ($scope instanceof Franchise) {
            $company = $scope->company;

            return array_values(array_filter([
                $scope,
                $company,
                $company?->parent,      // ← the step the first draft omitted
                $scope->organization,
            ]));
        }

        throw new InvalidArgumentException(
            'Settings resolve against Organization, Company or Franchise only, got '.$scope::class
        );
    }

    private function overridesFor(Model $scope): array
    {
        $cacheKey = $this->scopeTypeOf($scope).':'.$scope->getKey();

        return $this->cache[$cacheKey] ??= Setting::query()
            ->where('scope_type', $this->scopeTypeOf($scope))
            ->where('scope_id', $scope->getKey())
            ->pluck('value', 'key')
            ->all();
    }

    private function scopeTypeOf(Model $scope): string
    {
        return match (true) {
            $scope instanceof Organization => SettingDefinition::SCOPE_ORGANIZATION,
            $scope instanceof Company      => SettingDefinition::SCOPE_COMPANY,
            $scope instanceof Franchise    => SettingDefinition::SCOPE_FRANCHISE,
            default => throw new InvalidArgumentException('Not a settings scope: '.$scope::class),
        };
    }
}
