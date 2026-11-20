<?php

namespace App\Support\Financials\QuickBooks;

use Illuminate\Support\Collection;

/**
 * A QuickBooks Class or Location name → the branch it most plainly means, or
 * null. Exact (case, spacing and punctuation ignored) first; then the one
 * branch whose name contains it or is contained in it. Two candidates is no
 * answer: a person chooses in Financials › Markets.
 */
final class MarketMatch
{
    /** @param  Collection<int, object{id: string, name: string}>  $franchises */
    public static function franchiseFor(string $name, Collection $franchises): ?string
    {
        // "Parent:Child" — QuickBooks' sub-class path; the leaf is the market.
        $leaf = self::norm(str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name);
        if ($leaf === '') {
            return null;
        }

        $exact = $franchises->filter(fn ($f) => self::norm((string) $f->name) === $leaf);
        if ($exact->count() === 1) {
            return (string) $exact->first()->id;
        }

        $near = $franchises->filter(function ($f) use ($leaf) {
            $n = self::norm((string) $f->name);

            return $n !== '' && (str_contains($n, $leaf) || str_contains($leaf, $n));
        });

        return $near->count() === 1 ? (string) $near->first()->id : null;
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }
}
