<?php

namespace App\Support\Web;

use App\Models\WebSite;
use App\Models\WebValue;
use Illuminate\Support\Collection;

/**
 * Where a value comes from, in one place, for all three readers.
 *
 * ── The chain, most specific first ──
 *
 *     this page's override   →   this site's value   →   the primary site's
 *
 * Each step is per KEY rather than per site. A branch that overrides only the
 * phone number still publishes the primary's company name, address and hours —
 * which is the whole point, and the same shape a page override already had
 * against its site.
 *
 * ── Resolved for the readers, unresolved for the editor ──
 *
 * The plugin and the browser snippet get a FLAT map with the chain already
 * applied: they render a website and have no business knowing which level an
 * answer came from. That also means inheritance needed no plugin release — the
 * payload shape did not change, only what fills it.
 *
 * The SEO screen needs the opposite: the site's OWN values AND what it would
 * inherit, side by side, or it cannot show somebody that a blank box is not
 * empty but inherited. `forEditor` returns both.
 *
 * ── A site with no primary is not a special case ──
 *
 * If no site is primary, or this site IS the primary, the inherited layer is
 * simply empty and the chain collapses to what it was before. Nothing needs a
 * branch for "inheritance is off".
 *
 * ── Every map is cast to an object ──
 *
 * An empty PHP array encodes as `[]`, not `{}`. The Flutter panel casts these
 * to a Map, so a site with no values yet threw and the panel never opened: a
 * first-run deadlock, since values are added through that panel.
 */
final class ValueResolver
{
    /**
     * The flat, fully-resolved map a website renders from.
     *
     * @param  Collection<int, WebValue>  $own       this site's rows
     * @param  Collection<int, WebValue>  $inherited the primary site's rows
     * @return array{values: object, pages: object}
     */
    public static function forReaders(Collection $own, Collection $inherited): array
    {
        $base = self::siteMap($inherited);
        $mine = self::siteMap($own);

        /*
         * Page overrides are NOT inherited. A path on the primary site is a
         * statement about a page of THAT website; the branch may not even have
         * a page at the same path, and publishing the primary's contact-page
         * phone number onto a branch's contact page because the URLs happen to
         * match would be a coincidence dressed up as a rule.
         */
        $values = self::withComposedAddress(array_merge($base, $mine));
        $week = HoursWeek::parse((string) ($values['hours'] ?? ''));

        return [
            'values' => (object) $values,
            'pages' => (object) self::pageMaps($own),
            /*
             * The week apart, for `[ezbizi_hours_table]`, when the stored line
             * is one the grid wrote. Absent otherwise — see `HoursWeek`.
             *
             * TOP LEVEL rather than inside `values`, which is not tidiness:
             * every entry in `values` is a string that a shortcode casts and
             * prints, and an array in there would reach `[ezbizi key="..."]`
             * as "Array to string conversion" on a customer's page.
             */
            ...($week === null ? [] : ['hours_week' => $week]),
        ];
    }

    /**
     * `address` is one line built from the parts, when there are parts.
     *
     * The parts win over a stored one-liner because they are the thing a person
     * actually edits now — a stale single string left over from before the
     * split must not keep rendering after somebody has corrected the ZIP. A
     * site with NO parts keeps whatever `address` it had, so nothing that
     * pre-dates the split changes.
     *
     * The format is the one a US footer expects and JSON-LD can be derived
     * from: "112 Bloomfield Ave, Suite 4, Caldwell, NJ 07006".
     *
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    /**
     * This site's own web address, which nobody should have to type.
     *
     * Derived rather than stored: the host is already the record, and a typed
     * copy is a second version of one fact that goes stale the day a domain
     * changes. Overwrites anything stored under the key for the same reason —
     * if a row ever exists it is wrong by definition.
     *
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public static function withWebsiteUrl(array $map, ?string $host): array
    {
        $host = trim((string) $host);

        if ($host !== '') {
            $map['website_url'] = 'https://'.$host.'/';
        }

        return $map;
    }

    public static function withComposedAddress(array $map): array
    {
        $parts = array_filter([
            $map['address_line1'] ?? '',
            $map['address_line2'] ?? '',
        ], fn (string $s) => trim($s) !== '');

        $city = trim($map['city'] ?? '');
        $state = trim($map['state'] ?? '');
        $zip = trim($map['postal_code'] ?? '');

        if ($parts === [] && $city === '' && $state === '' && $zip === '') {
            return $map;
        }

        $tail = trim($state.' '.$zip);
        $locality = array_filter([$city, $tail], fn (string $s) => $s !== '');

        $map['address'] = implode(', ', [...array_map('trim', $parts), ...$locality]);

        return $map;
    }

    /**
     * What the editing screen needs: this site's own answers, and separately
     * the ones it would fall back to.
     *
     * @param  Collection<int, WebValue>  $own
     * @param  Collection<int, WebValue>  $inherited
     * @return array{values: object, pages: object, inherited: object}
     */
    public static function forEditor(Collection $own, Collection $inherited): array
    {
        return [
            'values' => (object) self::withComposedAddress(self::siteMap($own)),
            'pages' => (object) self::pageMaps($own),
            'inherited' => (object) self::withComposedAddress(self::siteMap($inherited)),
        ];
    }

    /**
     * The rows a site inherits from — the primary of its company, unless it is
     * the primary itself.
     *
     * @return Collection<int, WebValue>
     */
    public static function inheritedRowsFor(WebSite $site): Collection
    {
        if ($site->is_primary) {
            return collect();
        }

        $primary = WebSite::query()
            ->where('company_id', $site->company_id)
            ->whereNotNull('primary_company_id')
            ->first();

        if ($primary === null) {
            return collect();
        }

        return WebValue::query()
            ->where('site_id', $primary->getKey())
            ->where('path', '')
            ->get(['path', 'key', 'value']);
    }

    /** @param Collection<int, WebValue> $rows */
    private static function siteMap(Collection $rows): array
    {
        return $rows->where('path', '')->pluck('value', 'key')->all();
    }

    /** @param Collection<int, WebValue> $rows */
    private static function pageMaps(Collection $rows): array
    {
        return $rows->where('path', '!=', '')
            ->groupBy('path')
            ->map(fn (Collection $group) => (object) $group->pluck('value', 'key')->all())
            ->all();
    }
}
