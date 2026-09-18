<?php

namespace App\Support\Web;

/**
 * How the rendered widgets LOOK, as named bundles a page can pick from.
 *
 * ── The argument this settles ──
 *
 * A1 Tools carries presentation per instance:
 *
 *     [a1tools_city_list layout="accordion" columns="3" show_search="yes"]
 *     [a1tools_social_links style="outline" shape="circle" size="40"]
 *
 * EzBizi decided the opposite when the chat and the form shipped: shortcodes
 * take no arguments and every setting lives in the Widgets tab, so a company
 * with six subdomains does not set its colours six times, differently,
 * eventually. That decision is why changing an accent in EzBizi reaches 110
 * websites without anybody editing a page.
 *
 * Both cannot be true for a widget Toula has to drop into a footer that needs
 * small round icons and into a contact panel that needs big labelled ones. So:
 *
 *     A PRESET IS A NAMED BUNDLE OF PRESENTATION, HELD HERE, PICKED BY NAME.
 *
 *     [ezbizi_social_links preset="footer"]
 *
 * The name is the only argument. Every value inside it is still edited in one
 * place and still reaches every site at once, so the property that made the
 * no-arguments rule worth having survives — while a page can differ from
 * another page, which is what the existing pages need.
 *
 * ── Why every widget always has a `default` ──
 *
 * Because a live page must not go blank over a typo. `preset="foter"` resolves
 * to the default and renders something reasonable; it does not render nothing,
 * and it does not render an error into somebody's footer. `resolve()` therefore
 * GUARANTEES a `default` for every widget, whatever is stored.
 *
 * ── Why the options are so few ──
 *
 * They are the ones the live pages use, read off six rendered A1 Tools pages on
 * 2026-09-18, and nothing else. A control nobody has ever set is a control that
 * has to be sanitised, sent, documented and drawn for ever. More can be added
 * the day somebody wants one; taking one away later is the expensive direction.
 */
final class WidgetPresets
{
    /** The widgets that have a look worth naming. */
    public const WIDGETS = ['social_links', 'city_list', 'hours_table', 'google_map'];

    public const DEFAULT = 'default';

    /**
     * What each widget looks like before anybody names a preset, and the only
     * keys a stored preset may carry.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            /*
             * Live pages show 21–25 of these in a row. `plain` rather than a
             * coloured chip by default because they sit in footers next to
             * text, and a row of brand-coloured squares on a dark footer was
             * the thing most often overridden on the old sites.
             */
            'social_links' => [
                'style' => 'plain',
                'shape' => 'circle',
                'size' => 28,
                'gap' => 10,
                'labels' => false,
            ],
            /*
             * The accordion is A1's most-used arrangement — 17 of them on
             * nj.a-1chimney.com — but `columns` is the safer default: it
             * renders the same with JavaScript switched off, and a list of
             * towns is a list of links whatever else happens.
             */
            'city_list' => [
                'layout' => 'columns',
                'columns' => 3,
                'group' => true,
                'link' => true,
            ],
            /*
             * `today` earns its default: the reason a visitor reads an hours
             * table at all is to find out whether the business is open now.
             */
            'hours_table' => [
                'today' => true,
                'compact' => false,
            ],
            'google_map' => [
                'height' => 320,
                'zoom' => 14,
            ],
        ];
    }

    /** The values each option may take. Anything else becomes the default. */
    private const CHOICES = [
        'social_links' => [
            'style' => ['plain', 'solid', 'outline'],
            'shape' => ['circle', 'square', 'rounded'],
        ],
        'city_list' => [
            'layout' => ['columns', 'list', 'accordion'],
        ],
    ];

    /** Numbers, with the range a website can survive. */
    private const RANGES = [
        'social_links' => ['size' => [16, 64], 'gap' => [0, 40]],
        'city_list' => ['columns' => [1, 6]],
        'google_map' => ['height' => [120, 900], 'zoom' => [1, 20]],
    ];

    /**
     * Stored presets, forced into shape, with a `default` for every widget.
     *
     * Shape on the wire, and the plugin reads exactly this:
     *
     *     presets: { social_links: { default: {...}, footer: {...} }, ... }
     *
     * Keyed by widget first and name second, because "footer" means something
     * different to a row of icons than it does to a list of towns, and one flat
     * namespace would make the two collide on the obvious name.
     *
     * @param  mixed  $stored
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function resolve($stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach (self::defaults() as $widget => $defaults) {
            $named = is_array($stored[$widget] ?? null) ? $stored[$widget] : [];

            // The default first, so it exists even if the stored config has
            // nothing for this widget — or has only a named one.
            $out[$widget] = [self::DEFAULT => self::one($widget, $named[self::DEFAULT] ?? [])];

            foreach ($named as $name => $preset) {
                $clean = self::name((string) $name);

                if ($clean === '' || $clean === self::DEFAULT) {
                    continue;
                }

                $out[$widget][$clean] = self::one($widget, $preset);
            }
        }

        return $out;
    }

    /**
     * One preset: the widget's defaults with any recognised override applied.
     *
     * Unknown keys are dropped rather than passed through. They would reach a
     * `<style>` block on a customer's website, and a config is editable through
     * the API by anybody who can edit the site.
     *
     * @param  mixed  $stored
     * @return array<string, mixed>
     */
    private static function one(string $widget, $stored): array
    {
        $defaults = self::defaults()[$widget];
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach ($defaults as $key => $default) {
            $value = $stored[$key] ?? null;

            if ($value === null) {
                $out[$key] = $default;

                continue;
            }

            if (is_bool($default)) {
                $read = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $out[$key] = $read ?? $default;

                continue;
            }

            if (isset(self::RANGES[$widget][$key])) {
                [$min, $max] = self::RANGES[$widget][$key];
                $out[$key] = max($min, min($max, (int) $value));

                continue;
            }

            $choices = self::CHOICES[$widget][$key] ?? [];
            $out[$key] = in_array($value, $choices, true) ? $value : $default;
        }

        return $out;
    }

    /**
     * A preset name somebody can type into a shortcode without quoting rules.
     *
     * Lowercase, letters, digits, hyphen and underscore, 32 characters. The
     * name travels through a WordPress shortcode attribute and then into an
     * array lookup, so the narrow alphabet is what stops it being a place to
     * put something else.
     */
    public static function name(string $raw): string
    {
        $clean = strtolower(trim($raw));
        $clean = preg_replace('/[^a-z0-9_-]/', '', $clean) ?? '';

        return substr($clean, 0, 32);
    }
}
