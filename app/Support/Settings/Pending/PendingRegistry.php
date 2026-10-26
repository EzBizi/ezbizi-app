<?php

namespace App\Support\Settings\Pending;

use App\Models\Company;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything Settings › Pending asks of each place.
 *
 * Two sources, so nothing is listed per tenant:
 *   - every SettingDefinition declared `expected: true` that resolves to
 *     nothing at a place (no value there and none inherited);
 *   - the record checks in checks(). A module adds one by appending to that
 *     list, the way SettingRegistry's groups are one list in one file.
 */
final class PendingRegistry
{
    public function __construct(
        private readonly SettingRegistry $settings,
        private readonly SettingsResolver $resolver,
    ) {}

    /** @return list<PendingCheck> */
    public function all(): array
    {
        return [...$this->fromSettings(), ...$this->checks()];
    }

    /** @return list<PendingCheck> */
    private function fromSettings(): array
    {
        $out = [];

        foreach ($this->settings->all() as $key => $definition) {
            if (! $definition->expected) {
                continue;
            }

            $out[] = new PendingCheck(
                key: "setting:{$key}",
                label: $definition->label,
                module: $this->settings->groupOf($key)['key'],
                // Asked only where it can be set: a company cannot fill an
                // organization-only setting, so it is not that company's gap.
                scopes: $definition->allowedScopes,
                permissions: array_values(array_filter([$definition->permissionRequired(), $definition->readPermission])),
                missing: fn (Model $place) => $this->isEmpty($this->resolver->get($key, $place)),
                fill: fn () => ['kind' => 'setting', 'key' => $key, 'type' => $definition->type],
            );
        }

        return $out;
    }

    /** @return list<PendingCheck> */
    private function checks(): array
    {
        $openCompany = fn () => [
            'kind'        => 'open',
            'destination' => 'settings',
            'hint'        => 'Open Settings, then Organization, then the company.',
        ];

        return [
            new PendingCheck(
                key: 'company.address',
                label: 'Address',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->address_line1) || blank($c->city),
                fill: $openCompany,
            ),
            new PendingCheck(
                key: 'company.phone',
                label: 'Phone number',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->phone),
                fill: fn (Company $c) => ['kind' => 'field', 'path' => "/companies/{$c->id}", 'field' => 'phone'],
            ),
            new PendingCheck(
                key: 'company.logo',
                label: 'Logo',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->logo_path),
                fill: $openCompany,
            ),
        ];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
