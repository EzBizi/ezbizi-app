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
    public const WIDGETS = [
        'social_links',
        'city_list',
        'hours_table',
        'google_map',
        // Jose, 2026-09-23, after walking A1 Tools' three live sites with me:
        // "DO ALL OF THEM, END TO END". These three are the ones every page of
        // a-1chimney.com, everdoors.com and crestln.com carries, so they are
        // first: contact details as a block, a standing call to action, and
        // the phone on its own.
        'contact_info',
        'cta',
        'phone_box',
        // Jose, 2026-09-24: "we have a widget on a1tools that needs a ezbizi
        // counterpart asap" — the two faces on a-1chimney.com/reviews.
        'review_faces',
    ];

    public const DEFAULT = 'default';

    /**
     * Names the plugin has already taken as shortcodes of its own.
     *
     * The plugin registers `[ezbizi_city_list]` and its four siblings from
     * `widgets.php`, and THEN walks the field catalogue registering
     * `[ezbizi_<key>]` for every key this server sends. The second loop wins.
     * So a field added here called `city_list` would not clash loudly — it
     * would quietly turn a rendered list of towns into a plain string on 110
     * websites, and the only symptom is somebody's footer going flat.
     *
     * `post_title` is in the list although it carries no preset: it is a
     * shortcode the plugin owns, which is what makes it collidable.
     *
     * Kept here rather than in `SeoFields` because it is a fact about the
     * PLUGIN, and `SeoFields` is the file somebody edits while thinking about
     * fields. `WebManagementTest` asserts the two sets stay disjoint.
     */
    public const PLUGIN_OWNED_SHORTCODES = [
        'social_links',
        'city_list',
        'hours_table',
        'google_map',
        'post_title',
        'contact_info',
        'cta',
        'phone_box',
        'review_faces',
        'street_view',
    ];

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
            /*
             * The block in every footer on all three A1 sites: an icon, a
             * label, a value, three times over.
             *
             * `stack` rather than a row, because that is what a footer column
             * is; `icons` on, because the row reads as a list without them and
             * the icon is what makes a phone number scannable. `labels` is
             * separate from `icons` since the compact form in a header wants
             * the icon and not the word.
             */
            'contact_info' => [
                'layout' => 'stack',
                'icons' => true,
                'labels' => true,
                /*
                 * Three flags rather than one comma-separated `show`.
                 *
                 * A CSV would need a text box on the screen, a parser in the
                 * plugin, and a rule for what "phone,,emial" means. Three
                 * switches need none of that and say the same thing — and the
                 * only cost is that adding a fourth detail later is a fourth
                 * key rather than a longer string, which is the cheap
                 * direction.
                 */
                'phone' => true,
                'email' => true,
                'address' => true,
            ],
            /*
             * A1's sticky yellow "CALL US TODAY" bar, and Everdoors' popup.
             *
             * `inline` is the default and the other two are not, deliberately:
             * a bar and a popup are both things that cover part of somebody's
             * page, and a widget that did that the moment it was pasted in
             * would be a surprise on a live site. Somebody choosing `bar` has
             * decided to take that space.
             */
            'cta' => [
                'placement' => 'inline',
                'action' => 'call',
                'icon' => true,
                'full_width' => false,
                /*
                 * Empty means the wording that matches the action — "Call us
                 * today" for a call, "Book online" for a booking. A1's bar
                 * says CALL US TODAY and Everdoors' popup says something else
                 * entirely, so the words have to be settable; making the
                 * default EMPTY rather than a phrase means a site that says
                 * nothing still gets a sentence rather than a blank button.
                 */
                'text' => '',
            ],
            /*
             * The phone on its own, which is the most-placed single widget on
             * the estate — headers, hero sections, service cards.
             *
             * `link` on, because a phone number that is not tappable on a
             * phone is the whole value of the widget thrown away.
             */
            'phone_box' => [
                'size' => 'medium',
                'icon' => true,
                'link' => true,
                'label' => '',
            ],
            /*
             * A1 Tools' smiley widget: a happy face sends somebody to the
             * site's `review_link`, a sad one opens a form whose answer lands
             * in Leads rather than on Google. The wording defaults are the
             * ones a-1chimney.com/reviews shows today.
             *
             * No link setting here: the link is a business fact, and it lives
             * in `review_link` with the others.
             */
            'review_faces' => [
                // `dark` is for a dark section: a-1chimney.com/reviews sits
                // on a photo of bricks under a dark overlay.
                'tone' => 'light',
                'face_size' => 56,
                'title' => 'How was your experience?',
                'text' => 'Tap a face to let us know.',
                'happy_label' => 'Great!',
                'sad_label' => 'Not so great',
                'happy_title' => 'So glad we hit the mark!',
                'happy_text' => 'Tell us what we did well.',
                'button' => 'Leave a review on Google',
                'sad_title' => 'We are sorry we missed the mark',
                'sad_text' => 'Tell us what went wrong and how we can make it right.',
                'submit' => 'Send feedback',
                'thanks' => 'Thank you! We have received your feedback and will be in touch shortly.',
                // After the private feedback is sent. A switch rather than
                // "leave it empty": an emptied box arrives as null and comes
                // back as this default, so empty cannot mean anything.
                'public_prompt' => 'If you like, you can also share your experience publicly on Google.',
                'ask_public' => true,
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
        'contact_info' => [
            'layout' => ['stack', 'row', 'compact'],
        ],
        'cta' => [
            // `bar` is fixed to the bottom of the window, `popup` opens over
            // the page once per visit, `inline` sits where it was pasted.
            'placement' => ['inline', 'bar', 'popup'],
            'action' => ['call', 'book', 'form'],
        ],
        'phone_box' => [
            'size' => ['small', 'medium', 'large'],
        ],
        'review_faces' => [
            'tone' => ['light', 'dark'],
        ],
    ];

    /**
     * The options that are free text, and the longest each may be.
     *
     * DECLARED rather than inferred, because `one()` ends by checking the
     * value against `CHOICES` and a key with no choices falls to the default
     * every time. Without this list a text option would look settable on the
     * screen, save, come back as the default, and report nothing — the
     * quietest kind of broken. Found by reading `one()` before shipping it,
     * not by a test.
     *
     * The cap is what stops a preset becoming a place to store a paragraph on
     * somebody else's page. The plugin escapes what it renders, so this is a
     * size limit rather than a safety one.
     */
    private const TEXT = [
        'cta' => ['text' => 80],
        'phone_box' => ['label' => 60],
        'review_faces' => [
            'title' => 80, 'text' => 160, 'happy_label' => 30, 'sad_label' => 30,
            'happy_title' => 80, 'happy_text' => 160, 'button' => 60,
            'sad_title' => 80, 'sad_text' => 160, 'submit' => 40,
            'thanks' => 200, 'public_prompt' => 200,
        ],
    ];

    /** Numbers, with the range a website can survive. */
    private const RANGES = [
        'social_links' => ['size' => [16, 64], 'gap' => [0, 40]],
        'city_list' => ['columns' => [1, 6]],
        'google_map' => ['height' => [120, 900], 'zoom' => [1, 20]],
        'review_faces' => ['face_size' => [40, 120]],
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

            if (isset(self::TEXT[$widget][$key])) {
                $clean = trim(strip_tags((string) $value));
                $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

                $out[$key] = mb_substr($clean, 0, self::TEXT[$widget][$key]);

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
