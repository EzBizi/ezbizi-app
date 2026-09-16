<?php

namespace App\Support\Web;

use App\Models\WebSite;
use App\Models\WebValue;
use Illuminate\Support\Str;

/**
 * The free-text fields a customer invents for one page of their own website.
 *
 * ── What these are for ──
 *
 * `SeoFields` is a fixed catalogue of business facts: a phone number, a licence,
 * an address. Jose asked for the other half — somewhere to put the actual words
 * on a page, paragraph by paragraph, so the copy lives in EzBizi and the theme
 * only renders it. Those cannot be a fixed list, because nobody knows in advance
 * how many paragraphs the About page has.
 *
 * ── Why there is no table ──
 *
 * A custom field IS its value. `web_values` already stores (site, path, key,
 * value) and already has per-page overrides, so creating a field is saving a
 * row and deleting the last one removes it. A separate table naming the fields
 * would be a second list to keep in step with the first, and the failure mode
 * of that is a field that exists but publishes nothing, or a value nothing will
 * render.
 *
 * ── Why the key is shaped ──
 *
 * The plugin registers a shortcode for every key the server sends, so a key is
 * a promise: `[ezbizi_home_field_1]` appears on a customer's page and must keep
 * meaning the same thing. The shape below keeps invented keys in their own
 * namespace, so a field somebody made up in 2026 cannot collide with a built-in
 * field added in 2027 — that collision would silently repoint a shortcode
 * already pasted into a live page.
 */
final class PageFields
{
    /**
     * `<page>_field_<n>`, lower case, e.g. `home_field_1`, `about_us_field_3`.
     *
     * Anchored, so `field_1` alone and `home_field_1_x` are both refused. The
     * number is what the screen increments; the prefix is the page's slug, so a
     * person reading `[ezbizi_about_us_field_2]` in a template can tell where it
     * belongs without opening EzBizi.
     */
    public const PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*_field_[1-9][0-9]{0,2}$/';

    /** One page's worth, at most. Beyond this it is a CMS, which this is not. */
    public const MAX_PER_PAGE = 30;

    public static function isCustomKey(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1
            // A built-in always wins: if a future SeoFields key ever matched
            // this shape, the catalogue would carry it twice.
            && ! in_array($key, SeoFields::keys(), true);
    }

    /**
     * The key a new field on this page should take.
     *
     * Derived from the page's own path so it reads as belonging to it, and
     * numbered from the keys already there rather than from a count — deleting
     * field 2 of three must not make the next one collide with field 3.
     */
    public static function nextKey(string $path, array $existing): string
    {
        $slug = self::slug($path);
        $taken = [];

        foreach ($existing as $key) {
            if (preg_match('/^'.preg_quote($slug, '/').'_field_([0-9]+)$/', $key, $m) === 1) {
                $taken[] = (int) $m[1];
            }
        }

        return $slug.'_field_'.(($taken === [] ? 0 : max($taken)) + 1);
    }

    /**
     * A page path as a key prefix. `/about-us/` becomes `about_us`, and the
     * site root becomes `home` because `_field_1` on its own is not a name.
     */
    public static function slug(string $path): string
    {
        $slug = Str::of($path)
            ->trim('/')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(40, '')
            ->value();

        return $slug === '' ? 'home' : $slug;
    }

    /**
     * Every custom key this site has, with the page it belongs to.
     *
     * Read from the values themselves, which is what makes the two lists
     * impossible to disagree.
     *
     * @return array<string, string> key => path
     */
    public static function forSite(WebSite $site): array
    {
        $found = [];

        $rows = WebValue::query()
            ->where('site_id', $site->getKey())
            ->get(['path', 'key']);

        foreach ($rows as $row) {
            if (self::isCustomKey((string) $row->key)) {
                $found[(string) $row->key] = (string) $row->path;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Those keys in the shape the plugin's field catalogue takes, so each one
     * becomes a shortcode on the customer's site like any built-in field.
     *
     * @return array<int, array<string, string>>
     */
    public static function catalogueFor(WebSite $site): array
    {
        $out = [];

        foreach (self::forSite($site) as $key => $path) {
            $out[] = [
                'key' => $key,
                'label' => self::label($key),
                'hint' => 'Free text for '.($path === '' ? 'the home page' : $path).'.',
                'requirement' => SeoFields::OPTIONAL,
                'section' => SeoFields::GENERAL,
                'shortcode' => '[ezbizi_'.$key.']',
                // The page it overrides, so a screen can group them without
                // parsing the key back apart.
                'path' => $path,
            ];
        }

        return $out;
    }

    /** `about_us_field_2` reads as "About us — field 2". */
    private static function label(string $key): string
    {
        if (preg_match('/^(.*)_field_([0-9]+)$/', $key, $m) !== 1) {
            return $key;
        }

        return Str::of($m[1])->replace('_', ' ')->ucfirst()->value().' — field '.$m[2];
    }
}
