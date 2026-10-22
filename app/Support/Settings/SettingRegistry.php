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
            'localisation' => ['Language and formats', $this->localisation()],
            'operations' => ['Operations',           $this->operations()],
            'money' => ['Money and tax',        $this->money()],
            'numbering' => ['Document numbering',   $this->numbering()],
            'security' => ['Security',             $this->security()],
            'notifications' => ['Notifications',        $this->notifications()],
            'email' => ['Shared inboxes',       $this->email()],
            'customers' => ['Customers',            $this->customers()],
            'jobs' => ['Jobs',                 $this->jobs()],
            'human_resources' => ['Human Resources', $this->humanResources()],
            // Clock in and paid time off: every setting this group ever held.
            // Renamed from Human Resources when the pane left HR (2026-09-24).
            'work_hours' => ['Work Hours',       $this->hr()],
            'ai_assistance' => ['AI Assistance',  $this->assistant()],
            'field' => ['Field',              $this->field()],
            'phone' => ['Communication',      $this->phone()],
            'leads' => ['Leads',              $this->leads()],
            'scheduling' => ['Scheduling',         $this->scheduling()],
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
        $this->groups[$definition->key] = ['key' => $group, 'label' => $groupLabel];
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
                type: SettingDefinition::TYPE_ENUM, default: 'America/New_York',
                options: self::timezones(),
                optionLabels: self::timezoneLabels(),
                help: 'Pick a place if you want daylight saving handled for you — a place knows when the clocks change, so times stay right all year. The UTC offsets never change, which is only what you want for a clock that should ignore daylight saving; one of them is still the default, so set this deliberately. A franchise with its own timezone set overrides this.',
            ),
            new SettingDefinition(
                key: 'date_format', label: 'Date format',
                type: SettingDefinition::TYPE_ENUM, default: 'M/D/YYYY',
                options: ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'],
                optionLabels: [
                    'M/D/YYYY' => 'M/D/YYYY — 8/24/2026',
                    'D/M/YYYY' => 'D/M/YYYY — 24/8/2026',
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
                // Read by DashboardPeriod for the dashboard week bound (spec §6).
            ),
            new SettingDefinition(
                key: 'measurement_units', label: 'Units',
                type: SettingDefinition::TYPE_ENUM, default: 'imperial',
                options: ['imperial', 'metric'],
                optionLabels: [
                    'imperial' => 'Imperial — lb, mi, °F',
                    'metric' => 'Metric — kg, km, °C',
                ],
                notYetInEffect: 'EzBizi will use this once jobs record distances and areas.',
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
                // Letter, beside the US date format and Imperial units it sits
                // under: A4 there was the one non-US default (UI review,
                // 2026-10-08). No organization had chosen either on that day.
                type: SettingDefinition::TYPE_ENUM, default: 'letter',
                options: ['a4', 'letter'],
                optionLabels: [
                    'a4' => 'A4',
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
                    'monday' => ['open' => '08:00', 'close' => '17:00'],
                    'tuesday' => ['open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['open' => '08:00', 'close' => '17:00'],
                    'thursday' => ['open' => '08:00', 'close' => '17:00'],
                    'friday' => ['open' => '08:00', 'close' => '17:00'],
                    'saturday' => null,
                    'sunday' => null,
                ],
                // The "not yet in effect" banner is gone because it stopped
                // being true. It said "nothing books anything yet", and that
                // is still so for scheduling — but Clock In began reading this
                // on 2026-09-08 (TimeClock.php:327) to decide whether somebody
                // is clocking in outside hours, so the setting now has a real
                // consequence and the banner told administrators otherwise.
                //
                // Removed by the Phone-system session on 2026-09-09 because
                // SettingReadershipTest went red across the whole suite and
                // names this remedy. The wording of what it DOES is Clock In's
                // to sharpen; the claim that it does nothing was simply false.
                help: 'Franchises usually differ — set it at the level that varies. '
                    .'Clock in treats these as the working day for anyone without a personal work '
                    .'schedule: the lock screen, the end-of-shift reminder and the automatic clock-out '
                    .'all follow them, and a shift started before them is marked outside schedule. '
                    .'A personal schedule set on Human Resources › Records always wins.',
            ),
            new SettingDefinition(
                key: 'ops.fiscal_year_starts_month', label: 'Fiscal year starts',
                type: SettingDefinition::TYPE_ENUM, default: 1,
                options: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                optionLabels: [
                    '1' => 'January',
                    '2' => 'February',
                    '3' => 'March',
                    '4' => 'April',
                    '5' => 'May',
                    '6' => 'June',
                    '7' => 'July',
                    '8' => 'August',
                    '9' => 'September',
                    '10' => 'October',
                    '11' => 'November',
                    '12' => 'December',
                ],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Organization-wide so reporting periods line up.',
                notYetInEffect: 'EzBizi will use this once reports can be grouped by financial year.',
            ),
            new SettingDefinition(
                key: 'ops.default_job_duration_minutes', label: 'Default job duration',
                type: SettingDefinition::TYPE_ENUM, default: 60,
                options: [15, 30, 45, 60, 90, 120, 180, 240],
                optionLabels: [
                    '15' => '15 minutes',
                    '30' => '30 minutes',
                    '45' => '45 minutes',
                    '60' => '1 hour',
                    '90' => '1½ hours',
                    '120' => '2 hours',
                    '180' => '3 hours',
                    '240' => '4 hours',
                ],
                notYetInEffect: 'EzBizi will use this once it can book jobs into the calendar for you.',
            ),

            /*
             * How long a device may stay silent before DeviceOfflineWorker
             * raises `monitoring.device_offline` for it.
             *
             * 180 seconds is 3× the 60-second heartbeat cadence — one missed
             * beat is noise (a laptop asleep for a few seconds, a slow
             * network), three in a row is a device that stopped reporting.
             *
             * In `operations` rather than a group of its own, same reasoning
             * as `organization.structure_canvas` just above: a new group
             * with one member is a heading SettingRegistryTest's grouping
             * check gains nothing from.
             */
            new SettingDefinition(
                key: 'monitoring.offline_after_seconds', label: 'Mark a device offline after',
                type: SettingDefinition::TYPE_INT, default: 180,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_FRANCHISE],
                help: 'Seconds since a device last reported before it counts as offline. '
                    .'Devices report roughly every 60 seconds, so anything under that fires on '
                    .'a single missed beat.',
            ),

            /*
             * Whether a monitored machine shows a notice while somebody is
             * REMOTELY CONTROLLING it — not while it is merely being watched.
             *
             * Off by default, and silent when off: with this false nothing
             * changes on the machine at all, which is the product's T&C default
             * (viewing is silent). An organization that wants transparency for
             * its people turns it on, and then RemoteControlController rides the
             * resolved value out on the agent's `input-pending` poll as
             * `indicator`; the agent shows a tray notice only while it is BOTH
             * wanted and actually being driven (control_wanted && indicator).
             *
             * ORGANIZATION-ONLY, and resolved at the org scope in
             * RemoteControlController::inputPending. A franchise override would
             * be resolved-away silently there — the exact "looks like it's
             * working" bug SettingsResolver's docblock warns about — so the one
             * scope that sets it is the one scope that reads it. In `operations`
             * for the same reason `monitoring.offline_after_seconds` is: a group
             * of one is a heading the grouping test gains nothing from.
             */
            new SettingDefinition(
                key: 'monitoring.control_indicator',
                label: 'Show a notice on a machine while it is being remotely controlled',
                type: SettingDefinition::TYPE_BOOL, default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'When on, a monitored Windows machine shows a system-tray notice while '
                    .'someone is remotely controlling it. Watching a live view stays silent — '
                    .'only taking control shows this. Off by default, and fully silent when off.',
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
                key: 'organization.structure_canvas',
                label: 'Structure arrangement',
                type: SettingDefinition::TYPE_JSON,
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
                notYetInEffect: 'EzBizi will use this once it sends invoices.',
            ),
            new SettingDefinition(
                key: 'money.tax_label', label: 'Tax label',
                type: SettingDefinition::TYPE_ENUM, default: 'Sales Tax',
                options: ['Sales Tax', 'VAT', 'GST', 'HST', 'PST'],
                help: 'Shown on invoices.',
                notYetInEffect: 'EzBizi will use this once it sends invoices.',
            ),
            new SettingDefinition(
                key: 'money.tax_inclusive_pricing', label: 'Prices include tax',
                type: SettingDefinition::TYPE_BOOL, default: false,
                notYetInEffect: 'EzBizi will use this once it sends invoices.',
            ),
            new SettingDefinition(
                key: 'money.payment_terms_days', label: 'Payment terms',
                type: SettingDefinition::TYPE_ENUM, default: 30,
                options: [0, 7, 14, 15, 30, 45, 60, 90],
                optionLabels: [
                    '0' => 'Due on receipt',
                    '7' => 'Net 7',
                    '14' => 'Net 14',
                    '15' => 'Net 15',
                    '30' => 'Net 30',
                    '45' => 'Net 45',
                    '60' => 'Net 60',
                    '90' => 'Net 90',
                ],
                help: 'When an invoice is due, counted from the day it is issued.',
            ),
            new SettingDefinition(
                key: 'invoicing.email_mode', label: 'How invoices are emailed',
                type: SettingDefinition::TYPE_ENUM, default: 'per_branch',
                options: ['per_branch', 'summary'],
                optionLabels: [
                    'per_branch' => 'One email per invoice, to whoever it bills',
                    'summary' => 'One summary email with every invoice attached',
                ],
                editPermission: 'invoices.edit',
                help: 'The email body is the summary table either way.',
            ),
            new SettingDefinition(
                key: 'invoicing.summary_to', label: 'Summary email goes to',
                type: SettingDefinition::TYPE_STRING, default: '',
                editPermission: 'invoices.edit',
                help: 'Addresses for the summary email, separated by commas. Used when invoices go as one summary.',
            ),
            new SettingDefinition(
                key: 'invoicing.channels', label: 'Invoices are sent by',
                type: SettingDefinition::TYPE_JSON, default: ['email'],
                editPermission: 'invoices.edit',
                help: 'Any of email, sms and whatsapp. Each goes through what this business connected: its mailbox, a number of its own that can text, its WhatsApp.',
            ),
            new SettingDefinition(
                key: 'invoicing.mailbox_id', label: 'Mailbox invoices are emailed from',
                type: SettingDefinition::TYPE_STRING, default: '',
                editPermission: 'invoices.edit',
                help: 'Empty: the company\'s connected mailbox, else the organization\'s, else its own mail server.',
            ),
            new SettingDefinition(
                key: 'invoicing.sms_from', label: 'Number invoices are texted from',
                type: SettingDefinition::TYPE_STRING, default: '',
                editPermission: 'invoices.edit',
                help: 'One of this business\'s own numbers that can send texts.',
            ),
            new SettingDefinition(
                key: 'invoicing.whatsapp_from', label: 'WhatsApp number invoices go from',
                type: SettingDefinition::TYPE_STRING, default: '',
                editPermission: 'invoices.edit',
                help: 'Needed only when the business has more than one WhatsApp number.',
            ),
            new SettingDefinition(
                key: 'invoicing.whatsapp_template', label: 'WhatsApp template for invoices',
                type: SettingDefinition::TYPE_STRING, default: '',
                editPermission: 'invoices.edit',
                help: 'An approved template taking four values: the business name, the invoice number, the total and a link to the PDF.',
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
            ),
            new SettingDefinition(
                key: 'numbering.estimate_prefix', label: 'Estimate number prefix',
                type: SettingDefinition::TYPE_STRING, default: 'EST-',
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
                    '0' => 'Never',
                    '30' => '30 days',
                    '60' => '60 days',
                    '90' => '90 days',
                    '180' => '6 months',
                    '365' => '1 year',
                ],
                allowedScopes: $orgOnly,
                help: 'Never is the default. Forced rotation encourages weaker passwords — NIST advises against it.',
                notYetInEffect: 'Nobody is asked to change their password on a schedule yet.',
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
                type: SettingDefinition::TYPE_BOOL, default: false,
                /*
                 * OFF BY DEFAULT, by Jose's ruling, 2026-09-28, verbatim: "the
                 * system is fine, but why is it enabled, it should be disabled
                 * by default, so yes, stop it asap. emails should only send if
                 * the toggle for send an email is activated as well", then
                 * "in automations".
                 *
                 * It shipped ON, and was invisible because every mail died in
                 * the queue worker until abf44c7c. The day that fix went live,
                 * every "A new lead from local_services has arrived" emailed
                 * nine people, about 370 an hour. The bell is the notification;
                 * mail is something an organization chooses. Production was set
                 * false per organization by the deploy desk before this landed.
                 *
                 * And an AUTOMATION's notification is never emailed, whatever
                 * this says — Jose, minutes later: "the toggle should not exist
                 * at all, there is an automation for sending an email". Email
                 * from a rule is only the "Send an email" step. That rule lives
                 * in NotificationDispatcher::mayEmail(), so this key now governs
                 * mail for the other sources (system, hr) only.
                 *
                 * The "unless" clause is not hedging: above the ceiling
                 * NOTHING is emailed, not a subset.
                 *
                 * Notifications added an email ceiling in 27a40807 that
                 * refuses rather than truncates -- mailing an arbitrary subset
                 * chosen by collection order makes "why did Bob get this and I
                 * did not" a question with no answer, and the bell has already
                 * told everybody. Correct, and it made the sentence I wrote
                 * one commit earlier false for exactly the administrators most
                 * likely to notice: the ones with a wide automation.
                 *
                 * THE NUMBER IS DELIBERATELY NOT REPEATED HERE. Their constant
                 * is private, so a "50" typed into this string could not be
                 * derived from it and would go stale the first time they tune
                 * it -- the same way the sentence above this one went stale.
                 * A phrasing with no number cannot disagree with the code. If
                 * naming it turns out to matter to an administrator, the fix is
                 * for that constant to become public and be interpolated, not
                 * for a digit to be copied across a module boundary.
                 */
                help: 'Off unless an administrator turns it on. '
                    .'With this on, a notification is also emailed to each person it was for, '
                    .'in the same words the app shows -- unless it was for more people than '
                    .'EzBizi will email at once, and then it stays in the app only. Off leaves '
                    .'the bell working. The two channels are independent: neither one switched '
                    .'off silences the other.',
            ),
            new SettingDefinition(
                key: 'notifications.push_enabled', label: 'Send push notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
                /*
                 * The notYetInEffect sentence came off when push became real.
                 * NotificationDispatcher reads this key in two places now, so
                 * leaving "takes effect when there is a mobile app to push to"
                 * would be the setting telling somebody it does nothing while
                 * it decides whether their phone buzzes. Two guards caught it
                 * in the same run, from both directions.
                 */
            ),
            new SettingDefinition(
                key: 'notifications.in_app_enabled', label: 'Show in-app notifications',
                type: SettingDefinition::TYPE_BOOL, default: true,
                /*
                 * It called itself "the master switch. Off silences every
                 * notification below, including the ones a company cannot
                 * otherwise turn off." That was true and complete about a
                 * system with ONE channel, and it stopped being true at
                 * 94ab82a2, when the notifications module split the three
                 * keys apart: the source toggles decide whether a
                 * notification happens at all, this one writes the row, and
                 * `notifications.email_enabled` sends the mail.
                 *
                 * Retracted by the session that wrote it, on the argument
                 * that settles it: the key is NAMED `in_app_enabled`, and
                 * somebody who unticks "Show in-app notifications" and
                 * silently stops receiving MAIL has been lied to by the name.
                 *
                 * Nothing was lost by the split, including the case the old
                 * sentence was protecting. `system` has no source toggle
                 * deliberately -- it carries "your trial ends tomorrow" -- and
                 * this was its only off switch; now its row is silenced here
                 * and its mail by the email key, independently. The
                 * cross-channel master that actually does the job is the
                 * source map, which is channel-agnostic by construction.
                 *
                 * Nothing mechanical catches a help sentence going false --
                 * the gate in a34bf5f4 watches `notYetInEffect`, not `help`.
                 * This one was caught by the module that owns the behaviour
                 * reading its own retraction back against my file.
                 */
                help: 'The bell inside the app. Off leaves email working, if this organization '
                    .'sends notifications by email. To stop a kind of notification reaching '
                    .'anybody at all, use the switches below.',
            ),

            /*
             * Per-SOURCE, which is the axis a company actually complains along.
             *
             * Nobody asks to be told less in general; they ask to stop hearing
             * about one KIND of thing — "the automation alerts are noisy, HR's
             * are not". `source` is already the column that separates those, so
             * it is what these key on rather than a second taxonomy invented to
             * sit beside it.
             *
             * Finer control than this already exists and is owned elsewhere: a
             * single noisy automation is switched off by its own `is_active`
             * (Automation::86). A per-rule toggle here would be a third place to
             * look when somebody asks why they stopped hearing about something.
             *
             * There is deliberately no `system` toggle. That source carries
             * "your trial ends tomorrow" and the things a business is worse off
             * for having silenced — the master switch above can still do it, but
             * it cannot be done by accident while tidying up noise from one
             * source.
             */
            new SettingDefinition(
                key: 'notifications.automation_enabled', label: 'Notifications from automations',
                type: SettingDefinition::TYPE_BOOL, default: true,
                help: 'The notify action on automation rules. Off leaves the rules running '
                    .'and their other actions working — it silences only the telling.',
            ),
            new SettingDefinition(
                key: 'notifications.hr_enabled', label: 'Notifications from HR',
                type: SettingDefinition::TYPE_BOOL, default: true,
                help: 'Contract amendments and onboarding. These are addressed to one named '
                    .'person rather than broadcast, so they are quiet by nature.',
            ),

            new SettingDefinition(
                key: 'notifications.quiet_hours', label: 'Quiet hours',
                type: SettingDefinition::TYPE_TIME_RANGE,
                default: ['start' => null, 'end' => null],
                help: 'Suppress non-urgent notifications. Null disables.',
                // Sharpened on 2026-09-14, the day notifications started going
                // out by email. The old sentence -- "every one is raised the
                // moment it happens today" -- was true and stayed true, and
                // what changed underneath it was the COST of that being true.
                // A notification raised at 3am was a silent row nobody saw
                // until morning; the same notification now rings a phone.
                // Nothing here is read by anything yet, so the claim is honest;
                // it just has to say what it is now failing to hold back.
                notYetInEffect: 'Notifications are still sent the moment they happen, even during these hours.',
            ),
            new SettingDefinition(
                key: 'notifications.reply_to_email', label: 'Reply-to address',
                type: SettingDefinition::TYPE_STRING, default: '',
                // It said "Blank uses the franchise contact address", and a
                // franchise has no contact address -- name, timezone, currency
                // and the two ids, and nothing else. The sentence described a
                // column that has never existed, which nobody could find out
                // while nothing sent mail. Blank now says what actually
                // happens.
                help: 'Where replies to a notification email should go. Blank sends them back to '
                    .'the address EzBizi sends from.',
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
                    'shared' => 'The whole team',
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
                    'phone_or_email' => 'Phone or email',
                    'phone' => 'Phone',
                    'email' => 'Email',
                    'name_and_address' => 'Name and address',
                    'off' => 'Do not detect',
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

            /*
             * Which of the statuses above mean the money is earned (spec §8.2).
             *
             * Used to be a per-user dashboard preference (`user.preferences`,
             * `dashboard.glances[].options.earned_statuses`) — a personal opinion
             * about a company fact, so two viewers could disagree about whether the
             * same job was revenue. Promoting it here also gains an audit trail the
             * preference never had: a write to this setting is logged as
             * `settings.updated` (SettingsController::update()), while the
             * dashboard-preference write it replaces was never audited at all.
             *
             * Organization and company: a company may sell differently from its
             * parent. NOT franchise — one branch cannot decide what the whole
             * business calls revenue.
             */
            /*
             * Which statuses mean "waiting on the customer's decision", for
             * `job.estimate_aging` (Jose's Project Management rule 2,
             * 2026-10-21). Empty by default: nothing ages until somebody says
             * which of the organization's own words mean waiting.
             */
            new SettingDefinition(
                key: 'jobs.estimate_aging_statuses', label: 'Statuses that wait on an estimate',
                type: SettingDefinition::TYPE_SUBSET,
                choicesFrom: 'jobs.statuses',
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Jobs in these statuses are checked once a day: how long since their latest '
                    .'estimate, and what has been paid. Automations can act on it, for example '
                    .'cancelling after 30 days with nothing paid.',
            ),
            /*
             * Which statuses mean cancelled, for Project Management's
             * "Cancellations" (2026-10-22). Empty: none counted, nothing guessed.
             */
            new SettingDefinition(
                key: 'jobs.cancelled_statuses', label: 'Statuses that mean the job was cancelled',
                type: SettingDefinition::TYPE_SUBSET,
                choicesFrom: 'jobs.statuses',
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'Project Management counts a job as cancelled when it moves into one of these.',
            ),
            new SettingDefinition(
                key: 'jobs.earned_statuses', label: 'Statuses that mean the money is earned',
                type: SettingDefinition::TYPE_SUBSET,
                choicesFrom: 'jobs.statuses',
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                help: 'Which of your job statuses mean the work is done and the money counts. '
                    .'Revenue is reported against the moment a job first reaches one of these, '
                    .'so a job booked in June and finished in September counts in September.',
            ),

            /*
             * Which of YOUR job statuses mean what to Thumbtack.
             *
             * Thumbtack measures a Pro on what happens to work that came
             * through them, and its six statuses are fixed. EzBizi's are not:
             * every organization writes its own vocabulary in
             * `jobs.statuses`, so one company's "Done" is another's "Closed
             * out". There is no table that could map those without guessing,
             * and a guess here is not a display bug — it is a false claim
             * about a job, made to somebody who reports on it.
             *
             * So the tenant says. Empty by default, which means EzBizi tells
             * Thumbtack NOTHING until somebody fills it in — the same shape as
             * `jobs.earned_statuses`, and for the same reason: silence is
             * honest, a guess is not.
             */
            new SettingDefinition(
                key: 'jobs.thumbtack_status_map', label: 'What your job statuses mean to Thumbtack',
                type: SettingDefinition::TYPE_JSON,
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                help: 'For jobs that began as a Thumbtack enquiry, EzBizi can tell Thumbtack what '
                    .'happened to them. Map each of your own job statuses to one of these: '
                    .'not_scheduled, appt_scheduled, job_complete, invoice_paid, customer_cancel or '
                    .'pro_cancel. Anything you leave out is simply not reported.',
            ),
        ];
    }

    /**
     * The staff assistant, on WhatsApp and in the app. Organization-wide
     * because the WhatsApp line it answers on is: one number, one menu.
     *
     * No length limits in the type, so the assistant cuts each value to what
     * WhatsApp accepts when it sends - a header over 60 characters would
     * otherwise make Meta refuse the whole menu, which reads as no reply.
     */
    private function assistant(): array
    {
        $orgOnly = [SettingDefinition::SCOPE_ORGANIZATION];

        return [
            new SettingDefinition(
                key: 'ai_assistance.name',
                label: 'Assistant name',
                type: SettingDefinition::TYPE_STRING,
                default: 'Assistant',
                allowedScopes: $orgOnly,
                editPermission: 'ai_assistance.manage',
                help: 'The heading of the menu, on WhatsApp and in the app. Up to 60 characters.',
            ),
            new SettingDefinition(
                key: 'ai_assistance.welcome',
                label: 'Welcome message',
                type: SettingDefinition::TYPE_STRING,
                default: 'Choose an option below.',
                allowedScopes: $orgOnly,
                editPermission: 'ai_assistance.manage',
                help: 'What the assistant says above its menu.',
            ),
            new SettingDefinition(
                key: 'ai_assistance.location_group',
                label: 'Locations to search',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                allowedScopes: $orgOnly,
                editPermission: 'ai_assistance.manage',
                help: 'Find a location searches the sites in Web Management whose Group is this. '
                    .'Empty searches every site.',
            ),
            new SettingDefinition(
                key: 'ai_assistance.ask_new_contacts',
                label: 'Ask new contacts what they need',
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                allowedScopes: $orgOnly,
                editPermission: 'ai_assistance.manage',
                help: 'A new WhatsApp contact is asked "I need a service" or "I want to work with you": '
                    .'a service request becomes a lead, a job request goes to HR. Off, they get the menu.',
            ),
            new SettingDefinition(
                key: 'ai_assistance.report_corrections',
                label: 'Correct report drafts by WhatsApp',
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                allowedScopes: $orgOnly,
                editPermission: 'ai_assistance.manage',
                help: 'Somebody with "Correct reports" can reply to a report draft in their own words, such as '
                    .'"leave out job 4512". AI reads it, the bot repeats the change back, and nothing changes until they answer YES.',
            ),
        ];
    }

    /**
     * Field (docs/superpowers/specs/2026-09-25-field-module-design.md).
     *
     * Every level may set these: a franchise that runs night calls tracks
     * differently from the office company above it. They are resolved at the
     * TRACKED person's place, the way Clock in resolves their schedule.
     */
    private function phone(): array
    {
        return [
            // THE COMPANY'S CALL, not each person's. Jose, 2026-09-25: *"the
            // company should decide on the default toggle or not, not the
            // user"*. It was a per-user preference before that. On /me via
            // DisplaySettings, because the shell draws the window from it.
            new SettingDefinition(
                key: 'phone.incoming_popup',
                label: 'Pop-up for incoming calls',
                type: SettingDefinition::TYPE_BOOL,
                default: true,
                editPermission: 'phone.edit',
                help: 'A card with the caller and Accept or Ignore, which can be dragged out of '
                    .'the way. The strip at the top of the screen appears either way.',
            ),
        ];
    }

    /**
     * Suggested availability (Jose, 2026-10-13: "the mile radius should be
     * changed in settings as usual"). Enums, because TYPE_INT has no bounds and
     * a 0-mile radius suggests nothing ever. Franchise-level allowed: a dense
     * city and a spread-out region want different radii. Read by
     * App\Support\Scheduling\NearbySuggestion.
     */
    private function scheduling(): array
    {
        $miles = [1, 2, 3, 5, 10, 15, 20, 25, 30];
        $days = [7, 10, 14, 21, 30];
        $enum = fn (string $key, string $label, int $default, array $options, \Closure $name, string $help) => new SettingDefinition(
            key: $key, label: $label, type: SettingDefinition::TYPE_ENUM, default: $default, options: $options,
            optionLabels: array_combine(array_map('strval', $options), array_map($name, $options)), help: $help,
        );

        return [
            new SettingDefinition(
                key: 'scheduling.suggest_radius_miles', label: 'Suggested availability: nearby radius',
                type: SettingDefinition::TYPE_ENUM, default: 30,
                options: $miles,
                optionLabels: array_combine(array_map('strval', $miles), array_map(fn ($m) => $m === 1 ? '1 mile' : "{$m} miles", $miles)),
                help: 'How close a booked job must be, in a straight line, for Scheduling › Availability '
                    .'to suggest the same day so a technician does both.',
            ),
            new SettingDefinition(
                key: 'scheduling.suggest_days', label: 'Suggested availability: look ahead',
                type: SettingDefinition::TYPE_ENUM, default: 14,
                options: $days,
                optionLabels: array_combine(array_map('strval', $days), array_map(fn ($d) => "{$d} days", $days)),
                help: 'How many days from tomorrow a suggestion may look for a day with nearby work.',
            ),
            // Jose, 2026-10-14: "i want to be able to change all the settings
            // in the app, like amount of jobs a technician can get for
            // routing, etc". Every number Routing, Re-Scheduling and the
            // suggestions decide with is one of these; enums, so none can be
            // set to a value that breaks them.
            $enum('scheduling.suggest_window_gap', 'Suggestions: which windows count as nearby', 1, [0, 1, 2],
                fn ($v) => [0 => 'The same window only', 1 => 'The same window or the one beside it', 2 => 'Up to two windows away'][$v],
                'A booked job counts as "nearby" only when it is in the offered time window, or this close to it. '
                .'Used by the Availability suggestion and by Re-Scheduling.'),
            $enum('scheduling.route_jobs_per_tech', 'Routing: jobs per technician per day', 6, [3, 4, 5, 6, 7, 8, 9, 10],
                fn ($v) => "{$v} jobs",
                'The most jobs one technician takes in a day. Routing counts technicians as the capacity of the day '
                .'divided by this.'),
            $enum('scheduling.route_jobs_per_window', 'Routing: jobs per technician per time window', 2, [1, 2, 3, 4],
                fn ($v) => $v === 1 ? '1 job' : "{$v} jobs",
                'How many jobs one technician takes in the same time window (for example 2 in the morning).'),
            $enum('scheduling.route_pair_miles', 'Routing: two jobs in one window, at most this far apart', 10, [2, 5, 10, 15, 20, 30],
                fn ($v) => "{$v} miles",
                'A technician gets a second job in the same window only when it is this close to the first.'),
            $enum('scheduling.route_new_miles', 'Routing: start another technician route beyond', 15, [5, 10, 15, 20, 30],
                fn ($v) => "{$v} miles",
                'A job farther than this from every route so far goes to the next technician.'),
            $enum('scheduling.route_far_miles', 'Routing: outside the area beyond', 60, [30, 45, 60, 90, 120],
                fn ($v) => "{$v} miles",
                'A job this far from the other jobs that day is listed as far outside the area (usually booked in the '
                .'wrong one) instead of being put on a route.'),
            $enum('scheduling.reschedule_settled_days', 'Re-Scheduling: never move jobs in the next', 2, [0, 1, 2, 3, 5, 7],
                fn ($v) => $v === 0 ? 'No limit' : ($v === 1 ? '1 day' : "{$v} days"),
                'Jobs booked this soon are left where they are: the customer has planned for them.'),
            $enum('scheduling.reschedule_max_shift_days', 'Re-Scheduling: move a job at most', 3, [1, 2, 3, 5, 7, 14],
                fn ($v) => $v === 1 ? '1 day earlier or later' : "{$v} days earlier or later",
                'How far from its booked date a job may be suggested to move.'),
            $enum('scheduling.reschedule_max_after_miles', 'Re-Scheduling: nearest job after the move within', 10, [2, 5, 10, 15, 20, 30],
                fn ($v) => "{$v} miles",
                'A move is suggested only when it puts the job this close to other booked work.'),
        ];
    }

    private function leads(): array
    {
        return [
            /*
             * Canned replies beside Send on a lead (Jose, 2026-09-30: "tiara
             * should have first replied with a Thank you for contacting
             * (company name)"). A list of name + text: the name is the chip,
             * the text goes into the reply box to be read before sending -
             * never sent by itself. Per company, because each brand signs
             * with its own name.
             */
            new SettingDefinition(
                key: 'leads.reply_templates',
                label: 'Reply templates',
                type: SettingDefinition::TYPE_LIST,
                default: [[
                    'label' => 'Thank you',
                    'value' => '{{greeting}}, thank you for contacting {{company}}! A dispatcher will '
                        .'follow up with you shortly. Could you share the service address and a day '
                        .'that works best for you?',
                ]],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                editPermission: 'leads.edit',
                help: 'Shown beside Send on a lead. The name is the button; the text is put in the '
                    .'reply box to check before sending. {{greeting}} becomes "Hi Mark" (or "Hi '
                    .'there"), {{company}} the company name, {{missing}} what we still need to book (full '
                    .'name, full service address, email - only the ones not given yet), {{availability}} '
                    .'the first open day and windows for the ZIP the customer gave.',
            ),
            /*
             * When somebody is there to answer leads - separate from
             * ops.business_hours, which Clock In enforces (Jose, 2026-10-05:
             * the closed-office auto replies). Read by LeadOfficeHours.
             */
            new SettingDefinition(
                key: 'leads.office_hours',
                label: 'Office hours',
                type: SettingDefinition::TYPE_HOURS,
                default: [
                    'monday' => ['open' => '08:00', 'close' => '17:00'],
                    'tuesday' => ['open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['open' => '08:00', 'close' => '17:00'],
                    'thursday' => ['open' => '08:00', 'close' => '17:00'],
                    'friday' => ['open' => '08:00', 'close' => '17:00'],
                    'saturday' => null,
                    'sunday' => null,
                ],
                editPermission: 'leads.edit',
                help: 'When the office answers leads. Outside these hours the closed-office auto '
                    .'replies say when you open again ({{office_opens}}).',
            ),
            new SettingDefinition(
                key: 'leads.photos_email',
                label: 'Email for customer photos',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                editPermission: 'leads.edit',
                help: 'Where a customer who mentions pictures or documents is asked to email them, '
                    .'e.g. office@ny.a-1chimney.com. Set per branch. Empty: they are not asked.',
            ),
            // Jose, 2026-10-13: send the booking link when the office is
            // closed, and the number to call to reschedule once approved.
            new SettingDefinition(
                key: 'leads.booking_url',
                label: 'Online booking page',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                editPermission: 'leads.edit',
                help: 'The page with the booking widget, e.g. https://www.a-1chimney.com/booking/. '
                    .'{{booking_link}} in an auto reply is this page with a link that files the '
                    .'booking on the same lead. Empty: no link.',
            ),
            new SettingDefinition(
                key: 'leads.office_phone',
                label: 'Office phone for customers',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                editPermission: 'leads.edit',
                help: 'The number a customer calls to reschedule, {{office_phone}} in a message. Set per branch.',
            ),
            // Phone calls into Leads (RC Calls, EZ Calls), set per business
            // rather than written into the code (Jose, 2026-10-16: "it all
            // needs to be stablished as a public product would").
            new SettingDefinition(
                key: 'leads.calls_company',
                label: 'Company for calls to your lines',
                type: SettingDefinition::TYPE_COMPANY,
                default: null,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                editPermission: 'leads.manage',
                readPermission: 'leads.manage',
                help: 'The company a call to one of your phone lines is filed under when the line '
                    .'is not named after a company. Empty: your only company, or the one the phone '
                    .'connection belongs to.',
            ),
            new SettingDefinition(
                key: 'leads.line_markets',
                label: 'Phone lines by market',
                type: SettingDefinition::TYPE_FRANCHISE_MAP,
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                editPermission: 'leads.manage',
                readPermission: 'leads.manage',
                help: 'The code your line names start with, and the branch its calls go to: NJ for a '
                    .'line called "NJ - GMB". A line whose code is not here stays at company level.',
            ),
            new SettingDefinition(
                key: 'leads.new_client_queue',
                label: 'Phone queue for new customers',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                editPermission: 'leads.manage',
                readPermission: 'leads.manage',
                help: 'The queue a caller reaches by choosing "new customer" in your phone menu. A '
                    .'caller who pressed it but has had a job with you before is counted as existing.',
            ),
            new SettingDefinition(
                key: 'leads.existing_client_queue',
                label: 'Phone queue for existing customers',
                type: SettingDefinition::TYPE_STRING,
                default: '',
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                editPermission: 'leads.manage',
                readPermission: 'leads.manage',
                help: 'The queue a caller reaches by choosing "existing customer" in your phone menu. '
                    .'Their calls are tagged Existing client. Empty: none are.',
            ),
            // A1 Leads takeover, 2026-10-16: one switch that stops every call
            // to Google for a business (GoogleToken refuses the token).
            new SettingDefinition(
                key: 'leads.google_paused',
                label: 'Pause all Google calls',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                editPermission: 'leads.manage',
                readPermission: 'leads.manage',
                help: 'On: EzBizi stops talking to Google for this business - Local Services leads, '
                    .'Ads, Business Profile and reviews - until it is switched off. Nothing is '
                    .'disconnected; syncs say they are paused.',
            ),
        ];
    }

    private function field(): array
    {
        return [
            new SettingDefinition(
                key: 'field.tracking_mode',
                label: 'When phones report their location',
                type: SettingDefinition::TYPE_ENUM,
                default: 'clocked_in',
                options: ['clocked_in', 'scheduled', 'always'],
                optionLabels: [
                    'clocked_in' => 'While clocked in',
                    'scheduled' => 'During their scheduled work hours',
                    'always' => 'Always',
                ],
                editPermission: 'field.manage',
                help: 'Applies to people whose role includes Field › Tracked. Their phone shows a '
                    .'notification whenever it is sharing. Points sent outside this window are '
                    .'refused, whatever the phone does.',
            ),
            new SettingDefinition(
                key: 'field.arrival_radius_m',
                // Both units in every label (UI review, 2026-10-08: "metres"
                // under Imperial units). Stored in metres, as the maths is.
                label: 'Arrived within',
                type: SettingDefinition::TYPE_ENUM,
                default: 150,
                options: [50, 100, 150, 250, 500],
                optionLabels: [
                    '50' => '160 ft (50 m)',
                    '100' => '330 ft (100 m)',
                    '150' => '490 ft (150 m)',
                    '250' => '820 ft (250 m)',
                    '500' => '1,640 ft (500 m)',
                ],
                editPermission: 'field.manage',
                help: 'How close to a job’s address counts as arrived. Leaving is one and a half times this.',
            ),
            new SettingDefinition(
                key: 'field.stopped_minutes',
                label: 'Stopped too long after, minutes',
                type: SettingDefinition::TYPE_ENUM,
                default: 20,
                options: [10, 15, 20, 30, 45, 60],
                editPermission: 'field.manage',
                help: 'Somebody standing still this long away from any of their jobs raises an alert.',
            ),
            new SettingDefinition(
                key: 'field.lost_signal_minutes',
                label: 'Lost signal after, minutes',
                type: SettingDefinition::TYPE_ENUM,
                default: 15,
                options: [5, 10, 15, 30, 60],
                editPermission: 'field.manage',
                help: 'A phone that should be reporting and has been silent this long raises an alert.',
            ),
            // Jose, 2026-10-19: "On Field settings i should be able to select
            // which roles show up on the map, this is separate from what roles
            // are being tracked which belongs on roles y permissions."
            new SettingDefinition(
                key: 'field.map_roles',
                label: 'Roles shown on the map',
                type: SettingDefinition::TYPE_ROLES,
                default: [],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY,
                    SettingDefinition::SCOPE_FRANCHISE],
                editPermission: 'field.manage',
                help: 'Who appears on Field › Map. Leave empty to show everybody tracked. This changes what is '
                    .'shown, not who is tracked: that is Roles & permissions.',
            ),
            // Closest office (A1's Stats routing panel, 2026-10-19): when Google
            // cannot route, the straight line is stretched and timed with these.
            new SettingDefinition(
                key: 'field.estimate_mph',
                min: 5,
                label: 'Average driving speed for estimates, mph',
                type: SettingDefinition::TYPE_INT,
                default: 35,
                editPermission: 'field.manage',
                help: 'Used only when a drive time cannot be fetched.',
            ),
            new SettingDefinition(
                key: 'field.estimate_road_factor',
                min: 1.0,
                label: 'Roads are longer than a straight line by',
                type: SettingDefinition::TYPE_DECIMAL,
                default: 1.3,
                editPermission: 'field.manage',
                help: 'A straight-line distance is multiplied by this to estimate the drive.',
            ),
            new SettingDefinition(
                key: 'field.similar_within_miles',
                min: 0,
                label: 'Offices this close to the nearest count as just as close, miles',
                type: SettingDefinition::TYPE_INT,
                default: 5,
                editPermission: 'field.manage',
            ),
            // Safety recordings capture the customer's voice, so they are off
            // until a company turns them on, and states where every party must
            // consent either block them or require announcing them (desk,
            // 2026-10-16, pending Jose's ruling on the defaults).
            new SettingDefinition(
                key: 'field.recording_enabled',
                label: 'Technicians may record job visits',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                editPermission: 'field.manage',
                help: 'Off by default. When on, people whose role may record can record a visit for their '
                    .'safety. Check the recording laws where you work before turning it on.',
            ),
            new SettingDefinition(
                key: 'field.recording_notice_states',
                label: 'States where every party must consent',
                type: SettingDefinition::TYPE_STRING,
                default: 'CA, CT, DE, FL, IL, MD, MA, MI, MT, NV, NH, OR, PA, VT, WA',
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                editPermission: 'field.manage',
                help: 'Two-letter state codes, separated by commas. Recording on a job in one of these states '
                    .'follows the rule below.',
            ),
            new SettingDefinition(
                key: 'field.recording_all_party_rule',
                label: 'In those states',
                type: SettingDefinition::TYPE_ENUM,
                default: 'block',
                options: ['block', 'announce'],
                optionLabels: [
                    'block' => 'Do not allow recording',
                    'announce' => 'Allow it once the technician confirms they told the customer',
                ],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                editPermission: 'field.manage',
            ),
            new SettingDefinition(
                key: 'field.recording_notice_text',
                label: 'What the technician tells the customer',
                type: SettingDefinition::TYPE_STRING,
                default: 'This visit may be recorded for safety and quality.',
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY],
                editPermission: 'field.manage',
            ),
        ];
    }

    private function humanResources(): array
    {
        return [
            // Jose, 2026-10-05: "a final approval is pending from the employer
            // who sent it out ... this also should be toggable".
            new SettingDefinition(
                key: 'hr.onboarding_approval',
                label: 'Approve onboardings before access',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
                help: 'When on, an onboarding signed by both sides waits for the final approval of '
                    .'whoever sent it, or an Administrator. Until then the new starter sees only '
                    .'their paperwork.',
            ),
        ];
    }

    private function hr(): array
    {
        $orgOnly = [SettingDefinition::SCOPE_ORGANIZATION];

        return [
            new SettingDefinition(
                key: 'time_clock.enabled',
                label: 'Clock in',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, people whose role includes Clock in see a button in the top bar. Human Resources shows the day list.',
            ),
            new SettingDefinition(
                key: 'time_clock.lock_screen',
                label: 'Lock screen',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, people whose role includes Lock screen see only a Clock In screen '
                    .'while they are not clocked in on a working day. Mark somebody exempt on '
                    .'Human Resources › Records.',
            ),
            // Enums rather than free ints: TYPE_INT has no bounds, and a
            // 0-minute warning or a 9-hour extension is a support ticket.
            new SettingDefinition(
                key: 'time_clock.warning_minutes',
                label: 'Minutes of warning before a shift ends',
                type: SettingDefinition::TYPE_ENUM,
                default: 5,
                options: [1, 2, 3, 5, 10, 15],
                allowedScopes: $orgOnly,
                help: 'How long before the scheduled end the reminder appears.',
            ),
            new SettingDefinition(
                key: 'time_clock.extension_minutes',
                label: 'Extension length, minutes',
                type: SettingDefinition::TYPE_ENUM,
                default: 30,
                options: [5, 10, 15, 30, 45, 60, 90, 120],
                allowedScopes: $orgOnly,
                help: 'How much each Extend adds to the scheduled end. There is no limit on how many times.',
            ),
            // Work Hours › Dashboard (Jose, 2026-10-01): how late a first
            // clock-in may be and still count as on time, and how early a
            // clock-out may be and not count as leaving early.
            new SettingDefinition(
                key: 'time_clock.grace_minutes',
                label: 'Grace period, minutes',
                type: SettingDefinition::TYPE_ENUM,
                default: 5,
                options: [0, 1, 2, 3, 5, 10, 15],
                allowedScopes: $orgOnly,
                help: 'On the Dashboard, a clock-in this many minutes after the start still counts as on time, and a clock-out this many minutes before the end is not leaving early.',
            ),
            /*
             * The backstop, and it exists because the schedule was not one.
             *
             * Found on 2026-09-23 from Jose's screenshot: Adeline was closed by
             * the sweep at her 5pm end and clocked back in eight seconds later.
             * A shift that STARTS at or after the scheduled end has no end to
             * count down to, so `scheduled_end_at` was null — and the sweep
             * reads `whereNotNull`, so that row was invisible to it for ever.
             * Five people across four days were still clocked in, one of them
             * for 40 hours. Nothing was broken; nothing was ever going to close
             * them.
             *
             * So every shift gets an end now, and this is the one used when the
             * schedule does not supply one: a day off, a late start, a person
             * with no schedule at all. It is a CAP rather than a shift length —
             * nobody should hit it, and hitting it means the punch was never
             * closed by a person.
             */
            /*
             * The clock-in zone (A1 Tools takeover, 2026-10-16): somebody
             * whose role cannot "Clock in from anywhere" must be within this
             * many metres of their place to clock in or out by hand. Set on
             * the franchise (or higher, and it cascades). Off, or no centre,
             * and nothing is checked.
             */
            // Daily approval (Shoval via Jose, 2026-10-22): off until a place
            // asks for it. Who approves is whoever holds "Approve daily hours".
            new SettingDefinition(
                key: 'time_clock.approval_enabled',
                label: 'Daily approval of hours',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                help: 'When on, people whose role can "Approve daily hours" get yesterday\'s clock-ins each morning to approve or adjust.',
            ),
            new SettingDefinition(
                key: 'time_clock.approval_time',
                label: 'Daily approval reminder time',
                type: SettingDefinition::TYPE_ENUM,
                default: '08:00',
                options: ['06:00', '07:00', '08:00', '09:00', '10:00', '12:00'],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                help: 'When the approver is told yesterday\'s hours are waiting, in the place\'s own time.',
            ),
            new SettingDefinition(
                key: 'time_clock.geofence_enabled',
                label: 'Clock-in zone',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                help: 'When on, people whose role cannot "Clock in from anywhere" must be near this place to clock in or out. Roles grant it in Settings › Roles.',
            ),
            new SettingDefinition(
                key: 'time_clock.geofence_center',
                label: 'Clock-in zone centre',
                type: SettingDefinition::TYPE_LOCATION,
                default: '',
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                help: 'Latitude and longitude of the place, as Google Maps shows them: right-click the spot, then click the numbers to copy them (for example 41.0534, -73.5387).',
            ),
            new SettingDefinition(
                key: 'time_clock.geofence_radius_m',
                label: 'Clock-in zone size, metres',
                type: SettingDefinition::TYPE_ENUM,
                default: 100,
                options: [50, 100, 150, 200, 300, 500, 1000, 2000, 5000],
                allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                help: 'How far from the centre still counts as at the place. The phone’s own accuracy is allowed on top.',
            ),
            new SettingDefinition(
                key: 'time_clock.max_hours',
                label: 'Longest a shift may run, hours',
                type: SettingDefinition::TYPE_ENUM,
                default: 16,
                options: [8, 10, 12, 14, 16, 18, 20, 24],
                allowedScopes: $orgOnly,
                help: 'A shift that nobody clocks out of is closed automatically after this long. '
                    .'It applies when the schedule gives no end — a day off, or clocking in after '
                    .'the day is over. Correct the times on Human Resources > Work Hours.',
            ),
            new SettingDefinition(
                key: 'time_clock.week_starts_on',
                label: 'Week starts on',
                type: SettingDefinition::TYPE_ENUM,
                default: 'sunday',
                options: ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                optionLabels: [
                    'sunday' => 'Sunday',
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday',
                    'saturday' => 'Saturday',
                ],
                allowedScopes: $orgOnly,
                // All seven rather than the two that cover most people: a
                // payroll week starting on a Thursday is somebody's real one,
                // and an enum costs the same either way.
                help: 'Which day Work Hours counts a week from. It decides the totals, '
                    .'the days worked and the average on that screen.',
            ),
            new SettingDefinition(
                key: 'time_clock.self_schedule',
                label: 'People can edit their own schedule',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, Your account offers Edit on the work schedule. Human Resources can always edit it.',
            ),

            /*
             * PAID TIME OFF — the allowance, not the days taken.
             *
             * From a production suggestion (Toula Elwell, A1-Group, 2026-09-08):
             * "how much paid pto we all have and when it can be used". Approved
             * by Jose 2026-09-17. The second half of her sentence is why these
             * are settings and not a number on a screen: "when it can be used"
             * is a waiting period, and it is the part nobody can look up today.
             *
             * EZBIZI DOES NOT DECIDE ANY OF THESE VALUES. Days, waiting periods
             * and carryover are terms of employment; they vary by company and
             * by state law, and the one place they exist in this product today
             * is the contract templates — where exactly ONE of eleven mentions
             * PTO at all (Project Manager: six days a year, usable after three
             * months, no carryover). So the defaults below are the SAFE ones
             * rather than the common ones: off, and zero days. A company that
             * has a policy says so; one that has not is not told it has six.
             */
            new SettingDefinition(
                key: 'pto.enabled',
                label: 'Paid time off',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, Human Resources shows what paid time off each person has and when they may start using it. Off is the honest default: a company with no written policy should not be shown a balance it never agreed to.',
            ),
            new SettingDefinition(
                key: 'pto.allowance',
                label: 'Paid days off',
                type: SettingDefinition::TYPE_INT,
                default: 0,
                allowedScopes: $orgOnly,
                help: 'The allowance somebody earns in the period below, before anything they have taken. This is your policy, not ours — whatever your contracts say.',
            ),
            new SettingDefinition(
                key: 'pto.allowance_period',
                label: 'Earned per',
                type: SettingDefinition::TYPE_ENUM,
                default: 'year',
                options: ['week', 'month', 'quarter', 'year'],
                optionLabels: ['week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter (3 months)', 'year' => 'Year'],
                allowedScopes: $orgOnly,
                // Six days a year and half a day a month are the same allowance
                // said two ways, and EzBizi converting between them would put
                // words in a contract's mouth. So the screen says back whichever
                // one you chose, and nothing is annualised behind your back.
                help: 'Most written policies say a number of days a year. Per month or per week is for policies that accrue as somebody works.',
            ),
            new SettingDefinition(
                key: 'pto.waiting',
                // The number only: 90 here with Months below is seven and a half
                // years (Jose's screenshot, 2026-10-21), so both labels say so.
                label: 'Can be used after (number — its unit is the next setting)',
                type: SettingDefinition::TYPE_INT,
                default: 0,
                allowedScopes: $orgOnly,
                help: 'Counted from the day somebody started, in the unit set just below. Zero means it can be used from their first day. Check the unit: 90 with Months is seven and a half years.',
            ),
            new SettingDefinition(
                key: 'pto.waiting_unit',
                label: 'UNIT of "Can be used after": days, weeks, months or years',
                type: SettingDefinition::TYPE_ENUM,
                default: 'day',
                options: ['day', 'week', 'month', 'quarter', 'year'],
                optionLabels: [
                    'day' => 'Days',
                    'week' => 'Weeks',
                    'month' => 'Months',
                    'quarter' => 'Quarters (3 months)',
                    'year' => 'Years',
                ],
                allowedScopes: $orgOnly,
                // Months rather than ninety days, when that is what the contract
                // says. They are not the same date — three months from 30
                // November is 28 February, ninety days is 28 February in a leap
                // year and 1 March otherwise — and the one the screen should be
                // able to say back is the one somebody signed.
                help: 'A contract that says "after three months of continuous employment" is three months, not ninety days. Say it the way your contract says it.',
            ),
            new SettingDefinition(
                key: 'pto.carry_over',
                label: 'Unused days carry over',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'At each reset (the "Use by / resets every" setting): when on, unused days come along into the next year or period; when off, they are lost. Check your own contracts and your state law before turning this on or leaving it off — both are lawful somewhere.',
            ),
            // Earned per month and used up by the work anniversary (Jose,
            // 2026-10-21: started 1 Apr 2025, "until 1 Apr 2026 to use the
            // days"). 1 January is what this did before the setting existed.
            new SettingDefinition(
                key: 'pto.reset',
                label: 'Use by / resets every',
                type: SettingDefinition::TYPE_ENUM,
                default: 'jan_1',
                options: ['anniversary', 'jan_1', 'period'],
                optionLabels: [
                    'anniversary' => 'Work anniversary year (from each person’s start date)',
                    'jan_1' => 'Calendar year (1 January)',
                    'period' => 'Each "Earned per" period',
                ],
                allowedScopes: $orgOnly,
                help: 'When the days earned must be used by. Days are still earned per the "Earned per" setting; at each reset, carryover decides whether what is left comes along.',
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
     * Display label for a stored timezone id (POSIX Etc/GMT).
     *
     * Etc/GMT+5 is UTC-5. The day-list API may use an ASCII hyphen (`UTC-5`);
     * the client caption is `Times in {$label}`.
     */
    public static function timezoneLabel(string $id): string
    {
        return self::timezoneLabels()[$id] ?? $id;
    }

    /**
     * @return array<string, string>
     */
    /**
     * Real places first, fixed offsets after them.
     *
     * ── Why places had to be added ───────────────────────────────
     *
     * This list held ONLY `Etc/GMT±N` and `UTC` — 27 fixed offsets and not one
     * real timezone. So the drift was not a badly chosen default: there was no
     * non-drifting option to choose. Every organization resolved `Etc/GMT+5`,
     * which is UTC-5 all year, so from March to November every clock derived
     * from this setting ran an hour behind the Eastern businesses using it.
     *
     * The DEFAULT moved to `America/New_York` on 2026-09-09 as well, on Jose's
     * word that the company is physically in Miami. Offsets stay selectable
     * for anyone who genuinely wants a clock that does not move.
     *
     * Measured by the Marketing session on 2026-09-08: a newsletter set for
     * 09:00 went out at 10:00 New York in summer. The same offset reaches Clock
     * In, Companies, Franchises, dashboard periods and Phone reports, which
     * bucket a near-midnight call into the wrong day for half the year.
     *
     * The fixed offsets are KEPT, and one of them is STILL THE DEFAULT. Moving
     * the default is not a one-line change: seventeen test files across
     * Dashboard, TimeClock, Phone reports, Social posts and HR glances pin
     * `Etc/GMT+5` as the value they inherit, and the full suite found all of
     * them. Rewriting five other modules' timezone expectations is its own
     * piece of work with those modules' owners present.
     *
     * So this change does one thing: it makes a real place CHOOSABLE, which it
     * was not. An organization that wants daylight saving handled sets it, and
     * A1-Group's row was set on 2026-09-09. Whoever moves the default inherits
     * the seventeen files, and the help text says the default is an offset so
     * nobody assumes otherwise.
     *
     * The names match `_zoneNames` in the Phone module's hours pane so the two
     * screens do not disagree about what a place is called. They are two lists
     * on purpose for now: one is Dart in the client, one is PHP on the server,
     * and sharing them is a bigger change than either needed.
     *
     * @return array<string, string>
     */
    private static function timezoneLabels(): array
    {
        $labels = self::PLACES;

        foreach (range(-12, 14) as $hours) {
            $labels[self::timezoneIdForOffset($hours)] = self::timezoneLabelForOffset($hours);
        }

        return $labels;
    }

    /**
     * Somewhere a business actually is.
     *
     * Deliberately short. A list of every IANA zone is 600 entries and a
     * dropdown nobody can find their own city in; these are the places EzBizi
     * has customers or plausibly will. A zone set through the API that is not
     * here still works — SettingDefinition validates against the options, so
     * add to this list rather than reaching for the offsets.
     */
    private const PLACES = [
        'America/New_York' => 'Eastern — New York',
        'America/Detroit' => 'Eastern — Detroit',
        'America/Chicago' => 'Central — Chicago',
        'America/Denver' => 'Mountain — Denver',
        'America/Phoenix' => 'Arizona (no daylight saving)',
        'America/Los_Angeles' => 'Pacific — Los Angeles',
        'America/Anchorage' => 'Alaska',
        'Pacific/Honolulu' => 'Hawaii (no daylight saving)',
        'America/Toronto' => 'Eastern — Toronto',
        'America/Winnipeg' => 'Central — Winnipeg',
        'America/Edmonton' => 'Mountain — Edmonton',
        'America/Vancouver' => 'Pacific — Vancouver',
    ];

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
