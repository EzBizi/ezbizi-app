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
            'email'         => ['Shared inboxes',       $this->email()],
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
                optionLabels: ['en' => 'English', 'es' => 'Spanish'],
                help: 'Users may override this for themselves under Your account.',
            ),
            new SettingDefinition(
                key: 'timezone', label: 'Default timezone',
                type: SettingDefinition::TYPE_ENUM, default: 'Etc/GMT+5',
                options: self::timezones(),
                optionLabels: self::timezoneLabels(),
                help: 'Hours from UTC. Clocks stay on this offset all year — there is no daylight saving. A franchise with its own timezone set overrides this.',
            ),
            new SettingDefinition(
                key: 'date_format', label: 'Date format',
                type: SettingDefinition::TYPE_ENUM, default: 'M/D/YYYY',
                options: ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'],
                optionLabels: [
                    'M/D/YYYY'   => 'M/D/YYYY — 8/24/2026',
                    'D/M/YYYY'   => 'D/M/YYYY — 24/8/2026',
                    'YYYY-MM-DD' => 'YYYY-MM-DD — 2026-08-24',
                ],
            ),
            new SettingDefinition(
                key: 'time_format', label: 'Time format',
                type: SettingDefinition::TYPE_ENUM, default: '12h',
                options: ['12h', '24h'],
                optionLabels: [
                    '12h' => '12-hour — 3:00 PM',
                    '24h' => '24-hour — 15:00',
                ],
            ),
            new SettingDefinition(
                key: 'week_starts_on', label: 'Week starts on',
                type: SettingDefinition::TYPE_ENUM, default: 'sunday',
                options: ['sunday', 'monday'],
                optionLabels: ['sunday' => 'Sunday', 'monday' => 'Monday'],
                notYetInEffect: 'Takes effect when the scheduling calendar ships. Nothing in the '
                    .'app lays out a week yet, so there is no first column for this to move.',
            ),
            new SettingDefinition(
                key: 'measurement_units', label: 'Units',
                type: SettingDefinition::TYPE_ENUM, default: 'imperial',
                options: ['imperial', 'metric'],
                optionLabels: [
                    'imperial' => 'Imperial — lb, mi, °F',
                    'metric'   => 'Metric — kg, km, °C',
                ],
                notYetInEffect: 'Takes effect when jobs record distances and areas. Nothing in '
                    .'the app shows a measurement yet, in either system.',
            ),
            /*
             * Paper, for the PDF copy of a signed contract.
             *
             * It lived in `config/contracts.php`, whose own docblock conceded
             * that was the wrong home: §2.1's test is "would a second
             * INSTALLATION need a different value", and paper only half passes
             * it, because two ORGANIZATIONS on one installation disagree the
             * moment one of them is in North America. A deployment-level default
             * meant a franchise in Ohio and its parent in Manchester printed on
             * the same wrong sheet.
             *
             * Two options rather than dompdf's forty. The renderer accepts any
             * size dompdf knows, and a dropdown offering `b7` and `folio` asks
             * somebody to have an opinion about a sheet nobody prints a contract
             * on. These are the two the world actually uses; the renderer still
             * refuses an unknown value rather than substituting one, so nothing
             * here has to be the enforcement.
             *
             * The signed document itself has no page size — it is HTML and it
             * reflows. This is a property of the COPY, which is why it is
             * settled here and not on the contract.
             */
            new SettingDefinition(
                key: 'contract_pdf_paper', label: 'Contract paper size',
                type: SettingDefinition::TYPE_ENUM, default: 'a4',
                options: ['a4', 'letter'],
                optionLabels: [
                    'a4'     => 'A4',
                    'letter' => 'US Letter',
                ],
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
                type: SettingDefinition::TYPE_ENUM, default: 1,
                options: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                optionLabels: [
                    '1'  => 'January',
                    '2'  => 'February',
                    '3'  => 'March',
                    '4'  => 'April',
                    '5'  => 'May',
                    '6'  => 'June',
                    '7'  => 'July',
                    '8'  => 'August',
                    '9'  => 'September',
                    '10' => 'October',
                    '11' => 'November',
                    '12' => 'December',
                ],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Organization-wide so reporting periods line up.',
                notYetInEffect: 'Takes effect when reporting offers a financial year. No figure '
                    .'in the app is grouped by one yet.',
            ),
            new SettingDefinition(
                key: 'ops.default_job_duration_minutes', label: 'Default job duration',
                type: SettingDefinition::TYPE_ENUM, default: 60,
                options: [15, 30, 45, 60, 90, 120, 180, 240],
                optionLabels: [
                    '15'  => '15 minutes',
                    '30'  => '30 minutes',
                    '45'  => '45 minutes',
                    '60'  => '1 hour',
                    '90'  => '1½ hours',
                    '120' => '2 hours',
                    '180' => '3 hours',
                    '240' => '4 hours',
                ],
                notYetInEffect: 'Takes effect when scheduling ships. A job can already hold a '
                    .'length, but nothing fills one in, so this number is never reached for.',
            ),

            /*
             * Where the boxes sit on the Organization tab's structure canvas.
             *
             * ── Why it is a setting at all ───────────────────────
             *
             * It used to be one person's preference, in
             * `users.preferences['organization.canvas']` (§2.1 category 6). The
             * owner's answer to "should this be shared" was "should be shared
             * obviously", so it is a tenant setting now (§2.1 category 5): one
             * picture of the business that everybody in the business sees.
             *
             * Held here rather than in a store of its own because the settings
             * system holds it with no strain at all. The unique
             * (scope_type, scope_id, key) is exactly one row per organization,
             * which is the invariant this value needs and would otherwise have
             * had to be enforced by hand; and the `json` column has roughly two
             * thousand times the headroom the cap allows (30KB against a 4GB
             * type).
             *
             * ── In `operations` rather than a group of its own ───
             *
             * A new group whose only member is filtered out of the settings
             * screen is a heading that can never render. `operations` is where
             * "how this organization runs day to day" already lives, and
             * SettingRegistryTest::test_every_setting_declares_a_named_group is
             * satisfied without inventing one.
             *
             * Dotted key, deliberately: SettingReaders' own docblock records
             * that an undotted key collides with column names and can score a
             * false reader.
             */
            new SettingDefinition(
                key:   'organization.structure_canvas',
                label: 'Structure arrangement',
                type:  SettingDefinition::TYPE_JSON,
                // [] rather than null: castJson([]) returns [], which is what
                // test_every_default_survives_its_own_cast needs, and "nobody
                // has arranged it" and "the arrangement is empty" mean the same
                // thing to the canvas — every box goes to its automatic place.
                default: [],
                // One arrangement for the whole business, which is the entire
                // point of it now. A company-scoped or franchise-scoped
                // override would be a second picture of one structure, and the
                // first time the two disagreed somebody would be looking at a
                // chart of a business that does not exist.
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Where the boxes sit on the Organization tab. Everyone in the '
                    .'organization sees the same arrangement, so moving one moves it for '
                    .'everybody.',
                editedOn: 'Organization → Structure',
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
                type: SettingDefinition::TYPE_ENUM, default: 'Sales Tax',
                options: ['Sales Tax', 'VAT', 'GST', 'HST', 'PST'],
                help: 'Shown on invoices.',
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
                key: 'money.payment_terms_days', label: 'Payment terms',
                type: SettingDefinition::TYPE_ENUM, default: 30,
                options: [0, 7, 14, 15, 30, 45, 60, 90],
                optionLabels: [
                    '0'  => 'Due on receipt',
                    '7'  => 'Net 7',
                    '14' => 'Net 14',
                    '15' => 'Net 15',
                    '30' => 'Net 30',
                    '45' => 'Net 45',
                    '60' => 'Net 60',
                    '90' => 'Net 90',
                ],
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
                key: 'numbering.vehicle_prefix', label: 'Vehicle number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'V-',
            ),
            new SettingDefinition(
                key: 'numbering.item_prefix', label: 'Item number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'I-',
            ),
            new SettingDefinition(
                key: 'numbering.po_prefix', label: 'Purchase order number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'PO-',
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
                type: SettingDefinition::TYPE_ENUM, default: 5,
                options: [3, 4, 5, 6, 7, 8],
                optionLabels: [
                    '3' => '3 digits — INV-042',
                    '4' => '4 digits — INV-0042',
                    '5' => '5 digits — INV-00042',
                    '6' => '6 digits — INV-000042',
                    '7' => '7 digits',
                    '8' => '8 digits',
                ],
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
                key: 'security.session_timeout_minutes', label: 'Sign out after inactivity',
                type: SettingDefinition::TYPE_DURATION, default: 480,
                allowedScopes: $orgOnly,
                // Stored as minutes so SessionLifetime can keep doing arithmetic
                // in one unit. The screen offers minutes, hours or days; the
                // floor and ceiling are stated because the code clamps to both.
                help: 'Counts from the last thing you did, not from signing in. '
                    .'Pick minutes, hours or days. Below 5 minutes is treated as 5, and '
                    .'no session outlives 30 days however much it is used. Does not apply '
                    .'to "Keep me signed in", which lasts up to those 30 days.',
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
                key: 'security.password_expiry_days', label: 'Password expires after',
                type: SettingDefinition::TYPE_ENUM, default: 0,
                options: [0, 30, 60, 90, 180, 365],
                optionLabels: [
                    '0'   => 'Never',
                    '30'  => '30 days',
                    '60'  => '60 days',
                    '90'  => '90 days',
                    '180' => '6 months',
                    '365' => '1 year',
                ],
                allowedScopes: $orgOnly,
                help: 'Never is the default. Forced rotation encourages weaker passwords — NIST advises against it.',
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
    // Shared inboxes (the Email module)
    //
    // All three scopes, deliberately. This is a preference about how a TEAM
    // works together rather than a security control: it changes who can see that
    // a message has been opened, and nothing about who can open it — that is
    // App\Support\Email\MailboxAccess, which asks for a grant and for §4 reach
    // and is not affected by anything here.
    //
    // So the franchise-level override is legitimate in a way it would not be for
    // a security setting: a branch running one inbox between three people wants
    // the shared view, and a company where one person's mailbox happens to be
    // connected may not. Compare security(), where every setting is organization
    // -only precisely so a franchise cannot weaken a policy imposed above it.
    // ─────────────────────────────────────────────────────────────
    private function email(): array
    {
        return [
            /*
             * The owner's words: "perhaps we can let the organization choose in
             * settings if it's shared or not shared view."
             *
             * An enum rather than a bool. `shared_read_state: true` reads
             * ambiguously on a screen — shared with whom, and what is the
             * opposite? — and a two-value enum makes the settings screen render
             * the two answers as words somebody can choose between.
             *
             * SHARED is the default because it is what the module is FOR: an
             * inbox several people answer, where the question that matters is
             * "has anybody dealt with this yet". A private default would make
             * the commonest need something an organization has to discover a
             * setting to get.
             */
            new SettingDefinition(
                key: 'email.read_state', label: 'Who can see that a message has been opened',
                type: SettingDefinition::TYPE_ENUM, default: 'shared',
                options: ['shared', 'private'],
                optionLabels: [
                    'shared'  => 'The whole team',
                    'private' => 'Only the person who opened it',
                ],
                help: 'Shared shows "Seen by" beside each message, so the team can tell what '
                    .'has already been picked up. Private keeps that to each person. Changing '
                    .'it does not lose anything — the marks are kept either way, and switching '
                    .'back shows them again.',
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
                optionLabels: [
                    'phone_or_email'   => 'Phone or email',
                    'phone'            => 'Phone',
                    'email'            => 'Email',
                    'name_and_address' => 'Name and address',
                    'off'              => 'Do not detect',
                ],
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
                default: [
                    ['value' => 'scheduled', 'label' => 'Scheduled'],
                    ['value' => 'in_progress', 'label' => 'In progress'],
                    ['value' => 'completed', 'label' => 'Completed'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'The name is what people see. Stored as is what jobs keep. Changing '
                    .'a stored value also updates jobs that already have it.',
            ),
        ];
    }

    /**
     * Whole-hour UTC offsets, stored as IANA identifiers PHP already accepts.
     *
     * People pick UTC-5, not America/New_York. The franchise column and
     * Laravel's `timezone` rule still need a real zone name, so the value on
     * the wire is `UTC` or `Etc/GMT…` (POSIX sign: Etc/GMT+5 is UTC-5).
     *
     * @return list<string>
     */
    private static function timezones(): array
    {
        return array_keys(self::timezoneLabels());
    }

    /**
     * @return array<string, string>
     */
    private static function timezoneLabels(): array
    {
        $labels = [];

        foreach (range(-12, 14) as $hours) {
            $labels[self::timezoneIdForOffset($hours)] = self::timezoneLabelForOffset($hours);
        }

        return $labels;
    }

    private static function timezoneIdForOffset(int $hours): string
    {
        if ($hours === 0) {
            return 'UTC';
        }

        // POSIX: Etc/GMT+5 is UTC-5.
        return 'Etc/GMT'.($hours > 0 ? '-' : '+').abs($hours);
    }

    private static function timezoneLabelForOffset(int $hours): string
    {
        if ($hours === 0) {
            return 'UTC';
        }

        return 'UTC'.($hours > 0 ? '+' : '-').abs($hours);
    }
}
