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
 *
 * ── Every setting states whether it does anything ────────────────
 *
 * Seventeen of the thirty below configure features that have not been built.
 * They saved, they audited, and they changed nothing — an administrator raising
 * the tax rate for a new state got a stored number and no tax on anything. That
 * is worse than the setting being missing, because the screen presented it as
 * working.
 *
 * So a definition that nothing acts on carries `notYetInEffect`, a sentence for
 * the administrator saying what it is waiting for. {@see SettingReaders} checks
 * every one of those claims against the codebase and the test suite fails in
 * both directions — silence about a dead setting, and a stale "not yet" on one
 * that has since been wired. Marking a setting pending is not a way to skip
 * wiring it; it is the way to be honest until somebody does.
 */
class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $settings = [];

    /**
     * Which concern each setting belongs to, and what to call that concern.
     *
     * @var array<string, array{key: string, label: string}>
     */
    private array $groups = [];

    /**
     * The catalogue, in the order a person should meet it.
     *
     * The grouping lives HERE, beside the definitions, because it is a property
     * of the platform's design and not of the key's spelling. The settings
     * screen used to split on the dot in the key and shout the result, so
     * `ops.business_hours` sat under a heading reading "OPS" and
     * `jobs.statuses` under "JOBS"; nobody wrote those words, they fell out of
     * an identifier. A client deriving them cannot do better, because the fact
     * that `numbering.*` is "Document numbering" is not recoverable from the
     * string `numbering`.
     *
     * Registration order is response order, and therefore the order the screen
     * shows — so it reads localisation first and the rarely-touched things
     * last, rather than alphabetically by an identifier nobody sees.
     */
    public function __construct()
    {
        foreach ([
            'localisation'  => ['Language and formats', $this->localisation()],
            'operations'    => ['Operations',           $this->operations()],
            'money'         => ['Money and tax',        $this->money()],
            'numbering'     => ['Document numbering',   $this->numbering()],
            'security'      => ['Security',             $this->security()],
            'notifications' => ['Notifications',        $this->notifications()],
            'customers'     => ['Customers',            $this->customers()],
            'jobs'          => ['Jobs',                 $this->jobs()],
        ] as $group => [$label, $definitions]) {
            foreach ($definitions as $definition) {
                $this->register($definition, $group, $label);
            }
        }
    }

    /**
     * `$group` defaults to "other" rather than to something derived from the
     * key: a definition registered from outside this class has not said where
     * it belongs, and guessing from its prefix is the habit this replaced.
     */
    public function register(
        SettingDefinition $definition,
        string $group = 'other',
        string $groupLabel = 'Other',
    ): void {
        if (isset($this->settings[$definition->key])) {
            throw new InvalidArgumentException("Setting '{$definition->key}' is already registered.");
        }

        $this->settings[$definition->key] = $definition;
        $this->groups[$definition->key]   = ['key' => $group, 'label' => $groupLabel];
    }

    /**
     * The concern a setting belongs to — `['key' => ..., 'label' => ...]`.
     *
     * The label is what a screen prints. The key is what it can filter or
     * remember a collapsed section by without printing an English word.
     *
     * @return array{key: string, label: string}
     */
    public function groupOf(string $key): array
    {
        return $this->groups[$key] ?? ['key' => 'other', 'label' => 'Other'];
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

        foreach ($this->settings as $key => $definition) {
            $groups[$this->groupOf($key)['key']][] = $definition;
        }

        return $groups;
    }

    /**
     * The settings that configure something that does not exist yet.
     *
     * @return array<string, SettingDefinition>
     */
    public function notYetInEffect(): array
    {
        return array_filter($this->settings, fn (SettingDefinition $d) => ! $d->isInEffect());
    }

    /**
     * The settings that actually do something.
     *
     * Named as the counterpart so a caller cannot get the sense backwards, which
     * is easy to do with a single filter and an exclamation mark.
     *
     * @return array<string, SettingDefinition>
     */
    public function inEffect(): array
    {
        return array_filter($this->settings, fn (SettingDefinition $d) => $d->isInEffect());
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
                notYetInEffect: 'Takes effect when the Spanish translation ships. Every screen '
                    .'is in English today, whichever language is chosen here.',
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
                notYetInEffect: 'Takes effect when the scheduling calendar ships. Nothing in the '
                    .'app lays out a week yet, so there is no first column for this to move.',
            ),
            new SettingDefinition(
                key: 'measurement_units', label: 'Units',
                type: SettingDefinition::TYPE_ENUM, default: 'imperial',
                options: ['imperial', 'metric'],
                notYetInEffect: 'Takes effect when jobs record distances and areas. Nothing in '
                    .'the app shows a measurement yet, in either system.',
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
                type: SettingDefinition::TYPE_HOURS,
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
                notYetInEffect: 'Takes effect when scheduling ships. It is what will stop a job '
                    .'being booked for a Sunday, and nothing books anything yet.',
            ),
            new SettingDefinition(
                key: 'ops.fiscal_year_starts_month', label: 'Fiscal year starts',
                type: SettingDefinition::TYPE_INT, default: 1,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Month number, 1–12. Organization-wide so reporting periods line up.',
                notYetInEffect: 'Takes effect when reporting offers a financial year. No figure '
                    .'in the app is grouped by one yet.',
            ),
            new SettingDefinition(
                key: 'ops.default_job_duration_minutes', label: 'Default job duration',
                type: SettingDefinition::TYPE_INT, default: 60,
                notYetInEffect: 'Takes effect when scheduling ships. A job can already hold a '
                    .'length, but nothing fills one in, so this number is never reached for.',
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
                notYetInEffect: 'Takes effect when invoicing ships. It will be the rate a new '
                    .'invoice starts at; nothing in the app charges tax today.',
            ),
            new SettingDefinition(
                key: 'money.tax_label', label: 'Tax label',
                type: SettingDefinition::TYPE_STRING, default: 'Sales Tax',
                help: 'Shown on invoices — "Sales Tax", "VAT", "GST".',
                notYetInEffect: 'Takes effect when invoicing ships. It is the word that will '
                    .'head the tax line, and there are no invoices to head yet.',
            ),
            new SettingDefinition(
                key: 'money.tax_inclusive_pricing', label: 'Prices include tax',
                type: SettingDefinition::TYPE_BOOL, default: false,
                notYetInEffect: 'Takes effect when invoicing ships. It decides whether a price '
                    .'already contains tax or has it added, and nothing totals a price yet.',
            ),
            new SettingDefinition(
                key: 'money.payment_terms_days', label: 'Payment terms (days)',
                type: SettingDefinition::TYPE_INT, default: 30,
                notYetInEffect: 'Takes effect when invoicing ships. It will set the due date on '
                    .'a new invoice; nothing in the app has a due date today.',
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
                notYetInEffect: 'Takes effect when invoicing ships. Job numbers already use '
                    .'their prefix — there are simply no invoices to number.',
            ),
            new SettingDefinition(
                key: 'numbering.estimate_prefix', label: 'Estimate number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'EST-',
                notYetInEffect: 'Takes effect when estimates ship. Job numbers already use '
                    .'their prefix — there are simply no estimates to number.',
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
                help: 'Anyone without an authenticator can still sign in, and is taken '
                    .'straight to setting one up — the rest of the app waits until they have.',
            ),
            new SettingDefinition(
                key: 'security.session_timeout_minutes', label: 'Sign out after inactivity (minutes)',
                type: SettingDefinition::TYPE_INT, default: 480,
                allowedScopes: $orgOnly,
                // The floor and the ceiling are stated because the code clamps to
                // both, and a screen that accepts 1 while the code enforces 5 is
                // the same quiet lie this setting was guilty of when nothing read
                // it at all. See App\Support\Auth\SessionLifetime for why each
                // number is where it is.
                help: 'Counts from the last thing you did, not from signing in. '
                    .'Below 5 is treated as 5, and no session outlives 30 days however '
                    .'much it is used.',
            ),
            new SettingDefinition(
                key: 'security.password_min_length', label: 'Minimum password length',
                type: SettingDefinition::TYPE_INT, default: 12,
                allowedScopes: $orgOnly,
                // Raise-only, and it says so, because "minimum password length"
                // reads like a number you can set in either direction.
                help: 'Can only make passwords longer than the platform minimum of 12, '
                    .'never shorter. Above 72 has no effect — the password hash stops '
                    .'reading there. Does not apply to the very first account, which is '
                    .'created before this organization exists.',
            ),
            new SettingDefinition(
                key: 'security.password_expiry_days', label: 'Password expires after (days)',
                type: SettingDefinition::TYPE_INT, default: 0,
                allowedScopes: $orgOnly,
                help: '0 disables expiry. Forced rotation encourages weaker passwords — NIST advises against it.',
                notYetInEffect: 'Takes effect when scheduled password expiry ships. Nobody is '
                    .'asked to change a password on a timetable today, whatever this says.',
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
                notYetInEffect: 'Takes effect when notifications can be sent by email. They are '
                    .'only shown inside the app today, so switching this off changes nothing.',
            ),
            new SettingDefinition(
                key: 'notifications.push_enabled', label: 'Send push notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
                notYetInEffect: 'Takes effect when there is a mobile app to push to. '
                    .'Notifications are only shown inside the app today.',
            ),
            new SettingDefinition(
                key: 'notifications.in_app_enabled', label: 'Show in-app notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
            ),
            new SettingDefinition(
                key: 'notifications.quiet_hours', label: 'Quiet hours',
                type: SettingDefinition::TYPE_TIME_RANGE,
                default: ['start' => null, 'end' => null],
                help: 'Suppress non-urgent notifications. Null disables.',
                notYetInEffect: 'Takes effect when a notification can be held back and delivered '
                    .'later. Every one is raised the moment it happens today.',
            ),
            new SettingDefinition(
                key: 'notifications.reply_to_email', label: 'Reply-to address',
                type: SettingDefinition::TYPE_STRING, default: '',
                help: 'Blank uses the franchise contact address.',
                notYetInEffect: 'Takes effect when notifications can be sent by email. There is '
                    .'no message yet for a reply to come back to.',
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
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Jobs
    //
    // `jobs.statuses` was declared inside customers() and inherited that
    // grouping, which put "Job statuses" under a Customers heading. It is a
    // section of its own now — the group is a stated fact rather than a
    // side effect of where the constructor call happened to sit.
    // ─────────────────────────────────────────────────────────────
    private function jobs(): array
    {
        return [
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
                type: SettingDefinition::TYPE_LIST,
                default: ['scheduled', 'in_progress', 'completed', 'cancelled'],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Renaming one does not rename it on existing jobs — those keep the '
                    .'value they were saved with.',
            ),
        ];
    }
}
