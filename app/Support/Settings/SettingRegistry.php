<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * Every setting the platform knows about (plan §6).
 *
 * Grouped by concern. The `allowedScopes` on each is the real design work —
 * where a setting may be set determines who can change it, and for security
 * policy that IS the enforcement (see §Security below).
 *
 * Settings whose shape depends on a module that doesn't exist yet are noted at
 * the bottom rather than guessed at. Adding one later costs about four lines.
 */
class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $settings = [];

    public function __construct()
    {
        foreach ([
            ...$this->localisation(),
            ...$this->operations(),
            ...$this->money(),
            ...$this->numbering(),
            ...$this->security(),
            ...$this->notifications(),
            ...$this->customers(),
        ] as $definition) {
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

    /** @return array<string, SettingDefinition[]> for a grouped settings UI */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->settings as $definition) {
            $group = str_contains($definition->key, '.')
                ? strstr($definition->key, '.', true)
                : 'general';

            $groups[$group][] = $definition;
        }

        return $groups;
    }

    // ─────────────────────────────────────────────────────────────
    // Localisation — cascades all the way down. A New Jersey franchise
    // and a Texas one legitimately differ.
    // ─────────────────────────────────────────────────────────────
    private function localisation(): array
    {
        return [
            new SettingDefinition(
                key: 'locale', label: 'Language',
                type: SettingDefinition::TYPE_ENUM, default: 'en',
                options: ['en', 'es'],   // §8 — more addable without a code change
                help: 'Users may override this for themselves.',
            ),
            new SettingDefinition(
                key: 'timezone', label: 'Default timezone',
                type: SettingDefinition::TYPE_STRING, default: 'America/New_York',
                help: 'A franchise with its own timezone set overrides this.',
            ),
            new SettingDefinition(
                key: 'date_format', label: 'Date format',
                type: SettingDefinition::TYPE_ENUM, default: 'M/D/YYYY',
                options: ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'],
            ),
            new SettingDefinition(
                key: 'time_format', label: 'Time format',
                type: SettingDefinition::TYPE_ENUM, default: '12h',
                options: ['12h', '24h'],
            ),
            new SettingDefinition(
                key: 'week_starts_on', label: 'Week starts on',
                type: SettingDefinition::TYPE_ENUM, default: 'sunday',
                options: ['sunday', 'monday'],
            ),
            new SettingDefinition(
                key: 'measurement_units', label: 'Units',
                type: SettingDefinition::TYPE_ENUM, default: 'imperial',
                options: ['imperial', 'metric'],
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Operations
    // ─────────────────────────────────────────────────────────────
    private function operations(): array
    {
        return [
            new SettingDefinition(
                key: 'ops.business_hours', label: 'Business hours',
                type: SettingDefinition::TYPE_JSON,
                // Per weekday. `null` means closed — distinct from 00:00–00:00,
                // which would otherwise read as "open, for zero minutes".
                default: [
                    'monday'    => ['open' => '08:00', 'close' => '17:00'],
                    'tuesday'   => ['open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['open' => '08:00', 'close' => '17:00'],
                    'thursday'  => ['open' => '08:00', 'close' => '17:00'],
                    'friday'    => ['open' => '08:00', 'close' => '17:00'],
                    'saturday'  => null,
                    'sunday'    => null,
                ],
                help: 'Franchises usually differ — set it at the level that varies.',
            ),
            new SettingDefinition(
                key: 'ops.fiscal_year_starts_month', label: 'Fiscal year starts',
                type: SettingDefinition::TYPE_INT, default: 1,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Month number, 1–12. Organization-wide so reporting periods line up.',
            ),
            new SettingDefinition(
                key: 'ops.default_job_duration_minutes', label: 'Default job duration',
                type: SettingDefinition::TYPE_INT, default: 60,
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Money — franchise-level because tax is jurisdictional. A New Jersey
    // franchise and a Texas one have genuinely different rates, and that is
    // exactly the kind of thing that must not be one organization-wide number.
    // ─────────────────────────────────────────────────────────────
    private function money(): array
    {
        return [
            new SettingDefinition(
                key: 'money.tax_rate', label: 'Default tax rate (%)',
                type: SettingDefinition::TYPE_DECIMAL, default: 0.0,
                help: 'A percentage, not an amount. Set per franchise where rates differ.',
            ),
            new SettingDefinition(
                key: 'money.tax_label', label: 'Tax label',
                type: SettingDefinition::TYPE_STRING, default: 'Sales Tax',
                help: 'Shown on invoices — "Sales Tax", "VAT", "GST".',
            ),
            new SettingDefinition(
                key: 'money.tax_inclusive_pricing', label: 'Prices include tax',
                type: SettingDefinition::TYPE_BOOL, default: false,
            ),
            new SettingDefinition(
                key: 'money.payment_terms_days', label: 'Payment terms (days)',
                type: SettingDefinition::TYPE_INT, default: 30,
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Document numbering
    //
    // Franchise-level on purpose: invoices belong to the franchise that did the
    // work (§5.2), so their numbering sequences must not collide across
    // franchises. A shared organization-wide sequence would interleave two
    // franchises' invoices, which auditors dislike intensely.
    // ─────────────────────────────────────────────────────────────
    private function numbering(): array
    {
        return [
            new SettingDefinition(
                key: 'numbering.job_prefix', label: 'Job number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'JOB-',
            ),
            new SettingDefinition(
                key: 'numbering.invoice_prefix', label: 'Invoice number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'INV-',
            ),
            new SettingDefinition(
                key: 'numbering.estimate_prefix', label: 'Estimate number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'EST-',
            ),
            new SettingDefinition(
                key: 'numbering.pad_to_digits', label: 'Pad numbers to',
                type: SettingDefinition::TYPE_INT, default: 5,
                help: 'INV-00042 rather than INV-42.',
            ),
            new SettingDefinition(
                key: 'numbering.include_year', label: 'Include the year',
                type: SettingDefinition::TYPE_BOOL, default: false,
                help: 'INV-2026-00042.',
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Security — ORGANIZATION ONLY, every one of them.
    //
    // This is the enforcement, not a preference. If a franchise could set these,
    // a franchise administrator could switch off a policy the organization
    // imposed. Restricting the scope is simpler and stronger than trying to
    // enforce "override only in the stricter direction".
    // ─────────────────────────────────────────────────────────────
    private function security(): array
    {
        $orgOnly = [SettingDefinition::SCOPE_ORGANIZATION];

        return [
            new SettingDefinition(
                key: 'security.require_two_factor', label: 'Require two-factor authentication',
                type: SettingDefinition::TYPE_BOOL, default: false,
                allowedScopes: $orgOnly,
            ),
            new SettingDefinition(
                key: 'security.session_timeout_minutes', label: 'Sign out after inactivity (minutes)',
                type: SettingDefinition::TYPE_INT, default: 480,
                allowedScopes: $orgOnly,
            ),
            new SettingDefinition(
                key: 'security.password_min_length', label: 'Minimum password length',
                type: SettingDefinition::TYPE_INT, default: 12,
                allowedScopes: $orgOnly,
            ),
            new SettingDefinition(
                key: 'security.password_expiry_days', label: 'Password expires after (days)',
                type: SettingDefinition::TYPE_INT, default: 0,
                allowedScopes: $orgOnly,
                help: '0 disables expiry. Forced rotation encourages weaker passwords — NIST advises against it.',
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Notifications — organization default, franchise override.
    //
    // Per-CATEGORY toggles arrive with the modules that raise them (§5.5); these
    // are the channel-level defaults every module will respect.
    // ─────────────────────────────────────────────────────────────
    private function notifications(): array
    {
        return [
            new SettingDefinition(
                key: 'notifications.email_enabled', label: 'Send email notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
            ),
            new SettingDefinition(
                key: 'notifications.push_enabled', label: 'Send push notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
            ),
            new SettingDefinition(
                key: 'notifications.in_app_enabled', label: 'Show in-app notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
            ),
            new SettingDefinition(
                key: 'notifications.quiet_hours', label: 'Quiet hours',
                type: SettingDefinition::TYPE_JSON,
                default: ['start' => null, 'end' => null],
                help: 'Suppress non-urgent notifications. Null disables.',
            ),
            new SettingDefinition(
                key: 'notifications.reply_to_email', label: 'Reply-to address',
                type: SettingDefinition::TYPE_STRING, default: '',
                help: 'Blank uses the franchise contact address.',
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Customers — matters more than usual because customer sharing is ON
    // (§5.2), so duplicates surface across the whole organization.
    // ─────────────────────────────────────────────────────────────
    private function customers(): array
    {
        return [
            new SettingDefinition(
                key: 'customers.duplicate_match_on', label: 'Detect duplicates by',
                type: SettingDefinition::TYPE_ENUM, default: 'phone_or_email',
                options: ['phone_or_email', 'phone', 'email', 'name_and_address', 'off'],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Organization-wide, since sharing means duplicates cross franchises.',
            ),
            new SettingDefinition(
                key: 'customers.warn_on_cross_franchise_edit', label: 'Warn when editing another franchise\'s customer',
                type: SettingDefinition::TYPE_BOOL, default: true,
                help: 'Complements the owning-franchise tag (§5.2).',
            ),

            /*
             * The permitted job statuses.
             *
             * Data, not an enum column: an organization wanting its own word for
             * "on hold" should not need a migration (§2.1 category 5). That a job
             * HAS a status stays a platform invariant and lives in code.
             *
             * ORGANIZATION-ONLY on purpose. Statuses varying per franchise would
             * make a roll-up list a mix of vocabularies, and any report grouped by
             * status would silently split one real state into several.
             */
            new SettingDefinition(
                key: 'jobs.statuses', label: 'Job statuses',
                type: SettingDefinition::TYPE_JSON,
                default: ['scheduled', 'in_progress', 'completed', 'cancelled'],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Renaming one does not rename it on existing jobs — those keep the '
                    .'value they were saved with.',
            ),
        ];
    }
}
