<?php

namespace App\Support\Settings\Lists;

use App\Models\BusinessListItem;
use InvalidArgumentException;

/**
 * Reads a business list for the organization in context — the one call a
 * module makes where it used to read its constant.
 *
 * Until the business has touched the list, the answer is the definition's
 * `defaults`, so moving a constant here changes nothing on the day it moves.
 * Once any row exists (removed rows are kept, inactive), only the business's
 * own active rows are the answer, so emptying a list leaves it empty.
 */
final class BusinessLists
{
    public function __construct(private readonly ListRegistry $registry) {}

    /** @return list<array{key: string, value: array<string, mixed>, sort: int}> */
    public function rows(string $listKey): array
    {
        $definition = $this->registry->get($listKey)
            ?? throw new InvalidArgumentException("Unknown list '{$listKey}'. Lists are declared in ListRegistry.");

        // No organization in context (a platform command before it enters a
        // tenant): there is no business to have edited it, so the defaults.
        if (! app(\App\Support\Tenancy\TenantContext::class)->has()) {
            return array_map(fn (array $row, int $i) => [...$row, 'sort' => $i], $definition->defaults, array_keys($definition->defaults));
        }

        $stored = BusinessListItem::query()->where('list_key', $listKey)->orderBy('sort')->orderBy('item_key')->get();

        if ($stored->isEmpty()) {
            return array_map(fn (array $row, int $i) => [...$row, 'sort' => $i], $definition->defaults, array_keys($definition->defaults));
        }

        return $stored->where('active', true)
            ->map(fn (BusinessListItem $i) => ['key' => $i->item_key, 'value' => $i->value ?? [], 'sort' => $i->sort])
            ->values()->all();
    }

    /** True once the business has edited this list; false while it runs on the defaults. */
    public function edited(string $listKey): bool
    {
        return app(\App\Support\Tenancy\TenantContext::class)->has()
            && BusinessListItem::query()->where('list_key', $listKey)->exists();
    }
}
