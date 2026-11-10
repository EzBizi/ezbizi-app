<?php

namespace App\Support\Reports;

use App\Models\Franchise;
use App\Support\Reports\Texts\BranchName;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The business's location order (Settings › Lists, reports.locations; Shoval,
 * 2026-11-09: "everything that we do in the system, I want to have it in the
 * same order: New Jersey, Connecticut, Texas, Ohio, Illinois, Georgia,
 * Maryland (DMV), Massachusetts, and then Nationwide at the end").
 *
 * A listed name is ONE branch: of the branches called "Texas", the one whose
 * company has the most listed names and no parent (A-1's, not AngiSweep's).
 * Those come first, in the list's order; every other branch after them by its
 * full name, so a sub-company is never between two of A-1's.
 */
final class LocationOrder
{
    /** @return array<string, int> lower-cased listed name => its place */
    public static function positions(): array
    {
        return array_flip(array_map(fn (array $row) => mb_strtolower(trim((string) ($row['value']['branch'] ?? $row['key']))),
            app(BusinessLists::class)->rows('reports.locations')));
    }

    /**
     * The branch each listed name stands for.
     *
     * @param  Collection<int, Franchise>  $branches
     * @return array<string, true> by id
     */
    public static function claimed(Collection $branches, ?array $positions = null): array
    {
        $positions ??= self::positions();
        $key = fn (Franchise $b) => mb_strtolower(trim((string) $b->name));
        $listed = $branches->loadMissing('company')->filter(fn (Franchise $b) => isset($positions[$key($b)]));
        $names = $listed->groupBy('company_id')->map->count();

        return $listed->sortBy(fn (Franchise $b) => [$b->company?->parent_company_id !== null, -($names[$b->company_id] ?? 0)])
            ->unique($key)
            ->mapWithKeys(fn (Franchise $b) => [(string) $b->getKey() => true])->all();
    }

    /**
     * @param  Collection<int, Franchise>  $branches
     * @return Collection<int, Franchise>
     */
    public static function sort(Collection $branches): Collection
    {
        $positions = self::positions();
        $claimed = self::claimed($branches, $positions);

        return $branches->sortBy(fn (Franchise $b) => isset($claimed[(string) $b->getKey()])
            ? [0, $positions[mb_strtolower(trim((string) $b->name))], '']
            : [1, 0, BranchName::of($b)])->values();
    }

    /**
     * A free-text area's place (a marketing sheet's "Chicago"): the listed row
     * whose branch or one of its codes ("IL, Chicago") is a word of it; after
     * every listed one otherwise.
     */
    public static function areaPlace(string $area): int
    {
        foreach (array_values(app(BusinessLists::class)->rows('reports.locations')) as $i => $row) {
            $words = [(string) ($row['value']['branch'] ?? $row['key']), ...explode(',', (string) ($row['value']['code'] ?? ''))];
            foreach (array_filter(array_map('trim', $words)) as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $area)) {
                    return $i;
                }
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * Each branch's place, for lists that hold ids rather than branches.
     *
     * @param  iterable<string>  $ids
     * @return array<string, int>
     */
    public static function places(iterable $ids): array
    {
        // Outside a tenant (a summary built in isolation) there is no list to read: the rows keep their order.
        if (! app(TenantContext::class)->has()) {
            return [];
        }
        $ids = collect($ids)->map(fn ($id) => (string) $id)->filter()->unique()->values();

        return self::sort(Franchise::query()->whereIn('id', $ids)->get())
            ->values()->mapWithKeys(fn (Franchise $b, int $i) => [(string) $b->getKey() => $i])->all();
    }
}
