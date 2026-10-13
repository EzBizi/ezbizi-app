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
    public const DEFAULT = 'default';

    /**
     * What each widget looks like before anybody names a preset, and the only
     * keys a stored preset may carry.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $out = self::base();

        foreach ($out as $widget => $options) {
            foreach (self::generated($widget) as $key => $rule) {
                $out[$widget][$key] = $rule['default'];
            }
        }

        return $out;
    }

    /**
     * The parts of each widget that can be styled, and what kind each is.
     *
     * Jose, 2026-09-25: "i want all the options, for all the widgets please",
     * on Toula's lists — colours, hover colours, typography, borders, radius,
     * padding, shadows and alignment for every widget. Written out per widget
     * they would be several hundred options; they are the same few properties
     * applied to a handful of kinds of thing, so they are GENERATED from this
     * table and `PROPS`. Every one is optional: empty (or 0) leaves the look
     * to the theme, so an existing site renders exactly as it did.
     *
     * The plugin maps each part to a selector inside its widget; the screen
     * draws the editors from the schema; the rules live only here.
     */
    private const PARTS = [
        'social_links' => ['box' => 'box', 'icon' => 'icon', 'label' => 'text'],
        'city_list' => ['box' => 'box', 'heading' => 'text', 'group' => 'box', 'link' => 'text', 'count' => 'text', 'search' => 'button', 'arrow' => 'icon', 'bullet' => 'icon'],
        'hours_table' => ['box' => 'box', 'title' => 'text', 'day' => 'text', 'time' => 'text', 'closed' => 'text', 'badge' => 'button'],
        'google_map' => ['box' => 'box', 'button' => 'button', 'address' => 'text'],
        'post_title' => ['title' => 'text'],
        'contact_info' => ['box' => 'box', 'item' => 'box', 'icon' => 'icon', 'label' => 'text', 'value' => 'text', 'day' => 'text', 'time' => 'text'],
        'cta' => ['box' => 'box', 'button' => 'button', 'icon' => 'icon'],
        'phone_box' => ['box' => 'box', 'icon' => 'icon', 'label' => 'text', 'number' => 'text'],
        'review_faces' => ['box' => 'box', 'title' => 'text', 'text' => 'text', 'button' => 'button'],
        'team' => ['box' => 'box', 'heading' => 'text', 'name' => 'text', 'role' => 'text', 'photo' => 'box'],
        'branches' => ['box' => 'box', 'card' => 'box', 'name' => 'text', 'button' => 'button'],
        'store_locator' => ['box' => 'box', 'card' => 'box', 'name' => 'text', 'link' => 'text'],
        'form' => ['box' => 'box', 'title' => 'text', 'subtitle' => 'text', 'label' => 'text', 'input' => 'button', 'submit' => 'button', 'consent' => 'text', 'help' => 'text'],
        'services' => ['box' => 'box', 'card' => 'box', 'image' => 'box', 'icon' => 'icon', 'title' => 'text', 'text' => 'text', 'button' => 'button'],
        'reviews' => ['box' => 'box', 'card' => 'box', 'name' => 'text', 'text' => 'text', 'date' => 'text', 'summary' => 'text'],
        'before_after' => ['box' => 'box', 'card' => 'box', 'title' => 'text', 'text' => 'text', 'label' => 'text'],
        'faq' => ['box' => 'box', 'item' => 'box', 'question' => 'text', 'answer' => 'text', 'icon' => 'icon', 'category' => 'text'],
    ];

    /**
     * What each kind of part can be given. `[type, …]`: a colour is a hex or
     * empty; a range is whole pixels (percent for line height) with 0 meaning
     * "not set"; a choice's first option is its default.
     */
    private const PROPS = [
        'text' => [
            'color' => ['color'],
            'hover_color' => ['color'],
            'font_family' => ['font'],
            'font_size' => ['range', 0, 96],
            'font_weight' => ['choice', ['', '300', '400', '500', '600', '700', '800', '900']],
            'text_transform' => ['choice', ['', 'none', 'uppercase', 'lowercase', 'capitalize']],
            'letter_spacing' => ['range', 0, 20],
            'line_height' => ['range', 0, 300],
        ],
        'box' => [
            'bg' => ['color'],
            'hover_bg' => ['color'],
            'border_color' => ['color'],
            'hover_border_color' => ['color'],
            'border_width' => ['range', 0, 20],
            'radius' => ['range', 0, 100],
            'padding' => ['range', 0, 120],
            // Top and bottom, and the sides, when they differ: A1's buttons are
            // usually 12 by 24.
            'padding_y' => ['range', 0, 120],
            'padding_x' => ['range', 0, 120],
            'shadow' => ['choice', ['', 'none', 'soft', 'strong']],
            'align' => ['choice', ['', 'left', 'center', 'right']],
            'devices' => ['choice', ['', 'desktop', 'mobile']],
        ],
        'icon' => [
            'color' => ['color'],
            'hover_color' => ['color'],
            'bg' => ['color'],
            'border_color' => ['color'],
            'border_width' => ['range', 0, 20],
            'size' => ['range', 0, 120],
            'padding' => ['range', 0, 60],
            'radius' => ['range', 0, 100],
        ],
        'button' => [
            'color' => ['color'],
            'bg' => ['color'],
            'hover_color' => ['color'],
            'hover_bg' => ['color'],
            'border_color' => ['color'],
            'border_width' => ['range', 0, 20],
            'radius' => ['range', 0, 100],
            'padding' => ['range', 0, 60],
            'padding_y' => ['range', 0, 60],
            'padding_x' => ['range', 0, 60],
            'shadow' => ['choice', ['', 'none', 'soft', 'strong']],
            'font_family' => ['font'],
            'font_size' => ['range', 0, 96],
            'font_weight' => ['choice', ['', '300', '400', '500', '600', '700', '800', '900']],
            'text_transform' => ['choice', ['', 'none', 'uppercase', 'lowercase', 'capitalize']],
            'letter_spacing' => ['range', 0, 20],
        ],
    ];

    /**
     * The generated style options of one widget, keyed `<part>_<property>`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function generated(string $widget): array
    {
        static $cache = [];

        if (isset($cache[$widget])) {
            return $cache[$widget];
        }

        $out = [];

        foreach (self::PARTS[$widget] ?? [] as $part => $kind) {
            foreach (self::PROPS[$kind] as $prop => $spec) {
                $rule = match ($spec[0]) {
                    'color' => ['type' => 'color', 'default' => ''],
                    'font' => ['type' => 'text', 'max' => 60, 'font' => true, 'default' => ''],
                    'range' => ['type' => 'range', 'min' => $spec[1], 'max' => $spec[2], 'default' => 0],
                    'choice' => ['type' => 'choice', 'choices' => $spec[1], 'default' => $spec[1][0]],
                };

                $out[$part.'_'.$prop] = $rule + ['part' => $part, 'prop' => $prop];
            }
        }

        return $cache[$widget] = $out;
    }

    /**
     * How one option is read: the same rule for a stored preset here and for
     * an override the plugin checks.
     *
     * @return array<string, mixed>
     */
    private static function rule(string $widget, string $key, mixed $default): array
    {
        if (isset(self::generated($widget)[$key])) {
            $rule = self::generated($widget)[$key];
            unset($rule['default']);

            return $rule;
        }

        return match (true) {
            in_array($key, self::COLORS[$widget] ?? [], true) => ['type' => 'color'],
            is_bool($default) => ['type' => 'bool'],
            isset(self::RANGES[$widget][$key]) => [
                'type' => 'range',
                'min' => self::RANGES[$widget][$key][0],
                'max' => self::RANGES[$widget][$key][1],
            ],
            isset(self::TEXT[$widget][$key]) => ['type' => 'text', 'max' => self::TEXT[$widget][$key]],
            default => ['type' => 'choice', 'choices' => self::CHOICES[$widget][$key] ?? [$default]],
        };
    }

    /**
     * The options each widget offers before generation. See `defaults()`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function base(): array
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
                // Toula's Social Links (01a0d8e6-c580): which networks and in
                // what order (empty is every one this site has, in EzBizi's
                // order), their brand colours or one colour, a new tab, and a
                // hover effect.
                'networks' => '',
                'colors' => 'single',
                'new_tab' => true,
                'hover' => 'none',
                // Toula's custom platforms: "Name | link | icon image link; …".
                'custom' => '',
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
                // Toula's City List (01a0d8e6-28cc) and A1's live lists: a
                // search box, the state after each town, bullets, a count per
                // group, the first group open, one open at a time, links in a
                // new tab, and what to say when a search finds nothing.
                'search' => false,
                'search_placeholder' => 'Search cities...',
                'show_state' => true,
                'bullets' => false,
                'count' => false,
                'open_first' => false,
                'one_open' => false,
                'new_tab' => false,
                'column_gap' => 28,
                'empty_text' => 'No cities match that search.',
                // A group's name as a heading of the page (A1 drew an h3, so
                // it took the theme's heading font), or `none` for a label.
                'heading_tag' => 'none',
                // Towns in no group, as a panel of their own when groups fold.
                'other_label' => 'Other areas',
            ],
            /*
             * `today` earns its default: the reason a visitor reads an hours
             * table at all is to find out whether the business is open now.
             */
            'hours_table' => [
                'today' => true,
                'compact' => false,
                // Toula's Opening Hours list (01a0d8e6-0598).
                'layout' => 'table',
                'title' => '',
                'show_closed' => true,
                'closed_text' => 'Closed',
                'clock' => '12',
                'days' => 'short',
                'status' => false,
                'open_text' => 'Open now',
                'shut_text' => 'Closed now',
                'dividers' => false,
                'divider_color' => '',
            ],
            'google_map' => [
                'height' => 320,
                'zoom' => 14,
                // Toula's Map list (01a0d8e6-e13a).
                'map_type' => 'roadmap',
                'grayscale' => false,
                'height_mobile' => 0,
                'directions' => false,
                'directions_text' => 'Get directions',
                'address' => false,
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
                // Toula's Contact Details list (01a0d8e6-6684) and what A1's
                // live info boxes use: hours and licence sections, the second
                // number and inbox, each section's own title (empty hides it),
                // their order, spacing, links and dividers.
                'hours' => false,
                'license' => false,
                'secondary' => false,
                'phone_title' => 'Phone',
                'email_title' => 'Email',
                'address_title' => 'Location',
                'hours_title' => 'Hours',
                'license_title' => 'License',
                'closed_text' => 'Closed',
                'order' => 'phone,email,address,hours,license',
                'icon_gap' => 10,
                'item_gap' => 18,
                'links' => true,
                'underline' => false,
                'dividers' => false,
                'divider_color' => '',
                // Toula: more numbers than two ("Label | number; …"), and an
                // icon of each section's choosing.
                'extra_phones' => '',
                'phone_icon' => 'auto',
                'email_icon' => 'auto',
                'address_icon' => 'auto',
                'hours_icon' => 'auto',
                'license_icon' => 'auto',
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
                // Toula's CTA list (suggestion 01a0d8e6-8d95): a custom link,
                // tracking on it, a new tab, the icon, an animation, and what
                // the button does on a phone.
                // `value` shows the number, address or link itself, as A1's
                // button did whenever nobody had typed its words.
                'label' => 'words',
                'url' => '',
                'url_params' => '',
                'new_tab' => false,
                'icon_type' => 'auto',
                'icon_position' => 'left',
                'icon_gap' => 8,
                'animation' => 'none',
                'animation_speed' => 2,
                'animate_on' => 'always',
                'glow_color' => '',
                'mobile' => 'same',
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
                // Toula's Phone list (01a0d8e6-a9ce): which number, what a tap
                // does, where the icon sits, and how the number is written.
                'which' => 'primary',
                'link_type' => 'call',
                'icon_position' => 'left',
                'format' => 'as_entered',
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
                'thanks' => "Thank you for letting us know, and we're sorry this wasn't the experience you expected. We have received your feedback and will investigate the issue. We may contact you to provide further support.",
                // After the private feedback is sent. A switch rather than
                // "leave it empty": an emptied box arrives as null and comes
                // back as this default, so empty cannot mean anything.
                'public_prompt' => 'If you like, you can also share your experience publicly on Google.',
                // Off: after a complaint the page apologises and stops (Jose, 2026-10-13).
                'ask_public' => false,
            ],
            'team' => [
                'columns' => 3,
                'photo' => 'square',
                'bio' => true,
            ],
            /*
             * The branches one website answers for. A1's live pages hide the
             * address and show a map, so both are switches; the default shows
             * everything, since a branch card with no address is the odd one.
             */
            'branches' => [
                'columns' => 3,
                'address' => true,
                'map' => true,
                'directions' => true,
            ],
            'store_locator' => [
                'search' => true,
                'images' => true,
                'maps' => true,
            ],
            'form' => [
                'field_gap' => 12,
                'button_width' => 'auto',
            ],
            'services' => [
                'layout' => 'grid',
                'columns' => 3,
                'image' => true,
                'icon' => true,
                'description' => true,
                'phone' => false,
                'category' => '',
                'button_text' => 'Learn more',
                'new_tab' => false,
            ],
            'reviews' => [
                'layout' => 'grid',
                'columns' => 3,
                'source' => 'auto',
                'limit' => 6,
                'min_rating' => 4,
                'stars' => true,
                'show_source' => true,
                'date' => true,
                'photo' => true,
                'excerpt' => 0,
                'summary' => false,
                'star_color' => '',
            ],
            'before_after' => [
                'layout' => 'slider',
                'columns' => 2,
                'title' => true,
                'description' => true,
                'category' => '',
                'before_label' => 'Before',
                'after_label' => 'After',
                'handle_color' => '',
            ],
            'post_title' => [
                // `none` prints the words alone, as it always has, so a title
                // sitting inside a heading is not wrapped in a second one.
                'tag' => 'none',
                'format' => 'title',
                'prefix' => '',
                'suffix' => '',
                'fallback' => '',
            ],
            'faq' => [
                'category' => '',
                'show_category' => false,
                'icon' => 'plus',
                'schema' => true,
                'open_first' => false,
                'one_open' => true,
            ],
        ];
    }

    /** The icons a Contact details section may wear; the plugin draws each. */
    private const CONTACT_ICONS = ['auto', 'phone', 'mobile', 'email', 'address', 'home', 'hours', 'calendar', 'license', 'star', 'chat', 'globe', 'arrow', 'none'];

    /** The values each option may take. Anything else becomes the default. */
    private const CHOICES = [
        'city_list' => [
            'layout' => ['columns', 'list', 'accordion'],
            'heading_tag' => ['none', 'h2', 'h3', 'h4', 'h5', 'h6'],
        ],
        'social_links' => [
            'style' => ['plain', 'solid', 'outline'],
            'shape' => ['circle', 'square', 'rounded'],
            'colors' => ['single', 'brand'],
            'hover' => ['none', 'fade', 'grow', 'lift'],
        ],
        'contact_info' => [
            'layout' => ['stack', 'row', 'compact', 'grid', 'inline'],
            'phone_icon' => self::CONTACT_ICONS,
            'email_icon' => self::CONTACT_ICONS,
            'address_icon' => self::CONTACT_ICONS,
            'hours_icon' => self::CONTACT_ICONS,
            'license_icon' => self::CONTACT_ICONS,
        ],
        'cta' => [
            // `bar` is fixed to the bottom of the window, `popup` opens over
            // the page once per visit, `inline` sits where it was pasted.
            'placement' => ['inline', 'bar', 'popup'],
            'action' => ['call', 'book', 'form', 'email', 'review', 'url', 'sms', 'whatsapp'],
            'icon_type' => ['auto', 'phone', 'email', 'calendar', 'star', 'arrow', 'chat', 'none'],
            'label' => ['words', 'value'],
            'icon_position' => ['left', 'right'],
            'animation' => ['none', 'pulse', 'shake', 'bounce', 'glow'],
            'animate_on' => ['always', 'hover'],
            'mobile' => ['same', 'bar', 'hide'],
        ],
        'phone_box' => [
            'size' => ['small', 'medium', 'large'],
            'which' => ['primary', 'secondary'],
            'link_type' => ['call', 'sms', 'whatsapp'],
            'icon_position' => ['left', 'right', 'top'],
            'format' => ['as_entered', 'dashes', 'dots', 'parentheses'],
        ],
        'hours_table' => [
            'layout' => ['table', 'stack', 'inline'],
            'clock' => ['12', '24'],
            'days' => ['short', 'long'],
        ],
        'google_map' => [
            'map_type' => ['roadmap', 'satellite', 'hybrid', 'terrain'],
        ],
        'post_title' => [
            'tag' => ['none', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div'],
            'format' => ['title', 'city', 'city_state', 'address'],
        ],
        'review_faces' => [
            'tone' => ['light', 'dark'],
        ],
        'form' => [
            'button_width' => ['auto', 'full'],
        ],
        'services' => ['layout' => ['grid', 'list']],
        // `auto`: the hand-picked reviews when there are any, Google's when not.
        'reviews' => ['layout' => ['grid', 'list', 'slider'], 'source' => ['auto', 'featured', 'google']],
        'before_after' => ['layout' => ['slider', 'side']],
        'faq' => ['icon' => ['plus', 'chevron']],
        'team' => [
            'photo' => ['square', 'circle'],
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
        'cta' => ['text' => 80, 'url' => 500, 'url_params' => 200],
        'phone_box' => ['label' => 60],
        'city_list' => ['search_placeholder' => 60, 'empty_text' => 80, 'other_label' => 40],
        'services' => ['category' => 60, 'button_text' => 40],
        'before_after' => ['category' => 60, 'before_label' => 30, 'after_label' => 30],
        'faq' => ['category' => 60],
        'hours_table' => ['title' => 80, 'closed_text' => 30, 'open_text' => 30, 'shut_text' => 30],
        'google_map' => ['directions_text' => 40],
        'post_title' => ['prefix' => 80, 'suffix' => 80, 'fallback' => 120],
        'social_links' => ['networks' => 300, 'custom' => 1000],
        'contact_info' => [
            'phone_title' => 40, 'email_title' => 40, 'address_title' => 40, 'hours_title' => 40,
            'license_title' => 40, 'closed_text' => 30, 'order' => 60, 'extra_phones' => 500,
        ],
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
        'city_list' => ['columns' => [1, 6], 'column_gap' => [0, 80]],
        'google_map' => ['height' => [120, 900], 'zoom' => [1, 20], 'height_mobile' => [0, 900]],
        'review_faces' => ['face_size' => [40, 120]],
        'team' => ['columns' => [1, 6]],
        'branches' => ['columns' => [1, 4]],
        'cta' => ['icon_gap' => [0, 40], 'animation_speed' => [1, 10]],
        'contact_info' => ['icon_gap' => [0, 40], 'item_gap' => [0, 80]],
        'form' => ['field_gap' => [0, 40]],
        'services' => ['columns' => [1, 4]],
        'reviews' => ['columns' => [1, 4], 'limit' => [1, 50], 'min_rating' => [1, 5], 'excerpt' => [0, 1000]],
        'before_after' => ['columns' => [1, 4]],
    ];

    /** Hand-written options that are a colour: a hex, or empty for the site's accent. */
    private const COLORS = [
        'cta' => ['glow_color'],
        'contact_info' => ['divider_color'],
        'reviews' => ['star_color'],
        'before_after' => ['handle_color'],
        'hours_table' => ['divider_color'],
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

            $out[$key] = $value === null
                ? $default
                : self::read(self::rule($widget, $key, $default), $value, $default);
        }

        return $out;
    }

    /**
     * One value, by its rule. Anything that does not fit becomes the default:
     * this ends up in a `<style>` block on somebody's live page.
     */
    private static function read(array $rule, mixed $value, mixed $default): mixed
    {
        switch ($rule['type']) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;

            case 'range':
                return is_numeric($value) ? max($rule['min'], min($rule['max'], (int) $value)) : $default;

            case 'color':
                $value = strtolower(trim((string) $value));

                return preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value) === 1 ? $value : '';

            case 'text':
                $clean = trim(strip_tags((string) $value));
                $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

                if (! empty($rule['font'])) {
                    // A font name reaches CSS unquoted-safe: letters, digits,
                    // spaces, commas, hyphens and quotes, nothing that closes a rule.
                    $clean = preg_replace('/[^A-Za-z0-9 ,\'"-]/', '', $clean) ?? '';
                }

                return mb_substr($clean, 0, $rule['max']);

            default:
                return in_array($value, $rule['choices'], true) ? $value : $default;
        }
    }

    /**
     * What each option IS, for the plugin to check a per-widget override by.
     *
     * Jose, 2026-09-25: "company wide preset but allowing per widget
     * override". The preset stays the default; a placed widget may change any
     * of its options, as a shortcode attribute or an Elementor control. The
     * override never passes through this server — it is typed on the customer's
     * site — so the plugin needs the same rules `one()` applies here, and this
     * is them, sent rather than copied so they cannot drift.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function schema(): array
    {
        $out = [];

        foreach (self::defaults() as $widget => $defaults) {
            foreach ($defaults as $key => $default) {
                $out[$widget][$key] = self::rule($widget, $key, $default);
            }
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
