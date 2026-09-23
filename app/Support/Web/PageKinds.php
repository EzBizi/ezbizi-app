<?php

namespace App\Support\Web;

/**
 * What kind of page this is — asked once, so two screens cannot disagree.
 *
 * ── Why this exists at all ──
 *
 * Mia, 2026-09-18: city pages "just need to be separated from the current pages
 * tab, and moved into a cities tab, i believe toula has already filled a lot of
 * information on them already on ezbizi we dont want to destroy her work."
 *
 * She is right about the volume. Measured on production that day: 8,956 page
 * paths carry a `city`, 7,904 carry a `group`, and one host — call.a-1chimney.com
 * — has 609 of them. Mixed into the Pages list they bury every real page a
 * person was looking for.
 *
 * So Pages has to stop showing them and Cities has to start, and those are two
 * lists that must partition rather than overlap. One classifier, called by
 * both. Two independent rules would drift, and the failure is a page in both
 * lists or in neither, found by a customer.
 *
 * ── The two signals, and why both ──
 *
 * 1. IT HOLDS A `city` VALUE. Toula's own work defines the list, which is the
 *    same argument `PageFields::forSite()` already makes about reading keys
 *    from the values rather than keeping a second register of them. This is
 *    also the only signal that costs nothing: the values are in our database.
 *
 * 2. ITS WORDPRESS AUTHOR IS A CITY-KIND ACCOUNT. Every site on the fleet files
 *    pages under author accounts that name the kind — `Single City`, `SEO CITY`,
 *    `Location City`, `Single Service`, `Single Post`, `Privacy` — and A1 Tools
 *    keys off exactly this. It is the only signal that can see a city page
 *    NOBODY HAS FILLED IN YET, which is the work Toula is actually doing.
 *
 * `Single Location` is deliberately NOT a city: A1 Tools separates city pages
 * from location pages, and so should we. `Location City` contains both words
 * and is a city, because it says City.
 *
 * ── Why the match is on "city" and not on a list of names ──
 *
 * Because the names differ per site and nobody here controls them: atl. has
 * `Location City` where nj. has `Single Location`. A hard-coded roster would be
 * wrong on the next site somebody builds, and wrong silently — the pages would
 * quietly stay in the wrong list. Matching the word is what survives a site
 * being set up by somebody who chose slightly different names.
 */
final class PageKinds
{
    public const CITY = 'city';

    public const PAGE = 'page';

    /**
     * Whether an author's display name names the city kind.
     *
     * Case-insensitive and word-boundaried, so `Single City` and `SEO CITY`
     * match and a page authored by somebody called "Felicity" does not.
     */
    public static function authorNamesCity(?string $authorName): bool
    {
        $name = trim((string) $authorName);

        if ($name === '') {
            return false;
        }

        return preg_match('/\bcity\b/i', $name) === 1;
    }

    /**
     * Whether the values held against a path make it a city page.
     *
     * A `city` with something in it. An empty string is not a statement — it is
     * what a box looks like after somebody clears it — and a path whose only
     * row is a blank `city` would otherwise leave Pages for ever.
     *
     * @param  array<string, mixed>  $values  this path's own values
     */
    public static function valuesNameCity(array $values): bool
    {
        return trim((string) ($values['city'] ?? '')) !== '';
    }

    /**
     * The verdict for one page, from whichever signals the caller has.
     *
     * Either signal is enough. A page with a `city` filled in but authored by
     * the brand account is a city page — she typed a city into it — and a page
     * authored by `SEO CITY` with nothing filled in is one too, because that is
     * the page she has not reached yet.
     *
     * @param  array<string, mixed>  $values
     */
    public static function of(array $values = [], ?string $authorName = null): string
    {
        return self::valuesNameCity($values) || self::authorNamesCity($authorName)
            ? self::CITY
            : self::PAGE;
    }

    /**
     * The paths on this site that Cities claims, so Pages can leave them out.
     *
     * Built from the values ALONE and deliberately: `SiteContent::list` returns
     * one page of results at a time, so a Pages list filtered on the author
     * signal would need the author names resolved for every page in the site
     * before it could draw the first twenty. The values are already here.
     *
     * The consequence is worth stating rather than discovering: a city page
     * nobody has filled in yet stays in Pages until it has a city, which is
     * also where somebody would look for it. `?scan=1` on the cities read is
     * what finds those.
     *
     * @param  iterable<object{path: string, key: string, value: string}>  $rows
     * @return array<string, true>  a set, for O(1) lookups against a page list
     */
    public static function cityPathsFrom(iterable $rows): array
    {
        $paths = [];

        foreach ($rows as $row) {
            $path = (string) ($row->path ?? '');

            if ($path === '' || (string) ($row->key ?? '') !== 'city') {
                continue;
            }

            if (trim((string) ($row->value ?? '')) !== '') {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    /**
     * The path a WordPress permalink points at, in the form values are keyed by.
     *
     * `https://nj.a-1chimney.com/service-areas/toms-river-nj/` becomes
     * `/service-areas/toms-river-nj`. Kept here beside the classifier because
     * matching a live page against a stored value is the one place the two
     * halves of this feature meet, and a trailing slash on one side of that
     * comparison is a page that silently appears in both lists.
     */
    public static function pathOf(string $link): string
    {
        $path = (string) parse_url($link, PHP_URL_PATH);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.trim($path, '/');
    }
}
