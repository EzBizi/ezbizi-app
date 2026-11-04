<?php

namespace App\Support\Settings\Lists;

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
            ...self::placeholders('customers.placeholder_emails', 'Placeholder emails', \App\Models\Customer::PLACEHOLDER_EMAILS,
                'Addresses that mean "no real email": never kept on a customer, matched or mailed.'),
            ...self::placeholders('customers.placeholder_phones', 'Placeholder phones', \App\Models\Customer::PLACEHOLDER_PHONES,
                'Relay, tracking and office lines typed in as the customer phone (ten digits, no leading 1): never kept, texted or matched.'),
            ...self::placeholders('customers.placeholder_streets', 'Placeholder streets', \App\Support\Workiz\WorkizTwins::PLACEHOLDER_STREETS,
                'Office streets typed in as the customer address: never used to match twins.'),
        ];
    }
}
