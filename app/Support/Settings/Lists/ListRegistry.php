<?php

namespace App\Support\Settings\Lists;

use App\Models\Customer;
use App\Support\Reports\MarketingReport;
use App\Support\Reports\Tagging\CallLabels;
use App\Support\Workiz\WorkizTwins;
use InvalidArgumentException;

/**
 * Every business list the software knows (Settings › Lists, 2026-11-04).
 *
 * A module moves a code constant here by appending one ListDefinition to
 * definitions() with the constant as its `defaults`, then reading it through
 * BusinessLists::rows(). Nothing else: the API, the audit and the screen come
 * with the definition.
 */
final class ListRegistry
{
    /** @var array<string, ListDefinition> */
    private array $lists = [];

    public function __construct()
    {
        foreach ($this->definitions() as $definition) {
            $this->register($definition);
        }
    }

    public function register(ListDefinition $definition): void
    {
        if (isset($this->lists[$definition->key])) {
            throw new InvalidArgumentException("List '{$definition->key}' is already registered.");
        }

        $this->lists[$definition->key] = $definition;
    }

    public function get(string $key): ?ListDefinition
    {
        return $this->lists[$key] ?? null;
    }

    /** @return array<string, ListDefinition> */
    public function all(): array
    {
        return $this->lists;
    }

    /** @return list<ListDefinition> */
    private static function placeholders(string $key, string $label, array $defaults, string $help): array
    {
        // The value is the row's own first column, so typing it on the Lists
        // screen is how one is added (its key is that text).
        return [new ListDefinition(
            key: $key, label: $label, module: 'customers',
            columns: [['key' => 'value', 'label' => 'Value']],
            defaults: array_map(fn ($v) => ['key' => $v, 'value' => ['value' => $v]], $defaults),
            help: $help, editPermission: 'customers.edit',
        )];
    }

    /** @return list<ListDefinition> */
    private function definitions(): array
    {
        return [
            // Module owners append theirs here (inventory:
            // docs/reviews/2026-11-04/hardcoded-business-data.md).
            ...self::placeholders('customers.placeholder_emails', 'Placeholder emails', Customer::PLACEHOLDER_EMAILS,
                'Addresses that mean "no real email": never kept on a customer, matched or mailed.'),
            ...self::placeholders('customers.placeholder_phones', 'Placeholder phones', Customer::PLACEHOLDER_PHONES,
                'Relay, tracking and office lines typed in as the customer phone (ten digits, no leading 1): never kept, texted or matched.'),
            ...self::placeholders('customers.placeholder_streets', 'Placeholder streets', WorkizTwins::PLACEHOLDER_STREETS,
                'Office streets typed in as the customer address: never used to match twins.'),
            // Which phone-system name is which Workiz person (Reports, Jose
            // 2026-11-04). No defaults: the pairs are a business's own staff.
            // Phone name first, so a row added on the screen is keyed by it.
            new ListDefinition(
                key: 'reports.staff_names', label: 'Staff names: phone system to Workiz', module: 'reports',
                columns: [['key' => 'phone_name', 'label' => 'Name on the phones'], ['key' => 'name', 'label' => 'Name in Workiz']],
                help: 'Only where the two differ by more than a middle name or an initial (Jay Ticoit is Dieuve '
                    .'Ticoit). Everyone else is matched to Workiz\'s team list by first and last name.',
                editPermission: 'reports.handles',
            ),
            // The source a job gets from the line its client dialed (Jose, 2026-11-16).
            new ListDefinition(
                key: 'jobs.sources_by_line', label: 'Job source by line dialed', module: 'jobs',
                columns: [['key' => 'line', 'label' => 'Line: a number, or the end of its name (- PPC)'], ['key' => 'source', 'label' => 'Workiz job source']],
                help: 'Used by the automation step "Set job sources from the line dialed". A number matches that line; '
                    .'anything else matches a line whose name ends with it. The source must be on the branch\'s own Workiz list.',
                editPermission: 'jobs.edit',
            ),
            // Which Workiz job sources Google Ads pays for (Reports' Marketing).
            new ListDefinition(
                key: 'reports.paid_sources', label: 'Paid job sources', module: 'reports',
                columns: [['key' => 'source', 'label' => 'Workiz job source'], ['key' => 'channel', 'label' => 'Ad channel (lsa, ppc or meta)']],
                defaults: array_map(fn (string $source) => ['key' => $source, 'value' => ['source' => $source, 'channel' => MarketingReport::PAID[$source]]],
                    array_keys(MarketingReport::PAID)),
                help: 'The job sources your ads pay for: lsa for Google Local Services, ppc for Google Search, meta for '
                    .'Facebook. Marketing puts that channel\'s cost against them, and Local Services leads count toward the lsa one.',
                editPermission: 'reports.settings',
            ),
            // The daily and weekly WhatsApp texts (Jose, 2026-11-06: "order of report by states").
            new ListDefinition(
                key: 'reports.locations', label: 'Report locations, in order', module: 'reports',
                columns: [['key' => 'branch', 'label' => 'Branch'], ['key' => 'code', 'label' => 'Codes in Meta campaign names, comma-separated (TX, DFW)']],
                help: 'The branches the WhatsApp report texts list, in this order; a branch not here is listed only when it has '
                    .'something to show. A Meta campaign with no branch counts toward the branch whose code is its state or a word of its name.',
                editPermission: 'reports.settings',
            ),
            ...array_map(fn (array $list) => new ListDefinition(
                key: $list[0], label: $list[1], module: 'reports', columns: [['key' => 'tag', 'label' => 'Workiz tag']],
                defaults: array_map(fn (string $tag) => ['key' => $tag, 'value' => ['tag' => $tag]], $list[2]),
                help: $list[3].' Emoji, spaces and dashes are ignored, so "Paid – Estimated" also matches it with a coloured dot in front.',
                editPermission: 'reports.settings',
            ), [
                ['reports.tags_not_in_progress', 'Tags left out of In Progress jobs', ['DEPOSIT RECEIVED'],
                    'Jobs with one of these tags are not counted in the In Progress jobs texts.'],
                ['reports.tags_not_created', 'Tags left out of jobs created', ['PAID - ESTIMATED', 'PAID - ESTIMATED (BY SUB)'],
                    'Jobs with one of these tags are not counted in the jobs created texts.'],
            ]),
            new ListDefinition(
                key: 'reports.dispatcher_roles', label: 'Dispatcher roles, in order', module: 'reports',
                columns: [['key' => 'role', 'label' => 'Role']],
                defaults: array_map(fn (string $role) => ['key' => $role, 'value' => ['role' => $role]], ['Dispatcher', 'Remote Dispatcher']),
                help: 'The weekly dispatcher texts list people by these roles, in this order; anyone else comes last.',
                editPermission: 'reports.settings',
            ),
            // Why a call came in, as the AI sorts them (Reports, Jose 2026-11-04).
            ...array_map(fn (array $list) => new ListDefinition(
                key: CallLabels::LISTS[$list[0]], label: $list[1], module: 'reports',
                columns: [['key' => 'label', 'label' => 'Label'], ['key' => 'name', 'label' => 'Shown as'],
                    ['key' => 'meaning', 'label' => 'What it means (told to the AI)']],
                defaults: CallLabels::defaults($list[2]), help: $list[3], editPermission: 'reports.handles',
            ), [
                ['existing', 'Call labels: existing customers', CallLabels::EXISTING,
                    'Why an existing customer called. The AI picks one per call, by its meaning. Keep "other" for the rest.'],
                ['not_booked', 'Call labels: new customers', CallLabels::NOT_BOOKED,
                    'Why a new customer did not book, or "booked". The AI picks one per call, by its meaning. Keep "other" for the rest.'],
            ]),
        ];
    }
}
