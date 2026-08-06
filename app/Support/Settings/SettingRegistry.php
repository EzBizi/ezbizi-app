<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * Every setting the platform knows about (plan §6).
 *
 * Deliberately small. Every entry here is one that genuinely cascades or is
 * genuinely policy — inventing settings for modules that don't exist yet would
 * be designing against imagined requirements, and the review flagged exactly
 * that risk for the workflow engine.
 *
 * Modules add their own as they're built. `franchise_label`, `reporting_currency`
 * and `shares_customers` deliberately remain columns on Organization: they are
 * organization-only, structural, and read on nearly every request — a cascade
 * lookup would buy nothing.
 */
class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $settings = [];

    public function __construct()
    {
        foreach ($this->defaults() as $definition) {
            $this->register($definition);
        }
    }

    public function register(SettingDefinition $definition): void
    {
        if (isset($this->settings[$definition->key])) {
            throw new InvalidArgumentException("Setting '{$definition->key}' is already registered.");
        }

        $this->settings[$definition->key] = $definition;
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->settings[$key] ?? null;
    }

    public function getOrFail(string $key): SettingDefinition
    {
        return $this->get($key) ?? throw new InvalidArgumentException(
            "Unknown setting '{$key}'. Definitions live in code (§2.1) — add it to SettingRegistry."
        );
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->settings;
    }

    /** Settings a given scope may set — what the settings screen renders. */
    public function forScope(string $scope): array
    {
        return array_filter($this->settings, fn (SettingDefinition $d) => $d->allowsScope($scope));
    }

    /** @return SettingDefinition[] */
    private function defaults(): array
    {
        return [
            new SettingDefinition(
                key: 'locale',
                label: 'Language',
                type: SettingDefinition::TYPE_ENUM,
                default: 'en',
                options: ['en', 'es'],   // §8; more addable without code changes
                help: 'Users may override this for themselves.',
            ),

            new SettingDefinition(
                key: 'date_format',
                label: 'Date format',
                type: SettingDefinition::TYPE_ENUM,
                default: 'M/D/YYYY',
                options: ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'],
            ),

            new SettingDefinition(
                key: 'week_starts_on',
                label: 'Week starts on',
                type: SettingDefinition::TYPE_ENUM,
                default: 'sunday',
                options: ['sunday', 'monday'],
            ),

            // ── security policy: organization-only, deliberately ──
            //
            // If a franchise could set these, a franchise administrator could
            // weaken a policy the organization imposed. Restricting the scope is
            // simpler and stronger than trying to enforce "override only in the
            // stricter direction".
            new SettingDefinition(
                key: 'require_two_factor',
                label: 'Require two-factor authentication',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Applies to every user in the organization.',
            ),

            new SettingDefinition(
                key: 'session_timeout_minutes',
                label: 'Sign out after inactivity (minutes)',
                type: SettingDefinition::TYPE_INT,
                default: 480,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
            ),
        ];
    }
}
