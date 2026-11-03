<?php
/**
 * The widgets that render a value as something other than a line of text: a
 * row of social links, an embedded map, the page's own title, an hours table,
 * and the towns a site covers.
 *
 * ── Why these take an argument when [ezbizi_chat] takes none ──
 *
 * `enquiries.php` states the rule and the rule still holds: a site owner who
 * could set the colours in the shortcode would have two places to set them and
 * no way to tell which won. What travels here is a NAME, not a value.
 * `preset="footer"` picks a bundle that is still edited once, in EzBizi, and
 * still reaches every site at once — so the property that made the
 * no-arguments rule worth having survives. It has to: the same row of icons
 * belongs small and round in a footer and large and labelled in a contact
 * panel, and one bundle per widget cannot be both. See
 * `App\Support\Web\WidgetPresets` for the other half of this.
 *
 * ── Why a refused token does not hide any of these ──
 *
 * `ezbizi_widget_config()` returns nothing while EzBizi is refusing this site,
 * and the chat and the form vanish with it, because a chat box is a promise
 * that somebody is listening. Every widget in this file renders a published
 * FACT — a phone number, a town, a set of opening hours — and this plugin has
 * always gone on publishing those from the last good read. So these read the
 * payload directly rather than through that gate, and that difference is the
 * whole reason they are not in `enquiries.php`.
 *
 * ── Why the shortcode names are what they are ──
 *
 * `ezbizi.php` registers `[ezbizi_<key>]` for every key the server names, and
 * that loop runs after this file's. A catalogue key called `city_list` would
 * therefore silently replace `[ezbizi_city_list]` with a plain string. None of
 * the five names below is a key in `SeoFields`, and a page field is named
 * `<page>_field_<n>`, so the collision cannot happen today — it is worth
 * knowing before somebody adds a field called `post_title`.
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The presentation a widget renders with: the preset somebody named, or the
 * default.
 *
 * An unknown name resolves to the default rather than to nothing. This is
 * somebody's live page and `preset="foter"` must not blank a footer — the same
 * guarantee `WidgetPresets::resolve()` makes at the other end, restated here
 * because the payload can be older than the server that is running.
 */
/**
 * A text option written one entry per line (or `;`), each entry `a | b | c`.
 *
 * @return list<list<string>> entries, each its trimmed parts; blank lines dropped
 */
function ezbizi_preset_lines($preset, $key) {
    $out = array();

    foreach (preg_split('/[\r\n;]+/', isset($preset[$key]) ? (string) $preset[$key] : '') as $line) {
        $parts = array_map('trim', explode('|', $line));

        if ($parts[0] !== '') {
            $out[] = $parts;
        }
    }

    return $out;
}

function ezbizi_preset($widget, $atts) {
    // Kept whole for the overrides; `shortcode_atts` below keeps only `preset`.
    $given = is_array($atts) ? $atts : array();
    $atts = shortcode_atts(
        array('preset' => ''),
        is_array($atts) ? $atts : array(),
        'ezbizi_' . $widget
    );

    $name = ezbizi_preset_name($atts['preset']);
    $payload = ezbizi_payload();

    $stored = isset($payload['widgets']['presets'][$widget])
        && is_array($payload['widgets']['presets'][$widget])
            ? $payload['widgets']['presets'][$widget]
            : array();

    $chosen = array();

    if ($name !== '' && isset($stored[$name]) && is_array($stored[$name])) {
        $chosen = $stored[$name];
    } elseif (isset($stored['default']) && is_array($stored['default'])) {
        $chosen = $stored['default'];
    }

    return ezbizi_apply_overrides($widget, array_merge(ezbizi_preset_fallback($widget), $chosen), $given);
}

/**
 * The style options of a widget as CSS on its own parts.
 *
 * Jose, 2026-09-25: "i want all the options, for all the widgets". The
 * options are generated server-side as `<part>_<property>` (WidgetPresets
 * PARTS × PROPS) and described in `widgets.schema`; this turns whichever are
 * set into rules on the selector the widget gives each part. Nothing set,
 * nothing printed — a site that never touches them renders as before.
 *
 * The id is doubled (`#x#x`) so a rule here outranks the widget's own base
 * style without `!important`. Every value is re-checked against its rule
 * before it is printed, since a preset from an older payload never passed
 * the override checks.
 *
 * @param array $parts part => selector suffix inside the widget, '' for the widget itself
 */
function ezbizi_style_css($widget, $id, $p, $parts) {
    $payload = ezbizi_payload();
    $rules = isset($payload['widgets']['schema'][$widget]) && is_array($payload['widgets']['schema'][$widget])
        ? $payload['widgets']['schema'][$widget]
        : array();

    $css = array();
    $media = array();
    $scope = '#' . $id . '#' . $id;

    foreach ($rules as $key => $rule) {
        if (! is_array($rule) || empty($rule['part']) || ! isset($parts[$rule['part']], $p[$key])) {
            continue;
        }

        $value = $p[$key];
        $prop = isset($rule['prop']) ? $rule['prop'] : '';
        $type = isset($rule['type']) ? $rule['type'] : '';

        if ($value === '' || $value === 0 || $value === '0' || $value === null || $value === false) {
            continue;
        }

        if ($type === 'color' && ! preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $value)) {
            continue;
        }

        if ($type === 'range') {
            $value = max((int) $rule['min'], min((int) $rule['max'], (int) $value));
        }

        if ($type === 'choice' && ! in_array((string) $value, isset($rule['choices']) ? (array) $rule['choices'] : array(), true)) {
            continue;
        }

        $sel = $scope . $parts[$rule['part']];
        $hover = $sel . ':hover';

        switch ($prop) {
            case 'color':
                $css[$sel][] = 'color:' . $value;
                break;
            case 'hover_color':
                $css[$hover][] = 'color:' . $value;
                break;
            case 'bg':
                $css[$sel][] = 'background:' . $value;
                break;
            case 'hover_bg':
                $css[$hover][] = 'background:' . $value;
                break;
            case 'border_color':
                $css[$sel][] = 'border-color:' . $value;
                break;
            case 'hover_border_color':
                $css[$hover][] = 'border-color:' . $value;
                break;
            case 'border_width':
                $css[$sel][] = 'border-width:' . $value . 'px;border-style:solid';
                break;
            case 'radius':
                $css[$sel][] = 'border-radius:' . $value . 'px';
                break;
            case 'padding':
                $css[$sel][] = 'padding:' . $value . 'px';
                break;
            case 'padding_y':
                $css[$sel][] = 'padding-top:' . $value . 'px;padding-bottom:' . $value . 'px';
                break;
            case 'padding_x':
                $css[$sel][] = 'padding-left:' . $value . 'px;padding-right:' . $value . 'px';
                break;
            case 'font_family':
                $family = preg_replace('/[^A-Za-z0-9 ,\'"-]/', '', (string) $value);
                if ($family !== '') {
                    $css[$sel][] = 'font-family:' . $family;
                }
                break;
            case 'font_size':
                $css[$sel][] = 'font-size:' . $value . 'px';
                break;
            case 'font_weight':
                $css[$sel][] = 'font-weight:' . $value;
                break;
            case 'text_transform':
                $css[$sel][] = 'text-transform:' . $value;
                break;
            case 'letter_spacing':
                $css[$sel][] = 'letter-spacing:' . $value . 'px';
                break;
            case 'line_height':
                $css[$sel][] = 'line-height:' . round($value / 100, 2);
                break;
            case 'size':
                $css[$sel][] = 'font-size:' . $value . 'px';
                $css[$sel . ' svg'][] = 'width:' . $value . 'px;height:' . $value . 'px';
                break;
            case 'shadow':
                $css[$sel][] = 'box-shadow:' . ($value === 'strong' ? '0 6px 24px rgba(0,0,0,.28)' : ($value === 'soft' ? '0 2px 10px rgba(0,0,0,.12)' : 'none'));
                break;
            case 'align':
                $css[$sel][] = 'text-align:' . $value . ';justify-content:' . ($value === 'left' ? 'flex-start' : ($value === 'right' ? 'flex-end' : 'center'));
                break;
            case 'devices':
                // Shown on one kind of screen only: hidden on the other.
                $media[$value === 'desktop' ? '(max-width:767px)' : '(min-width:768px)'][] = $sel . '{display:none}';
                break;
        }
    }

    if ($css === array() && $media === array()) {
        return '';
    }

    $out = '<style>';

    foreach ($css as $sel => $decls) {
        $out .= $sel . '{' . implode(';', $decls) . '}';
    }

    foreach ($media as $query => $blocks) {
        $out .= '@media ' . $query . '{' . implode('', $blocks) . '}';
    }

    return $out . '</style>';
}

/**
 * This widget's own changes on top of its preset.
 *
 * Jose, 2026-09-25: "company wide preset but allowing per widget override".
 * An override is a shortcode attribute named like the option
 * (`[ezbizi_social_links size="40" labels="yes"]`) or, in Elementor, the
 * widget's "This widget only" controls, which arrive here the same way.
 *
 * Checked against the rules EzBizi sends in `widgets.schema`, never trusted:
 * this is typed on the page, not saved through EzBizi, and it ends up in a
 * `<style>` block. An option EzBizi does not describe is ignored, and a value
 * outside its rule is dropped, so a typo keeps the preset's value rather than
 * breaking the page. A payload from before the schema existed allows nothing.
 */
function ezbizi_apply_overrides($widget, $preset, $atts) {
    $payload = ezbizi_payload();
    $rules = isset($payload['widgets']['schema'][$widget]) && is_array($payload['widgets']['schema'][$widget])
        ? $payload['widgets']['schema'][$widget]
        : array();

    if (! is_array($atts) || $rules === array()) {
        return $preset;
    }

    foreach ($rules as $key => $rule) {
        if (! isset($atts[$key]) || ! is_scalar($atts[$key]) || trim((string) $atts[$key]) === '' || ! is_array($rule)) {
            continue;
        }

        $raw = trim((string) $atts[$key]);

        switch (isset($rule['type']) ? $rule['type'] : '') {
            case 'bool':
                $read = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($read !== null) {
                    $preset[$key] = $read;
                }
                break;
            case 'range':
                if (is_numeric($raw)) {
                    $preset[$key] = max((int) $rule['min'], min((int) $rule['max'], (int) $raw));
                }
                break;
            case 'choice':
                if (in_array($raw, isset($rule['choices']) ? (array) $rule['choices'] : array(), true)) {
                    $preset[$key] = $raw;
                }
                break;
            case 'color':
                $raw = strtolower($raw);
                if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $raw)) {
                    $preset[$key] = $raw;
                }
                break;
            case 'text':
                $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));
                if (! empty($rule['font'])) {
                    $clean = (string) preg_replace('/[^A-Za-z0-9 ,\'"-]/', '', $clean);
                }
                $preset[$key] = function_exists('mb_substr')
                    ? mb_substr($clean, 0, (int) (isset($rule['max']) ? $rule['max'] : 80))
                    : substr($clean, 0, (int) (isset($rule['max']) ? $rule['max'] : 80));
                break;
        }
    }

    return $preset;
}

/**
 * A preset name, narrowed to what the server stores one as.
 *
 * Lowercase, letters, digits, hyphen and underscore, 32 characters — the same
 * alphabet as `WidgetPresets::name()`. Matching it is not decoration: a name
 * typed in the shortcode has to come out of this function looking like the one
 * that came out of that one, or `preset="Footer"` finds nothing and the page
 * quietly shows the default instead of what somebody asked for.
 */
function ezbizi_preset_name($raw) {
    $clean = strtolower(trim((string) $raw));
    $clean = preg_replace('/[^a-z0-9_-]/', '', $clean);

    return substr(is_string($clean) ? $clean : '', 0, 32);
}

/**
 * The geometry a widget falls back on when the payload carries no presets at
 * all.
 *
 * ── Why this invents defaults where `ezbizi_widget_config()` refuses to ──
 *
 * That function refuses because the chat's config is somebody's BRAND — their
 * accent colour, their greeting — and a guess at those is a wrong answer
 * printed in their name. These are numbers with no customer-specific truth in
 * them: a 28-pixel icon is nobody's decision, and a site whose last good read
 * predates the presets would otherwise render an empty footer for up to an
 * hour after upgrading, for want of a gap size. The values are copied from
 * `WidgetPresets::defaults()`; the server's answer wins whenever there is one.
 */
function ezbizi_preset_fallback($widget) {
    $fallbacks = array(
        'social_links' => array(
            'style' => 'plain',
            'shape' => 'circle',
            'size' => 28,
            'gap' => 10,
            'labels' => false,
        ),
        'city_list' => array(
            'layout' => 'columns',
            'columns' => 3,
            'group' => true,
            'link' => true,
        ),
        'hours_table' => array(
            'today' => true,
            'compact' => false,
        ),
        'google_map' => array(
            'height' => 320,
            'zoom' => 14,
        ),
    );

    // Copied from WidgetPresets::defaults(), like the ones above.
    $fallbacks['team'] = array('columns' => 3, 'photo' => 'square', 'bio' => true);
    $fallbacks['services'] = array('layout' => 'grid', 'columns' => 3, 'image' => true, 'icon' => true, 'description' => true, 'phone' => false, 'category' => '', 'button_text' => 'Learn more', 'new_tab' => false);
    $fallbacks['reviews'] = array('layout' => 'grid', 'columns' => 3, 'source' => 'auto', 'limit' => 6, 'min_rating' => 4, 'stars' => true, 'show_source' => true, 'date' => true, 'photo' => true, 'excerpt' => 0, 'summary' => false, 'star_color' => '');
    $fallbacks['before_after'] = array('layout' => 'slider', 'columns' => 2, 'title' => true, 'description' => true, 'category' => '', 'before_label' => 'Before', 'after_label' => 'After', 'handle_color' => '');
    $fallbacks['faq'] = array('category' => '', 'show_category' => false, 'icon' => 'plus', 'schema' => true, 'open_first' => false, 'one_open' => true);
    $fallbacks['branches'] = array('columns' => 3, 'address' => true, 'map' => true, 'directions' => true);
    $fallbacks['store_locator'] = array('search' => true, 'images' => true, 'maps' => true);

    // ponytail: copied from WidgetPresets::defaults(), like the four above.
    $fallbacks['review_faces'] = array(
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
        'public_prompt' => 'If you like, you can also share your experience publicly on Google.',
        // Off: after a complaint the page apologises and stops (Jose, 2026-10-13).
        'ask_public' => false,
    );

    return isset($fallbacks[$widget]) ? $fallbacks[$widget] : array();
}

/**
 * The palette EzBizi already holds for this site.
 *
 * Read straight from the payload rather than through `ezbizi_widget_config()`,
 * which answers for one widget and returns nothing on a refused site. A row of
 * icons in the customer's own accent is one place to set a colour; an icon
 * colour of its own in every preset would be a second.
 */
function ezbizi_widget_palette() {
    $payload = ezbizi_payload();

    return isset($payload['widgets']['style']) && is_array($payload['widgets']['style'])
        ? $payload['widgets']['style']
        : array();
}

// ── Social links ────────────────────────────────────────────────────

/**
 * The networks this site could publish, in the order EzBizi lists them.
 *
 * Taken from the SERVER's field catalogue — every entry marked `social` — and
 * not from a list in this file, for the reason stated at the top of
 * `ezbizi.php`: a sixteenth network added in EzBizi renders here at the next
 * refresh with nothing installed. It also means the label beside a labelled
 * icon is the one the screen shows, rather than a second spelling of it.
 */
function ezbizi_social_networks() {
    $payload = ezbizi_payload();
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
    $out = array();

    foreach ($fields as $field) {
        if (! is_array($field) || ! isset($field['key'], $field['section'])) {
            continue;
        }

        if ($field['section'] !== 'social') {
            continue;
        }

        $key = (string) $field['key'];
        $out[$key] = isset($field['label']) ? (string) $field['label'] : $key;
    }

    return $out;
}

/**
 * [ezbizi_social_links] — the links the company has, as a row.
 *
 * Only the networks with a value are drawn. A business with no TikTok is not a
 * gap in a row of fifteen; it is a business with no TikTok.
 */
function ezbizi_social_links_shortcode($atts) {
    $preset = ezbizi_preset('social_links', $atts);
    $links = array();
    $networks = ezbizi_social_networks();

    // Which networks, in which order: "facebook,instagram". Empty is every one.
    $chosen = array_values(array_filter(array_map('trim', explode(',', strtolower(
        isset($preset['networks']) ? (string) $preset['networks'] : ''
    )))));

    if ($chosen !== array()) {
        $networks = array_intersect_key(array_replace(array_flip($chosen), $networks), $networks);
        $networks = array_intersect_key($networks, array_flip($chosen));
    }

    foreach ($networks as $key => $label) {
        /*
         * `esc_url` is the gate AND the filter. A value that is not a web
         * address — `javascript:`, or something somebody pasted into the wrong
         * box — comes back empty, and a link with no href is worse than no
         * link at all, so it is dropped rather than drawn.
         */
        $href = esc_url(ezbizi_value($key));

        if ($href === '') {
            continue;
        }

        $links[] = array('key' => $key, 'label' => $label, 'href' => $href);
    }

    // Toula's custom platforms: "Name | link | icon image" per line, the icon optional.
    foreach (ezbizi_preset_lines($preset, 'custom') as $i => $parts) {
        $href = esc_url(isset($parts[1]) ? $parts[1] : '');

        if ($parts[0] !== '' && $href !== '') {
            $links[] = array('key' => 'custom-' . ($i + 1), 'label' => $parts[0], 'href' => $href,
                'icon' => esc_url(isset($parts[2]) ? $parts[2] : ''));
        }
    }

    if ($links === array()) {
        return '';
    }

    $labels = ! empty($preset['labels']);
    $id = ezbizi_widget_id('ezbizi-social');

    $out = ezbizi_social_style($id, $preset);
    $out .= '<div class="ezbizi-social" id="' . esc_attr($id) . '">';

    foreach ($links as $link) {
        $out .= '<a class="ezbizi-social-link ezbizi-social-' . esc_attr($link['key']) . '"'
            . ' href="' . $link['href'] . '"'
            /*
             * `noopener` for the window this opens and `noreferrer` with it:
             * both are named because `target="_blank"` hands the new tab a
             * handle on this one, and an old browser that ignores the first
             * honours the second.
             */
            . (! isset($preset['new_tab']) || ! empty($preset['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '')
            // The only name a screen reader gets when `labels` is off.
            . ' aria-label="' . esc_attr($link['label']) . '"'
            . ' title="' . esc_attr($link['label']) . '">'
            . (! empty($link['icon'])
                ? '<img src="' . $link['icon'] . '" alt="" loading="lazy" />'
                : ezbizi_social_icon($link['key'], $link['label']))
            . ($labels ? '<span class="ezbizi-social-label">' . esc_html($link['label']) . '</span>' : '')
            . '</a>';
    }

    return $out . '</div>' . ezbizi_social_extras($id, $preset, $links) . ezbizi_style_css('social_links', $id, $preset, array(
        'box' => '', 'icon' => ' .ezbizi-social-link', 'label' => ' .ezbizi-social-label',
    ));
}

/**
 * Brand colours and the hover effect.
 *
 * Brand mode paints each network its own colour — filled for `solid`, the
 * icon itself otherwise — which is the one per-network colour anybody asked
 * for; a colour per network by hand would be fifteen more options for the
 * same answer.
 */
function ezbizi_social_extras($id, $preset, $links) {
    $brands = array(
        'facebook' => '#1877F2', 'instagram' => '#E4405F', 'youtube' => '#FF0000', 'twitter' => '#000000',
        'linkedin' => '#0A66C2', 'tiktok' => '#000000', 'yelp' => '#D32323', 'google_business' => '#4285F4',
        'pinterest' => '#E60023', 'bbb' => '#005A78', 'nextdoor' => '#8ED500', 'houzz' => '#4DBC15',
        'angi' => '#FF6153', 'thumbtack' => '#009FD9', 'homeadvisor' => '#F68315',
    );
    $s = '#' . $id;
    $css = '';

    if (isset($preset['colors']) && $preset['colors'] === 'brand') {
        $solid = isset($preset['style']) && $preset['style'] === 'solid';

        foreach ($links as $link) {
            if (isset($brands[$link['key']])) {
                $css .= $s . ' .ezbizi-social-' . $link['key'] . ($solid
                    ? '{background:' . $brands[$link['key']] . ';color:#fff;border-color:' . $brands[$link['key']] . '}'
                    : '{color:' . $brands[$link['key']] . ';border-color:' . $brands[$link['key']] . '}');
            }
        }
    }

    $hover = isset($preset['hover']) ? (string) $preset['hover'] : 'none';
    $effects = array(
        'fade' => 'opacity:.7',
        'grow' => 'transform:scale(1.12)',
        'lift' => 'transform:translateY(-3px)',
    );

    if (isset($effects[$hover])) {
        $css .= $s . ' .ezbizi-social-link{transition:opacity .15s,transform .15s}'
            . $s . ' .ezbizi-social-link:hover{' . $effects[$hover] . '}';
    }

    return $css === '' ? '' : '<style>' . $css . '</style>';
}

/**
 * One network's mark, drawn inline.
 *
 * ── Why these are copied, and some are letters ──
 *
 * No icon font and no CDN, so every glyph is markup in this file. A
 * hand-recalled approximation of a trademarked logo is wrong in a way a letter
 * never is: it looks like the brand and is not it, on 110 sites we do not own.
 * So the marks are the networks' own paths, copied verbatim from Simple Icons
 * (CC0), which A1's pages showed as logos too; a network it has no mark for
 * keeps its initial.
 *
 * Everything is `currentColor`, with no knocked-out white anywhere, so one
 * glyph works plain, solid and outlined without three copies of it. The box is
 * sized in CSS rather than here, so `size` does not have to be threaded
 * through every glyph to be honoured.
 */
function ezbizi_social_icon($key, $label) {
    // The networks' own marks, exactly: Simple Icons (CC0), not drawn here.
    // A network without one keeps its letters.
    $marks = array(
        'facebook' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z',
        'instagram' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077',
        'youtube' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        'twitter' => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z',
        'linkedin' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        'tiktok' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        'yelp' => 'm7.6885 15.1415-3.6715.8483c-.3769.0871-.755.183-1.1452.155-.2611-.0188-.5122-.0414-.7606-.213a1.179 1.179 0 0 1-.331-.3594c-.3486-.5519-.3656-1.3661-.3697-2.0004a6.2874 6.2874 0 0 1 .3314-2.0642 1.857 1.857 0 0 1 .1073-.2474 2.3426 2.3426 0 0 1 .1255-.2165 2.4572 2.4572 0 0 1 .1563-.1975 1.1736 1.1736 0 0 1 .399-.2831 1.082 1.082 0 0 1 .4592-.0837c.2355.0016.5139.052.91.1734.0555.0191.1237.0382.1856.0572.3277.1013.7048.2404 1.1499.3987.6863.2404 1.3663.487 2.0463.7397l1.2117.4423c.2217.0807.4363.18.6412.297.174.0984.3273.2298.4512.387a1.217 1.217 0 0 1 .192.4309 1.2205 1.2205 0 0 1-.872 1.4522c-.0468.0151-.0852.0239-.1085.0293l-1.105.2553-.0031-.001zM18.8208 7.565a1.8506 1.8506 0 0 0-.2042-.1754 2.4082 2.4082 0 0 0-.2077-.1394 2.3607 2.3607 0 0 0-.2269-.109 1.1705 1.1705 0 0 0-.482-.0796 1.0862 1.0862 0 0 0-.4498.1263c-.2107.1048-.4388.2732-.742.5551-.042.0417-.0947.0886-.142.133-.2502.2351-.5286.5252-.8599.863a114.6363 114.6363 0 0 0-1.5166 1.5629l-.8962.9293a4.1897 4.1897 0 0 0-.4466.5483 1.541 1.541 0 0 0-.2364.5459 1.2199 1.2199 0 0 0 .0107.4518l.0046.02a1.218 1.218 0 0 0 1.4184.923 1.162 1.162 0 0 0 .1105-.0213l4.7781-1.104c.3766-.087.7587-.1667 1.097-.3631.2269-.1316.4428-.262.5909-.5252a1.1793 1.1793 0 0 0 .1405-.4683c.0733-.6512-.2668-1.3908-.5403-1.963a6.2792 6.2792 0 0 0-1.2001-1.7103zM8.9703.0754a8.6724 8.6724 0 0 0-.83.1564c-.2754.066-.548.1383-.8146.2236-.868.2844-2.0884.8063-2.295 1.8065-.1165.5655.1595 1.1439.3737 1.66.2595.6254.614 1.1889.9373 1.7777.8543 1.5545 1.7245 3.0993 2.5922 4.6457.259.4617.5416 1.0464 1.043 1.2856a1.058 1.058 0 0 0 .1013.0383c.2248.0851.4699.1016.7041.0471a4.3015 4.3015 0 0 0 .0418-.0097 1.2136 1.2136 0 0 0 .5658-.3397 1.1033 1.1033 0 0 0 .079-.0822c.3463-.435.3454-1.0833.3764-1.6134.1042-1.771.2139-3.5423.3009-5.3142.0332-.6712.1055-1.3333.0655-2.0096-.0328-.5579-.0368-1.1984-.3891-1.6563-.6218-.8073-1.9476-.741-2.8523-.6158zm2.084 15.9505a1.1053 1.1053 0 0 0-1.2306-.4145 1.1398 1.1398 0 0 0-.1526.0633 1.4806 1.4806 0 0 0-.2171.1354c-.1992.1475-.3668.3392-.5196.5315-.0386.049-.074.1143-.12.1562l-.7686 1.0573a113.9168 113.9168 0 0 0-1.2913 1.789c-.278.3895-.5184.7184-.7083 1.0094-.036.0547-.0734.116-.1075.1647-.2277.3522-.3566.6092-.4228.8381a1.0945 1.0945 0 0 0-.046.4721c.0211.1655.0768.3246.1635.467.046.0715.0957.1406.1487.207a2.334 2.334 0 0 0 .1754.1825 1.843 1.843 0 0 0 .2108.1732c.5304.369 1.1112.6342 1.722.8391a6.0958 6.0958 0 0 0 1.5716.3004c.091.0046.1821.0025.2728-.006a2.3878 2.3878 0 0 0 .2506-.0351 2.3862 2.3862 0 0 0 .2447-.071 1.1927 1.1927 0 0 0 .4175-.2658c.1127-.113.1994-.249.2541-.3989.0889-.2214.1473-.5026.1857-.92.0034-.0593.0118-.1305.0177-.1958.0304-.3463.0443-.7531.0666-1.2315.0375-.7357.067-1.4681.0903-2.2026 0 0 .0495-1.3053.0494-1.306.0113-.3008.002-.6342-.0814-.9336a1.396 1.396 0 0 0-.1756-.4054zm8.6754 2.0439c-.1605-.176-.3878-.3514-.7462-.5682-.0518-.0288-.1124-.0674-.1684-.1009-.2985-.1795-.658-.3684-1.078-.5965a120.7615 120.7615 0 0 0-1.9427-1.042l-1.1515-.6107c-.0597-.0175-.1203-.0607-.1766-.0878-.2212-.1058-.4558-.2045-.6992-.2498a1.4915 1.4915 0 0 0-.2545-.0265 1.1527 1.1527 0 0 0-.1648.01 1.1077 1.1077 0 0 0-.9227.9133 1.4186 1.4186 0 0 0 .0159.439c.0563.3065.1932.6096.3346.875l.615 1.1526c.3422.65.6884 1.2963 1.0435 1.9406.229.4202.4196.7799.5982 1.078.0338.056.0721.1163.1011.1682.2173.3584.392.584.569.7458.1146.1107.252.195.4026.247.1583.0525.326.071.4919.0546a2.368 2.368 0 0 0 .251-.0435c.0817-.022.1622-.048.241-.0784a1.863 1.863 0 0 0 .2475-.1143 6.1018 6.1018 0 0 0 1.2818-.9597c.4596-.4522.8659-.9454 1.182-1.51.044-.08.0819-.163.1138-.2483a2.49 2.49 0 0 0 .0773-.2411c.0186-.083.033-.1669.0429-.2513a1.188 1.188 0 0 0-.0565-.491 1.0933 1.0933 0 0 0-.248-.4041zm2.86 3.742a.8523.8523 0 0 1-.111.4236c-.074.132-.178.2377-.3115.3172a.8428.8428 0 0 1-.4385.119.847.847 0 0 1-.4373-.1179.8526.8526 0 0 1-.3125-.3171.8548.8548 0 0 1-.111-.4248c0-.1526.038-.2958.1143-.4294a.8405.8405 0 0 1 .315-.3159.849.849 0 0 1 .4315-.1156.8514.8514 0 0 1 .4294.1144.84.84 0 0 1 .316.3148.8494.8494 0 0 1 .1156.4317zm-.1202 0c0-.1328-.0332-.256-.0996-.3698s-.1564-.2038-.2702-.2702a.7125.7125 0 0 0-.371-.1007.7204.7204 0 0 0-.3698.0996.7487.7487 0 0 0-.2713.2702.7181.7181 0 0 0-.0996.3709c0 .132.0332.2557.0996.371a.7355.7355 0 0 0 .2713.2713.7354.7354 0 0 0 .3698.0985.7205.7205 0 0 0 .3698-.0996.7423.7423 0 0 0 .2702-.2691.7186.7186 0 0 0 .1008-.3721zm-.577.0584.2724.4522h-.1922l-.237-.4052h-.1546v.4052h-.1695v-1.02h.2988c.1268 0 .2195.0247.2783.0744.0595.0496.0892.1252.0892.2267a.2785.2785 0 0 1-.0492.1625c-.032.0466-.0775.0813-.1362.1042zm-.0412-.1408a.1532.1532 0 0 0 .056-.1214c0-.0573-.0164-.0981-.0491-.1225-.0329-.0251-.0847-.0377-.1557-.0377h-.1214v.3285h.1237c.061 0 .1098-.0157.1465-.047z',
        'google_business' => 'M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z',
        'pinterest' => 'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z',
        'thumbtack' => 'M6.18 6.38h11.69v2.68H6.17zm7.27 3.8v3.14c0 3.23-.02 3.74-.14 4.36a7.95 7.95 0 0 1-1.3 2.87c-.03 0-.78-1.35-.9-1.62-.17-.4-.3-.8-.4-1.25l-.09-.41-.02-5.78.16-.2a3.3 3.3 0 0 1 2.44-1.1zM12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0Z',
        'nextdoor' => 'M14.65 9.997a.069.069 0 0 0-.07.069v1.415c-.123-.177-.42-.37-.805-.37-.745 0-1.316.659-1.316 1.445 0 .787.571 1.447 1.316 1.447.386 0 .682-.194.806-.372v.221c0 .05.04.09.09.09h.607a.07.07 0 0 0 .07-.07v-3.806a.069.069 0 0 0-.07-.069zm-3.913.404a.07.07 0 0 0-.069.07c0 .779.064.7-.504.7a.09.09 0 0 0-.09.09v.486c0 .05.04.089.09.089h.504v1.136c0 .676.476 1.003 1.07 1.003.183 0 .32-.017.434-.046a.07.07 0 0 0 .052-.067v-.526a.07.07 0 0 0-.086-.066.984.984 0 0 1-.227.023c-.33 0-.476-.133-.476-.47v-.987h.608a.07.07 0 0 0 .07-.069v-.527a.069.069 0 0 0-.07-.069h-.608v-.701a.069.069 0 0 0-.069-.07zm-8.396.676c-.516 0-.955.236-1.201.598-.02.03-.055.095-.102.095-.226.002-.24-.276-.247-.524a.07.07 0 0 0-.069-.066l-.653-.004a.07.07 0 0 0-.069.07c.014.606.126 1.018.86 1.181.04.01.068.045.068.087v1.36c0 .037.03.068.069.068h.634a.07.07 0 0 0 .069-.07V12.47c0-.312.221-.667.64-.667.4 0 .642.355.642.667v1.404c0 .038.03.069.069.069h.634a.07.07 0 0 0 .069-.07v-1.508c0-.72-.616-1.287-1.413-1.287zm3.207.033c-.851 0-1.472.626-1.472 1.446 0 .876.65 1.431 1.483 1.447.655.012 1.09-.363 1.194-.494a.068.068 0 0 0-.015-.097l-.435-.34c-.047-.047-.084-.021-.112.001-.07.057-.203.22-.626.237-.37.015-.7-.205-.745-.576h2.03a.07.07 0 0 0 .069-.065c.006-.082.006-.142.006-.196 0-.897-.644-1.363-1.377-1.363zm11.652 0c-.812 0-1.472.637-1.472 1.446 0 .81.66 1.447 1.472 1.447.812 0 1.472-.638 1.472-1.447s-.66-1.446-1.472-1.446zm3.229 0c-.812 0-1.472.637-1.472 1.446 0 .81.66 1.447 1.472 1.447.812 0 1.472-.638 1.472-1.447s-.66-1.446-1.472-1.446zm3.314.028a.745.745 0 0 0-.695.476v-.374a.069.069 0 0 0-.069-.069h-.628a.069.069 0 0 0-.07.07v2.632a.07.07 0 0 0 .07.069h.628a.07.07 0 0 0 .07-.07v-1.255c0-.454.24-.737.604-.737.092 0 .175.013.26.035A.069.069 0 0 0 24 11.85v-.624a.07.07 0 0 0-.056-.068.938.938 0 0 0-.201-.02zm-16.666.033a.069.069 0 0 0-.058.108l.88 1.305L7 13.832a.07.07 0 0 0 .056.11h.745a.068.068 0 0 0 .056-.03l.564-.79.563.79a.069.069 0 0 0 .056.03h.74a.069.069 0 0 0 .057-.11l-.899-1.248.88-1.305a.069.069 0 0 0-.058-.108h-.738a.07.07 0 0 0-.058.03l-.548.818-.549-.817a.07.07 0 0 0-.057-.03zm-1.552.565c.286 0 .566.155.633.482h-1.31c.073-.338.392-.482.677-.482zm8.412.067c.42 0 .705.321.705.753 0 .433-.285.754-.705.754s-.705-.321-.705-.754c0-.432.285-.753.705-.753zm3.263.016c.403 0 .694.31.694.737s-.291.737-.694.737c-.403 0-.7-.31-.7-.737 0-.426.297-.737.7-.737zm3.229 0c.403 0 .694.31.694.737s-.291.737-.694.737c-.403 0-.7-.31-.7-.737 0-.426.297-.737.7-.737z',
        'houzz' => 'M1.27 0V24H9.32V16.44H14.68V24H22.73V10.37L6.61 5.75V0H1.27Z',
    );

    if (isset($marks[$key])) {
        $inner = '<path fill="currentColor" d="' . $marks[$key] . '"/>';
    } else {
        $mono = ezbizi_social_monogram($key, $label);
        // Two letters in the same box as one need a smaller face, and the
        // baseline is stated rather than centred with `dominant-baseline`,
        // which older Safari ignores and silently drops the letter out of view.
        $face = strlen($mono) > 1 ? 10 : 14;
        $inner = '<text x="12" y="17" text-anchor="middle" font-size="' . $face . '"'
            . ' font-weight="700" fill="currentColor">' . esc_html($mono) . '</text>';
    }

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" role="presentation">'
        . $inner . '</svg>';
}

/**
 * The letters that stand for a network, where two share an initial.
 *
 * Twitter, TikTok and Thumbtack are all T, and a row with three identical
 * letters in it tells a visitor nothing. A network not listed here — one
 * invented in EzBizi after this release — gets the first letter of its own
 * label, which is why this table is only the collisions.
 */
function ezbizi_social_monogram($key, $label) {
    $known = array(
        'facebook' => 'f',
        'linkedin' => 'in',
        'tiktok' => 'Tk',
        'thumbtack' => 'Th',
        'yelp' => 'Yp',
        'google_business' => 'G',
        'pinterest' => 'P',
        'bbb' => 'B',
        'nextdoor' => 'N',
        'houzz' => 'Hz',
        'angi' => 'A',
        'homeadvisor' => 'HA',
    );

    if (isset($known[$key])) {
        return $known[$key];
    }

    /*
     * The first LETTER OR DIGIT of the label, not its first byte: a label
     * beginning with a quotation mark or a multibyte character would otherwise
     * put half a character into the SVG and break the whole document.
     */
    return preg_match('/[A-Za-z0-9]/', $label, $m) === 1 ? strtoupper($m[0]) : '?';
}

/**
 * The row's own stylesheet.
 *
 * Every property a theme habitually sets on an anchor is stated, for the
 * reason `ezbizi_chat_style()` records at length: a widget living inside
 * somebody else's stylesheet has to say what it wants, including the things
 * that look like they have obvious defaults.
 */
function ezbizi_social_style($id, $preset) {
    $size = (int) $preset['size'];
    $gap = (int) $preset['gap'];
    $style = isset($preset['style']) ? (string) $preset['style'] : 'plain';
    $shape = isset($preset['shape']) ? (string) $preset['shape'] : 'circle';
    $labels = ! empty($preset['labels']);
    $s = '#' . esc_attr($id);

    if ($shape === 'square') {
        $radius = '0';
    } elseif ($shape === 'rounded') {
        $radius = max(3, (int) round($size / 5)) . 'px';
    } else {
        $radius = '999px';
    }

    // A chip has a border and a background around the glyph, so the glyph is
    // smaller than the box it sits in. A plain icon IS the box.
    $glyph = $style === 'plain'
        ? max(12, (int) round($size * 0.86))
        : max(10, (int) round($size * 0.58));

    /*
     * Stated in one place per variant rather than as a base plus overrides in
     * the same rule. Two `background` declarations one after the other work —
     * the last wins — and read like a mistake for ever afterwards.
     */
    if ($style === 'solid') {
        $palette = ezbizi_widget_palette();
        // The accent the customer already set for the chat. An icon colour of
        // its own in every preset would be a second place to set one colour.
        $chip = 'background:' . esc_attr(ezbizi_widget_text($palette, 'accent', '#0b5cad')) . ';'
            . 'color:' . esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff')) . ';border:0;';
    } elseif ($style === 'outline') {
        $chip = 'background:none;color:inherit;border:1px solid currentColor;';
    } else {
        $chip = 'background:none;color:inherit;border:0;';
    }

    return '<style>'
        . $s . '{display:flex;flex-wrap:wrap;align-items:center;gap:' . $gap . 'px;'
        . 'margin:0;padding:0;line-height:1}'

        . $s . ' .ezbizi-social-link{display:inline-flex;align-items:center;justify-content:center;'
        . 'gap:8px;box-sizing:border-box;'
        . ($labels ? 'width:auto;min-width:' . $size . 'px;padding:0 10px;' : 'width:' . $size . 'px;padding:0;')
        . 'height:' . $size . 'px;border-radius:' . $radius . ';'
        . 'box-shadow:none;text-decoration:none;font:inherit;line-height:1;' . $chip . '}'
        . $s . ' .ezbizi-social-link:hover{opacity:.82}'
        . $s . ' .ezbizi-social-link svg,' . $s . ' .ezbizi-social-link img{display:block;flex:0 0 auto;object-fit:contain;'
        . 'width:' . $glyph . 'px;height:' . $glyph . 'px}'
        // Stated only when there is a label to state it about, so the markup
        // says which row this is: a rule for an element that is never drawn is
        // a rule somebody has to read before concluding it does nothing.
        . ($labels ? $s . ' .ezbizi-social-label{white-space:nowrap;font-size:inherit}' : '')
        . '</style>';
}

// ── Map ─────────────────────────────────────────────────────────────

/**
 * [ezbizi_google_map] — where the business is, embedded.
 *
 * ── Why there is no API key ──
 *
 * Because we do not have one, and a keyed embed is worse than no embed: the
 * Maps JavaScript API bills per load and refuses to draw without a key, so one
 * missing or over-quota key would put "This page can't load Google Maps
 * correctly" on every site at once. The keyless `output=embed` form has been
 * the way to put a map in an iframe for years, costs nothing and needs no
 * account.
 *
 * `maps_url` cannot do this job — a Google "Share" link refuses to be framed —
 * which is why `latitude` and `longitude` exist as fields at all. It is still
 * the right thing to fall back to: a link to the listing beats an empty box.
 */
function ezbizi_google_map_shortcode($atts) {
    $preset = ezbizi_preset('google_map', $atts);
    $latitude = trim(ezbizi_value('latitude'));
    $longitude = trim(ezbizi_value('longitude'));

    $company = ezbizi_value('company_name');
    $title = $company === ''
        ? __('Map', 'ezbizi')
        : sprintf(
            /* translators: %s: the company name. */
            __('%s on Google Maps', 'ezbizi'),
            $company
        );

    if ($latitude !== '' && $longitude !== '') {
        $types = array('roadmap' => 'm', 'satellite' => 'k', 'hybrid' => 'h', 'terrain' => 'p');
        $type = isset($preset['map_type'], $types[$preset['map_type']]) ? $types[$preset['map_type']] : 'm';
        $src = 'https://maps.google.com/maps?q=' . rawurlencode($latitude) . ',' . rawurlencode($longitude)
            . '&z=' . (int) $preset['zoom'] . '&t=' . $type . '&output=embed';

        $id = ezbizi_widget_id('ezbizi-map');
        $mobile = isset($preset['height_mobile']) ? (int) $preset['height_mobile'] : 0;
        $extra = '<style>#' . $id . '{display:block;width:100%;height:' . (int) $preset['height'] . 'px;border:0'
            . (! empty($preset['grayscale']) ? ';filter:grayscale(1)' : '') . '}'
            . ($mobile > 0 ? '@media (max-width:767px){#' . $id . '{height:' . $mobile . 'px}}' : '') . '</style>';

        $out = ezbizi_style_css('google_map', $id, $preset, array('box' => '')) . $extra
            . '<iframe class="ezbizi-map" id="' . esc_attr($id) . '" src="' . esc_url($src) . '"'
            . ' title="' . esc_attr($title) . '"'
            . ' loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

        if (! empty($preset['address']) && ezbizi_value('address') !== '') {
            $out .= '<p class="ezbizi-map-address" id="' . esc_attr($id) . '-a">' . esc_html(ezbizi_value('address')) . '</p>';
        }

        if (! empty($preset['directions'])) {
            $to = ezbizi_value('maps_url') !== '' ? ezbizi_value('maps_url') : 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($latitude . ',' . $longitude);
            $bid = $id . '-d';
            $palette = ezbizi_widget_palette();
            $out .= '<style>#' . $bid . '{display:inline-block;margin-top:10px;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:700;background:'
                . esc_attr(ezbizi_widget_text($palette, 'accent', '#0b5cad')) . ';color:' . esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff')) . '}</style>'
                . '<a class="ezbizi-map-directions" id="' . esc_attr($bid) . '" href="' . esc_url($to) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html(isset($preset['directions_text']) && $preset['directions_text'] !== '' ? $preset['directions_text'] : __('Get directions', 'ezbizi')) . '</a>'
                . ezbizi_style_css('google_map', $bid, $preset, array('button' => ''));
        }

        return $out;
    }

    $listing = esc_url(ezbizi_value('maps_url'));

    if ($listing !== '') {
        return '<a class="ezbizi-map-link" href="' . $listing . '"'
            . ' target="_blank" rel="noopener noreferrer">' . esc_html($title) . '</a>';
    }

    return '';
}

// ── Page title ──────────────────────────────────────────────────────

/**
 * [ezbizi_post_title] — this page's own title.
 *
 * The one widget here with no server data in it. It is here because the pages
 * these shortcodes go into are built from one template per town, where the
 * heading has to say the town — and the title is the one thing WordPress
 * already knows that EzBizi does not.
 */
function ezbizi_post_title_shortcode($atts) {
    $p = ezbizi_preset('post_title', $atts);
    $id = function_exists('get_the_ID') ? get_the_ID() : 0;
    $format = isset($p['format']) ? (string) $p['format'] : 'title';

    // The page's own title, or the place it is about (Toula's "city/state/
    // address title formats"), which a location page carries as its values.
    if ($format === 'city') {
        $title = ezbizi_value('city');
    } elseif ($format === 'city_state') {
        $title = trim(ezbizi_value('city') . (ezbizi_value('state') !== '' ? ', ' . ezbizi_value('state') : ''), ', ');
    } elseif ($format === 'address') {
        $title = ezbizi_value('address');
    } else {
        $title = $id ? html_entity_decode((string) get_the_title($id), ENT_QUOTES, 'UTF-8') : '';
    }

    if ($title === '') {
        $title = isset($p['fallback']) ? (string) $p['fallback'] : '';
    }

    if ($title === '') {
        return '';
    }

    $text = esc_html((isset($p['prefix']) ? (string) $p['prefix'] : '') . $title . (isset($p['suffix']) ? (string) $p['suffix'] : ''));
    $tag = isset($p['tag']) && in_array($p['tag'], array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div'), true) ? $p['tag'] : 'none';
    $wid = ezbizi_widget_id('ezbizi-title');
    $css = ezbizi_style_css('post_title', $wid, $p, array('title' => ''));

    // Bare words unless a tag or a style asks for an element: this sits inside
    // headings on live pages, and wrapping it would nest one in another.
    if ($tag === 'none' && $css === '') {
        return $text;
    }

    $tag = $tag === 'none' ? 'span' : $tag;

    return $css . '<' . $tag . ' class="ezbizi-title" id="' . esc_attr($wid) . '">' . $text . '</' . $tag . '>';
}

// ── Hours table ─────────────────────────────────────────────────────

/**
 * [ezbizi_hours_table] — the week, as a table.
 *
 * ── Why the string is still the fallback ──
 *
 * `hours` is one line of text and always has been; `hours_week` is that line
 * read back as seven days, and it is ABSENT whenever the line was not written
 * by EzBizi's grid. "By appointment, November to March" is a real value real
 * sites hold, and it is the sites least like a shop that hold it. So a table
 * that cannot be built prints the sentence exactly as `[ezbizi_hours]` would,
 * and a site that never touched the grid still renders. See
 * `App\Support\Web\HoursWeek`.
 */
function ezbizi_hours_table_shortcode($atts) {
    $preset = ezbizi_preset('hours_table', $atts);
    $payload = ezbizi_payload();
    $week = isset($payload['hours_week']) && is_array($payload['hours_week'])
        ? $payload['hours_week']
        : array();

    if ($week === array()) {
        return esc_html(ezbizi_value('hours'));
    }

    $today = ! empty($preset['today']) ? ezbizi_today() : '';
    $compact = ! empty($preset['compact']);
    $layout = isset($preset['layout']) ? (string) $preset['layout'] : 'table';
    $long = isset($preset['days']) && $preset['days'] === 'long';
    $h24 = isset($preset['clock']) && $preset['clock'] === '24';
    $closed_text = isset($preset['closed_text']) && $preset['closed_text'] !== '' ? (string) $preset['closed_text'] : __('Closed', 'ezbizi');
    $names = array('Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday');
    $clock = function ($hhmm) use ($h24) {
        return $h24 && preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $hhmm, $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : ezbizi_clock($hhmm);
    };
    $id = ezbizi_widget_id('ezbizi-hours');
    $out = ezbizi_hours_style($id, $compact);

    if (! empty($preset['dividers'])) {
        $divider = isset($preset['divider_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $preset['divider_color']) ? (string) $preset['divider_color'] : 'rgba(127,127,127,.35)';
        $out .= '<style>#' . $id . ' .ezbizi-hours-row + .ezbizi-hours-row > *{border-top:1px solid ' . $divider . '}</style>';
    }

    if ($layout !== 'table') {
        $out .= '<style>#' . $id . '.ezbizi-hours-list{display:flex;flex-direction:' . ($layout === 'inline' ? 'row;flex-wrap:wrap;gap:6px 18px' : 'column;gap:4px') . '}'
            . '#' . $id . ' .ezbizi-hours-row{display:flex;gap:10px}</style>';
    }

    $rows = '';

    foreach ($week as $day) {
        if (! is_array($day) || ! isset($day['day'])) {
            continue;
        }

        $closed = ! empty($day['closed']);

        if ($closed && isset($preset['show_closed']) && empty($preset['show_closed'])) {
            continue;
        }

        $short = (string) $day['day'];
        $name = $long && isset($names[$short]) ? $names[$short] : $short;
        $class = 'ezbizi-hours-row' . ($today !== '' && $today === $short ? ' ezbizi-hours-today' : '');
        $hours = $closed ? esc_html($closed_text) : esc_html(
            $clock(isset($day['open']) ? (string) $day['open'] : '') . ' – ' . $clock(isset($day['close']) ? (string) $day['close'] : '')
        );

        $rows .= $layout === 'table'
            ? '<tr class="' . $class . '"' . (strpos($class, 'today') ? ' aria-current="true"' : '') . '><th scope="row">' . esc_html($name) . '</th>'
                . ($closed ? '<td class="ezbizi-hours-closed">' : '<td>') . $hours . '</td></tr>'
            : '<div class="' . $class . '"><span class="ezbizi-hours-day">' . esc_html($name) . '</span> '
                . '<span class="ezbizi-hours-time' . ($closed ? ' ezbizi-hours-closed' : '') . '">' . $hours . '</span></div>';
    }

    $title = isset($preset['title']) ? trim((string) $preset['title']) : '';
    $badge = '';

    // Open or closed NOW, worked out in the visitor's browser in the site's
    // own zone — a page cache would freeze an answer worked out here.
    if (! empty($preset['status'])) {
        $bid = $id . '-now';
        $badge = '<span class="ezbizi-hours-badge" id="' . esc_attr($bid) . '" hidden></span>'
            . "<script>(function(){var b=document.getElementById(" . wp_json_encode($bid) . ");if(!b)return;
var week=" . wp_json_encode(array_values($week)) . ",zone=" . wp_json_encode(ezbizi_value('timezone')) . ";
try{var p=new Intl.DateTimeFormat('en-US',{timeZone:zone||undefined,weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false}).formatToParts(new Date()),o={};
p.forEach(function(x){o[x.type]=x.value;});var now=(+o.hour%24)*60+(+o.minute),open=false;
week.forEach(function(d){if(d.day===o.weekday&&!d.closed&&d.open&&d.close){var a=d.open.split(':'),c=d.close.split(':');open=now>=(+a[0]*60+ +a[1])&&now<(+c[0]*60+ +c[1]);}});
b.textContent=open?" . wp_json_encode((string) (isset($preset['open_text']) ? $preset['open_text'] : 'Open now')) . ":" . wp_json_encode((string) (isset($preset['shut_text']) ? $preset['shut_text'] : 'Closed now')) . ";b.hidden=false;}catch(e){}})();</script>";
    }

    $head = ($title !== '' ? '<p class="ezbizi-hours-title"><strong>' . esc_html($title) . '</strong></p>' : '') . $badge;
    // The title and badge sit beside the table, so they are styled from a
    // wrapper of their own rather than from inside it.
    $head = $head === '' ? '' : '<div class="ezbizi-hours-head" id="' . esc_attr($id) . '-h">' . $head . '</div>'
        . ezbizi_style_css('hours_table', $id . '-h', $preset, array('title' => ' .ezbizi-hours-title', 'badge' => ' .ezbizi-hours-badge'));

    $out .= $layout === 'table'
        ? $head . '<table class="ezbizi-hours" id="' . esc_attr($id) . '"><tbody>' . $rows . '</tbody></table>'
        : $head . '<div class="ezbizi-hours ezbizi-hours-list" id="' . esc_attr($id) . '">' . $rows . '</div>';

    return $out . ezbizi_style_css('hours_table', $id, $preset, array(
        'box' => '', 'day' => $layout === 'table' ? ' th' : ' .ezbizi-hours-day',
        'time' => $layout === 'table' ? ' td' : ' .ezbizi-hours-time', 'closed' => ' .ezbizi-hours-closed',
    ));
}

/**
 * Which day it is where the BUSINESS is, as `Mon` … `Sun`.
 *
 * ── Why the site's own zone and not WordPress's ──
 *
 * `values.timezone` is the zone the hours were written in, and it is the one a
 * franchise site gets right: a WordPress install set up in California can
 * perfectly well publish a New Jersey branch's hours, and marking Tuesday on a
 * Monday evening is the sort of wrong that nobody reports and everybody sees.
 * WordPress's own zone is the fallback because it is usually the same answer.
 *
 * ── Why this is safe to bake into a cached page and the chat's gate is not ──
 *
 * `ezbizi_chat_shortcode()` had to decide in JavaScript: every site on the
 * estate serves from a page cache, so PHP's answer is frozen into the HTML,
 * and a bubble hidden at midnight stays hidden at 10am. Marking a row of a
 * table wrong for as long as the cache lives is a cosmetic fault on one
 * widget; hiding the way a visitor gets in touch is not. Nothing is hidden
 * here, so the cheap answer is the right one.
 */
function ezbizi_today() {
    $zone = null;
    $named = ezbizi_value('timezone');

    if ($named !== '') {
        try {
            $zone = new DateTimeZone($named);
        } catch (Exception $e) {
            // A zone we cannot read is a zone the site got wrong, and it must
            // not stop the table drawing.
            $zone = null;
        }
    }

    if ($zone === null && function_exists('wp_timezone')) {
        $zone = wp_timezone();
    }

    if (! $zone instanceof DateTimeZone) {
        return '';
    }

    $now = new DateTime('now', $zone);

    // `D` is `Mon`…`Sun`, which is exactly how the server names the days.
    return $now->format('D');
}

/**
 * `08:00` as `8:00 AM`.
 *
 * The payload carries a 24-hour clock because that is the only unambiguous way
 * to send one. The customer typed 8:00 AM into the grid and `[ezbizi_hours]`
 * renders 8:00 AM, so a table beside it that said 08:00 would look like a
 * different set of hours to the same visitor.
 */
function ezbizi_clock($hhmm) {
    if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $hhmm, $m) !== 1) {
        // Printed as it arrived, and escaped by the caller. A time we cannot
        // read is still the customer's answer.
        return $hhmm;
    }

    $hour = (int) $m[1] % 24;
    $meridiem = $hour < 12 ? 'AM' : 'PM';
    $twelve = $hour % 12;

    return ($twelve === 0 ? 12 : $twelve) . ':' . $m[2] . ' ' . $meridiem;
}

function ezbizi_hours_style($id, $compact) {
    $s = '#' . esc_attr($id);
    $pad = $compact ? '2px 14px 2px 0' : '6px 18px 6px 0';

    return '<style>'
        . $s . '{border-collapse:collapse;width:auto;margin:0;'
        . ($compact ? 'font-size:.92em;' : '') . 'color:inherit}'
        . $s . ' th,' . $s . ' td{padding:' . $pad . ';border:0;background:none;'
        . 'text-align:left;font:inherit;vertical-align:top}'
        . $s . ' th{font-weight:600;white-space:nowrap}'
        . $s . ' .ezbizi-hours-today th,' . $s . ' .ezbizi-hours-today td{font-weight:700}'
        . '</style>';
}

// ── City list ───────────────────────────────────────────────────────

/**
 * The towns this site has a page for.
 *
 * A page is a town's page because it answers for `city`, which is the only
 * thing the payload knows about it — `pages` is a map of path to values and
 * carries no notion of a kind. That is enough: a page with a city on it is a
 * page about that city, and a page without one cannot be listed as a town
 * whatever else it holds.
 */
function ezbizi_city_pages() {
    $payload = ezbizi_payload();
    $pages = isset($payload['pages']) && is_array($payload['pages']) ? $payload['pages'] : array();
    $towns = array();

    foreach ($pages as $path => $values) {
        $values = (array) $values;
        $city = isset($values['city']) ? trim((string) $values['city']) : '';

        if ($city === '') {
            continue;
        }

        $state = isset($values['state']) ? trim((string) $values['state']) : '';

        $towns[] = array(
            'path' => (string) $path,
            // The state earns its place: a site covering both sides of a line
            // has a Washington in each.
            'label' => $state === '' ? $city : $city . ', ' . $state,
            'city' => $city,
            'group' => isset($values['group']) ? trim((string) $values['group']) : '',
        );
    }

    return $towns;
}

/**
 * [ezbizi_city_list] — every town this site covers, linked.
 *
 * ── Why the accordion is `<details>` ──
 *
 * Because it has to work with JavaScript off, and because the alternative is a
 * script. This plugin enqueues none: a script is a request, a cache entry and
 * a thing that can collide with a theme's own jQuery, for a fold. `<details>`
 * is in every browser this plugin supports and needs nothing.
 */
function ezbizi_city_list_shortcode($atts) {
    $preset = ezbizi_preset('city_list', $atts);
    $towns = ezbizi_city_pages();

    if ($towns === array()) {
        return '';
    }

    $layout = isset($preset['layout']) ? (string) $preset['layout'] : 'columns';
    $link = ! empty($preset['link']);
    $grouped = array();
    $loose = array();

    $seen = array();

    foreach ($towns as $town) {
        $in = ! empty($preset['group']) && $town['group'] !== '' ? $town['group'] : '';

        // Several pages about one town (a service each) are one town in a list.
        $key = $in . "\n" . strtolower($town['label']);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        if ($in !== '') {
            $grouped[$in][] = $town;

            continue;
        }

        $loose[] = $town;
    }

    /*
     * Sorted, both levels. The payload's order is the order rows came out of
     * the database, which is arbitrary and changes when somebody edits a page
     * — a list of towns that reorders itself for no reason is a list nobody
     * trusts. `strnatcasecmp` so Route 9 sorts before Route 10.
     */
    uksort($grouped, 'strnatcasecmp');
    ezbizi_sort_towns($loose);

    foreach ($grouped as $name => $entries) {
        ezbizi_sort_towns($entries);
        $grouped[$name] = $entries;
    }

    $id = ezbizi_widget_id('ezbizi-cities');
    $columns = $layout === 'columns' ? (int) $preset['columns'] : 1;

    $out = ezbizi_city_style($id, $columns);
    $state = ! isset($preset['show_state']) || ! empty($preset['show_state']);
    $new_tab = ! empty($preset['new_tab']);
    $count = function ($entries) use ($preset) {
        return ! empty($preset['count']) ? ' <span class="ezbizi-cities-count">(' . count($entries) . ')</span>' : '';
    };
    $out .= '<style>#' . $id . ' .ezbizi-cities-list{column-gap:' . max(0, min(80, (int) (isset($preset['column_gap']) ? $preset['column_gap'] : 28))) . 'px'
        . (! empty($preset['bullets']) ? ';list-style:disc;padding-left:1.2em' : '') . '}</style>';
    $out .= '<div class="ezbizi-cities" id="' . esc_attr($id) . '"'
        . (! empty($preset['one_open']) ? ' data-one-open="1"' : '') . '>';

    if (! empty($preset['search'])) {
        $out .= '<input type="search" class="ezbizi-cities-search" placeholder="'
            . esc_attr(isset($preset['search_placeholder']) ? (string) $preset['search_placeholder'] : '')
            . '" aria-label="' . esc_attr__('Search cities', 'ezbizi') . '" />';
    }

    $first = true;

    /*
     * An accordion with nothing to fold is a list behind a button, so an
     * ungrouped site gets the list. The groups are the panels; there is no
     * other axis to fold a flat list of towns along.
     */
    $folding = $layout === 'accordion' && $grouped !== array();
    $tag = isset($preset['heading_tag']) && in_array($preset['heading_tag'], array('h2', 'h3', 'h4', 'h5', 'h6'), true) ? $preset['heading_tag'] : '';
    $name_html = function ($name) use ($tag) {
        return $tag === '' ? esc_html($name) : '<' . $tag . ' class="ezbizi-cities-heading">' . esc_html($name) . '</' . $tag . '>';
    };

    // Folding, the towns in no group get a panel of their own at the end,
    // rather than a bare list under the last panel.
    if ($folding && $loose !== array()) {
        $grouped[isset($preset['other_label']) && $preset['other_label'] !== '' ? (string) $preset['other_label'] : __('Other areas', 'ezbizi')] = $loose;
        $loose = array();
    }

    foreach ($grouped as $name => $entries) {
        if ($folding) {
            $out .= '<details class="ezbizi-cities-group"' . ($first && ! empty($preset['open_first']) ? ' open' : '') . '>'
                . '<summary class="ezbizi-cities-label">' . $name_html($name) . $count($entries) . '</summary>'
                . ezbizi_city_links($entries, $link, $state, $new_tab) . '</details>';
            $first = false;

            continue;
        }

        /*
         * `<strong>` and not `<h3>`: this renders inside a page whose heading
         * levels we cannot see, and a widget that guesses one either skips a
         * level or outranks the page's own title. A group name is a label, not
         * a section of the document.
         */
        $out .= '<div class="ezbizi-cities-group">'
            . ($tag === ''
                ? '<strong class="ezbizi-cities-label">' . esc_html($name) . $count($entries) . '</strong>'
                : '<div class="ezbizi-cities-label">' . $name_html($name) . $count($entries) . '</div>')
            . ezbizi_city_links($entries, $link, $state, $new_tab) . '</div>';
    }

    // Last, and with no label, because there is no honest one to give them.
    if ($loose !== array()) {
        $out .= ezbizi_city_links($loose, $link, $state, $new_tab);
    }

    if (! empty($preset['search'])) {
        $out .= '<p class="ezbizi-cities-empty" hidden>'
            . esc_html(isset($preset['empty_text']) ? (string) $preset['empty_text'] : '') . '</p>';
    }

    $out .= '</div>';

    // Search narrows the towns and hides a group with none left; one-open
    // closes the others when a group is opened.
    if (! empty($preset['search']) || ! empty($preset['one_open'])) {
        $out .= "<script>(function(){
var w=document.getElementById(" . wp_json_encode($id) . ");if(!w)return;
var q=w.querySelector('.ezbizi-cities-search'),none=w.querySelector('.ezbizi-cities-empty');
if(q)q.addEventListener('input',function(){var t=q.value.toLowerCase().trim(),n=0;
  Array.prototype.forEach.call(w.querySelectorAll('li'),function(li){var ok=!t||li.textContent.toLowerCase().indexOf(t)>-1;li.hidden=!ok;if(ok)n++;});
  Array.prototype.forEach.call(w.querySelectorAll('.ezbizi-cities-group'),function(g){var any=g.querySelector('li:not([hidden])');g.hidden=!any;if(t&&any&&g.tagName==='DETAILS')g.open=true;});
  if(none)none.hidden=n>0;});
if(w.getAttribute('data-one-open'))Array.prototype.forEach.call(w.querySelectorAll('details'),function(d){d.addEventListener('toggle',function(){
  if(d.open)Array.prototype.forEach.call(w.querySelectorAll('details'),function(o){if(o!==d)o.open=false;});});});
})();</script>";
    }

    return $out . ezbizi_style_css('city_list', $id, $preset, array(
        'box' => '', 'heading' => ' .ezbizi-cities-label', 'group' => ' .ezbizi-cities-group',
        'link' => ' a', 'count' => ' .ezbizi-cities-count', 'search' => ' .ezbizi-cities-search',
        'arrow' => ' summary::marker', 'bullet' => ' li::marker',
    ));
}

/** Towns in the order a reader expects them. */
function ezbizi_sort_towns(&$towns) {
    usort($towns, function ($a, $b) {
        return strnatcasecmp($a['label'], $b['label']);
    });
}

function ezbizi_city_links($towns, $link, $state = true, $new_tab = false) {
    $out = '<ul class="ezbizi-cities-list">';

    foreach ($towns as $town) {
        $label = esc_html($state || ! isset($town['city']) ? $town['label'] : $town['city']);

        /*
         * A path that does not survive `esc_url` is shown as text rather than
         * dropped. The town is a fact about the service area and stands on its
         * own; the link is how somebody reads more about it.
         */
        $href = $link ? esc_url(home_url($town['path'])) : '';

        $out .= '<li>' . ($href === ''
            ? $label
            : '<a href="' . $href . '"' . ($new_tab ? ' target="_blank" rel="noopener"' : '') . '>' . $label . '</a>') . '</li>';
    }

    return $out . '</ul>';
}

function ezbizi_city_style($id, $columns) {
    $s = '#' . esc_attr($id);

    return '<style>'
        . $s . '{margin:0;color:inherit}'
        . $s . ' .ezbizi-cities-list{list-style:none;margin:0 0 14px;padding:0;'
        . ($columns > 1 ? 'columns:' . $columns . ';column-gap:28px;' : '')
        . '}'
        // `break-inside` so a town does not land half in one column and half
        // in the next, which is what a multi-column list does by default.
        . $s . ' .ezbizi-cities-list li{margin:0 0 4px;padding:0;break-inside:avoid;'
        . '-webkit-column-break-inside:avoid}'
        . $s . ' .ezbizi-cities-list a{color:inherit;text-decoration:underline}'
        . $s . ' .ezbizi-cities-label{display:block;margin:0 0 6px;font-weight:700}'
        . $s . ' .ezbizi-cities-heading{display:inline;margin:0;font-size:1.2em}'
        // Themes often hide a summary's marker; the fold arrow is the only sign it opens.
        . $s . ' summary.ezbizi-cities-label{display:list-item;list-style:disclosure-closed inside;cursor:pointer}'
        . $s . ' details[open]>summary.ezbizi-cities-label{list-style-type:disclosure-open}'
        . $s . ' .ezbizi-cities-group{margin:0 0 10px}'
        . $s . ' summary{cursor:pointer;font-weight:700;margin:0 0 6px}'
        . $s . ' .ezbizi-cities-search{width:100%;box-sizing:border-box;padding:10px;margin:0 0 12px;border:1px solid #ccc;border-radius:6px;font:inherit}'
        . '</style>';
}

/*
 * On `init` with the others, and for the same reason: a shortcode registered
 * at file load would run before the payload exists on the request that fetches
 * it.
 */
/**
 * [ezbizi_contact_info] — the phone, the email and the address as one block.
 *
 * The thing every footer on a-1chimney.com, everdoors.com and crestln.com
 * carries: an icon, a label, a value, once per detail. Three separate
 * shortcodes already publish these as bare strings; what this adds is the
 * arrangement, which is the part somebody currently rebuilds by hand in
 * Elementor on every site.
 *
 * ── Why a row with nothing in it is not rendered ──
 *
 * A site with no email would otherwise publish a mail icon, the word "Email"
 * and an empty space. An absent detail is absent rather than blank.
 */
function ezbizi_contact_info_shortcode($atts) {
    $preset = ezbizi_preset('contact_info', $atts);
    $layout = isset($preset['layout']) ? (string) $preset['layout'] : 'stack';
    $icons = ! empty($preset['icons']);
    $labels = ! empty($preset['labels']);

    // The order somebody asked for, then anything they left out, in the usual order.
    $order = array_values(array_intersect(
        array_unique(array_merge(
            array_map('trim', explode(',', isset($preset['order']) ? (string) $preset['order'] : '')),
            array('phone', 'email', 'address', 'hours', 'license')
        )),
        array('phone', 'email', 'address', 'hours', 'license')
    ));

    $title = function ($key, $fallback) use ($preset) {
        return isset($preset[$key]) ? (string) $preset[$key] : $fallback;
    };
    $links = ! isset($preset['links']) || ! empty($preset['links']);
    $secondary = ! empty($preset['secondary']);

    $rows = array();

    foreach ($order as $what) {
        // Absent means yes for the first three, no for the two added later. An
        // older payload has none of these keys, and a plugin that read that as
        // "show nothing" would blank the block on every site not yet refreshed.
        $on = in_array($what, array('hours', 'license'), true)
            ? ! empty($preset[$what])
            : (! isset($preset[$what]) || ! empty($preset[$what]));

        if (! $on) {
            continue;
        }

        if ($what === 'phone') {
            foreach (array_unique(array_filter(array(ezbizi_value('phone'), $secondary ? ezbizi_value('phone_secondary') : ''))) as $i => $value) {
                $rows[] = array('phone', $i === 0 ? $title('phone_title', 'Phone') : '', $value, 'tel:' . ezbizi_dial($value), false);
            }

            // Toula: more than two numbers. "Label | number" per line, the label optional.
            foreach (ezbizi_preset_lines($preset, 'extra_phones') as $parts) {
                $value = isset($parts[1]) ? $parts[1] : $parts[0];
                $rows[] = array('phone', isset($parts[1]) ? $parts[0] : '', $value, 'tel:' . ezbizi_dial($value), false);
            }
        } elseif ($what === 'email') {
            foreach (array_unique(array_filter(array(ezbizi_value('email'), $secondary ? ezbizi_value('email_secondary') : ''))) as $i => $value) {
                $rows[] = array('email', $i === 0 ? $title('email_title', 'Email') : '', $value, 'mailto:' . $value, false);
            }
        } elseif ($what === 'address') {
            $rows[] = array('address', $title('address_title', 'Location'), ezbizi_value('address'), ezbizi_value('maps_url'), false);
        } elseif ($what === 'hours') {
            $rows[] = array('hours', $title('hours_title', 'Hours'), ezbizi_contact_hours($title('closed_text', 'Closed')), '', true);
        } elseif ($what === 'license') {
            $rows[] = array('license', $title('license_title', 'License'), ezbizi_value('license'), '', false);
        }
    }

    $drawn = array();

    foreach ($rows as $row) {
        // An absent detail is absent. A mail icon over an empty space is the
        // one thing worse than not publishing the row at all.
        if ($row[2] !== '') {
            $drawn[] = $row;
        }
    }

    if ($drawn === array()) {
        return '';
    }

    $id = ezbizi_widget_id('ezbizi-contact');
    $out = ezbizi_contact_info_style($id, $layout);
    $out .= '<div class="ezbizi-contact ezbizi-contact-' . esc_attr($layout) . '" id="' . esc_attr($id) . '">';

    foreach ($drawn as $row) {
        list($kind, $label, $value, $href, $html) = $row;
        $href = $links ? $href : '';

        $out .= '<div class="ezbizi-contact-item ezbizi-contact-' . esc_attr($kind) . '">';

        // Each section's own icon when one was chosen (Toula), else its usual one.
        $icon = isset($preset[$kind . '_icon']) && $preset[$kind . '_icon'] !== 'auto' ? (string) $preset[$kind . '_icon'] : $kind;

        if ($icons && $icon !== 'none') {
            $out .= '<span class="ezbizi-contact-icon" aria-hidden="true">'
                . ezbizi_contact_icon($icon) . '</span>';
        }

        $out .= '<div class="ezbizi-contact-text">';

        if ($labels && $label !== '') {
            $out .= '<span class="ezbizi-contact-label">' . esc_html($label) . '</span>';
        }

        $out .= '<span class="ezbizi-contact-value">';
        // Hours are markup built here from escaped parts; everything else is text.
        $out .= $html ? $value : ($href === ''
            ? esc_html($value)
            : '<a href="' . esc_url($href) . '">' . esc_html($value) . '</a>');
        $out .= '</span></div></div>';
    }

    $sel = '#' . $id;
    $gap = function ($key, $fallback) use ($preset) {
        return max(0, min(80, (int) (isset($preset[$key]) ? $preset[$key] : $fallback)));
    };
    $divider = isset($preset['divider_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $preset['divider_color'])
        ? (string) $preset['divider_color']
        : 'currentColor';
    $out .= '<style>' . $sel . '{gap:' . $gap('item_gap', 18) . 'px}'
        . $sel . ' .ezbizi-contact-item{gap:' . $gap('icon_gap', 10) . 'px}'
        . $sel . ' a{text-decoration:' . (! empty($preset['underline']) ? 'underline' : 'none') . '}'
        . (! empty($preset['dividers']) ? $sel . ' .ezbizi-contact-item + .ezbizi-contact-item{border-top:1px solid ' . $divider . ';padding-top:' . $gap('item_gap', 18) . 'px}' : '')
        . '</style>';

    return $out . '</div>' . ezbizi_style_css('contact_info', $id, $preset, array(
        'box' => '', 'item' => ' .ezbizi-contact-item', 'icon' => ' .ezbizi-contact-icon',
        'label' => ' .ezbizi-contact-label', 'value' => ' .ezbizi-contact-value',
        'day' => ' .ezbizi-contact-day', 'time' => ' .ezbizi-contact-time',
    ));
}

/**
 * The week as lines, from the site's hours sentence ("Mon–Fri 8:00 AM – 5:00
 * PM · Sat closed"), with the closed wording somebody chose. Escaped here,
 * because the caller prints it as markup.
 */
function ezbizi_contact_hours($closed) {
    $hours = trim(ezbizi_value('hours'));

    if ($hours === '') {
        return '';
    }

    $out = '';

    foreach (preg_split('/\s*\x{00B7}\s*/u', $hours) as $part) {
        if (! preg_match('/^(\S+)\s+(.+)$/u', trim($part), $m)) {
            continue;
        }

        $time = strcasecmp(trim($m[2]), 'closed') === 0 ? $closed : $m[2];
        $out .= '<span class="ezbizi-contact-hours-row"><span class="ezbizi-contact-day">' . esc_html($m[1]) . '</span> '
            . '<span class="ezbizi-contact-time">' . esc_html($time) . '</span></span><br />';
    }

    return $out;
}

/** Digits a phone can dial, from a number written for a person to read. */
function ezbizi_dial($value) {
    return preg_replace('/[^0-9+]/', '', $value);
}

/** One inline SVG per detail. No icon font, so nothing else has to load. */
function ezbizi_contact_icon($kind) {
    $paths = array(
        'phone' => 'M6.6 10.8a15.1 15.1 0 006.6 6.6l2.2-2.2a1 1 0 011-.2 11.4 11.4 0 003.6.6 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1c0 1.3.2 2.5.6 3.6a1 1 0 01-.3 1z',
        'email' => 'M20 4H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V6a2 2 0 00-2-2zm0 4l-8 5-8-5V6l8 5 8-5z',
        'address' => 'M12 2a7 7 0 00-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 00-7-7zm0 9.5A2.5 2.5 0 1112 6.5a2.5 2.5 0 010 5z',
        'calendar' => 'M7 2v2H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V6a2 2 0 00-2-2h-2V2h-2v2H9V2zM5 9h14v11H5z',
        'star' => 'M12 2l3 6.9 7.5.7-5.7 5 1.7 7.4L12 18l-6.5 4 1.7-7.4-5.7-5 7.5-.7z',
        'arrow' => 'M4 11h12.2l-5.6-5.6L12 4l8 8-8 8-1.4-1.4 5.6-5.6H4z',
        'chat' => 'M4 3h16a2 2 0 012 2v11a2 2 0 01-2 2H8l-5 4V5a2 2 0 011-2z',
        'hours' => 'M12 2a10 10 0 100 20 10 10 0 000-20zm0 2a8 8 0 110 16 8 8 0 010-16zm-1 3v6l5 3 1-1.7-4-2.4V7z',
        'license' => 'M12 2l8 3v6c0 5-3.4 9.7-8 11-4.6-1.3-8-6-8-11V5zm-1.2 13.2l6-6-1.4-1.4-4.6 4.6-2.2-2.2-1.4 1.4z',
        'mobile' => 'M7 1h10a2 2 0 012 2v18a2 2 0 01-2 2H7a2 2 0 01-2-2V3a2 2 0 012-2zm0 4v14h10V5zm5 15.5a1 1 0 100-2 1 1 0 000 2z',
        'home' => 'M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z',
        'globe' => 'M12 2a10 10 0 100 20 10 10 0 000-20zm6.9 6h-2.9a15.7 15.7 0 00-1.4-3.6A8 8 0 0118.9 8zM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4zM4.3 14a8.2 8.2 0 010-4h3.4a16.5 16.5 0 000 4zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 015.1 16zM8 8H5.1a8 8 0 014.3-3.6C8.8 5.5 8.3 6.7 8 8zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4zm2.3-6H9.7a14.7 14.7 0 010-4h4.6a14.7 14.7 0 010 4zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 01-4.3 3.6zm1.7-5.6a16.5 16.5 0 000-4h3.4a8.2 8.2 0 010 4z',
    );

    $d = isset($paths[$kind]) ? $paths[$kind] : $paths['address'];

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" focusable="false">'
        . '<path d="' . esc_attr($d) . '"/></svg>';
}

function ezbizi_contact_info_style($id, $layout) {
    $sel = '#' . $id;
    $css = $sel . '{display:flex;gap:18px}'
        . $sel . '.ezbizi-contact-stack{flex-direction:column}'
        . $sel . '.ezbizi-contact-row{flex-direction:row;flex-wrap:wrap}'
        . $sel . '.ezbizi-contact-compact{flex-direction:row;flex-wrap:wrap;gap:12px}'
        . $sel . '.ezbizi-contact-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}'
        . $sel . '.ezbizi-contact-inline{flex-direction:row;flex-wrap:wrap;align-items:center}'
        . $sel . ' .ezbizi-contact-item{display:flex;align-items:center;gap:10px}'
        . $sel . ' .ezbizi-contact-icon{display:inline-flex;align-items:center;justify-content:center;'
            // Sized around its icon, so a large padding cannot squeeze the icon to nothing.
            . 'box-sizing:content-box;min-width:20px;min-height:20px;padding:9px;border-radius:8px;border:1px solid currentColor;flex:none}'
        . $sel . '.ezbizi-contact-compact .ezbizi-contact-icon{padding:4px;border:0}'
        . $sel . ' .ezbizi-contact-text{display:flex;flex-direction:column;min-width:0}'
        . $sel . ' .ezbizi-contact-label{font-weight:700;line-height:1.2}'
        . $sel . ' .ezbizi-contact-value{line-height:1.3;word-break:break-word}'
        . $sel . ' a{color:inherit}';

    return '<style>' . $css . '</style>';
}

/**
 * [ezbizi_cta] — the standing call to action.
 *
 * A1's yellow "CALL US TODAY" strip along the bottom of every page, and
 * Everdoors' "Need Garage Door Help?" panel that opens over it.
 *
 * ── Why `inline` is the default and the other two are not ──
 *
 * A bar and a popup both take space a visitor did not offer. A widget that did
 * that the moment somebody pasted it into a page would be a surprise on a live
 * site, and on this estate it would be a surprise on 110 of them at once.
 * Choosing `bar` is choosing to cover the bottom of the page.
 *
 * ── Why the popup remembers ──
 *
 * `sessionStorage`, so it opens once a visit rather than on every page. A panel
 * that reopened on every click is the pattern people install blockers for, and
 * it would be doing it in the customer's name.
 */
function ezbizi_cta_shortcode($atts) {
    $preset = ezbizi_preset('cta', $atts);
    $placement = isset($preset['placement']) ? (string) $preset['placement'] : 'inline';
    $action = isset($preset['action']) ? (string) $preset['action'] : 'call';
    $icon = ! empty($preset['icon']);
    $wide = ! empty($preset['full_width']);

    $phone = ezbizi_value('phone');

    if ($action === 'call') {
        $href = $phone === '' ? '' : 'tel:' . ezbizi_dial($phone);
        $text = __('Call us today', 'ezbizi');
    } elseif ($action === 'sms') {
        $href = $phone === '' ? '' : 'sms:' . ezbizi_dial($phone);
        $text = __('Text us', 'ezbizi');
    } elseif ($action === 'whatsapp') {
        // wa.me takes the number with its country code and nothing else.
        $digits = preg_replace('/\D/', '', $phone);
        $digits = strlen($digits) === 10 ? '1' . $digits : $digits;
        $href = $digits === '' ? '' : 'https://wa.me/' . $digits;
        $text = __('WhatsApp us', 'ezbizi');
    } elseif ($action === 'email') {
        $email = ezbizi_value('email');
        $href = $email === '' ? '' : 'mailto:' . $email;
        $text = __('Email us', 'ezbizi');
    } elseif ($action === 'book') {
        $href = ezbizi_value('booking_url');
        $text = __('Book online', 'ezbizi');
    } elseif ($action === 'review') {
        $href = ezbizi_value('review_link');
        $text = __('Leave us a review', 'ezbizi');
    } elseif ($action === 'url') {
        $href = isset($preset['url']) ? trim((string) $preset['url']) : '';
        $href = preg_match('~^https?://~i', $href) ? $href : '';
        $text = __('Learn more', 'ezbizi');
    } else {
        $href = '#ezbizi-form';
        $text = __('Get a free estimate', 'ezbizi');
    }

    // The number, address or link itself, when that is what the button says.
    if (isset($preset['label']) && $preset['label'] === 'value' && $href !== '') {
        $shown = array(
            'call' => $phone, 'sms' => $phone, 'whatsapp' => $phone,
            'email' => ezbizi_value('email'),
        );
        $text = isset($shown[$action]) ? $shown[$action] : $href;
    }

    // Tracking on a web link: `utm_source=footer&utm_medium=cta`, appended.
    $params = isset($preset['url_params']) ? ltrim(trim((string) $preset['url_params']), '?&') : '';

    if ($params !== '' && preg_match('~^https?://~i', $href)) {
        $href .= (strpos($href, '?') === false ? '?' : '&') . $params;
    }

    // The site's own words win. Empty falls back to the wording that matches
    // the action, so a button is never blank.
    $chosen = isset($preset['text']) ? trim((string) $preset['text']) : '';

    if ($chosen !== '') {
        $text = $chosen;
    }

    // Nothing to act on is nothing to draw. A "Call us today" button with no
    // number behind it is a dead control on a live page.
    if ($href === '') {
        return '';
    }

    $id = ezbizi_widget_id('ezbizi-cta');
    $out = ezbizi_cta_style($id, $wide);

    $kinds = array(
        'call' => 'phone', 'sms' => 'phone', 'whatsapp' => 'chat', 'email' => 'email',
        'book' => 'calendar', 'review' => 'star', 'url' => 'arrow', 'form' => 'arrow',
    );
    $kind = isset($preset['icon_type']) && $preset['icon_type'] !== 'auto'
        ? (string) $preset['icon_type']
        : (isset($kinds[$action]) ? $kinds[$action] : 'arrow');
    $glyph = $icon && $kind !== 'none'
        ? '<span class="ezbizi-cta-icon" aria-hidden="true">' . ezbizi_contact_icon($kind) . '</span>'
        : '';
    $label = '<span class="ezbizi-cta-text">' . esc_html($text) . '</span>';
    $away = ! empty($preset['new_tab']) && preg_match('~^https?://~i', $href);
    $animation = isset($preset['animation']) ? (string) $preset['animation'] : 'none';

    $button = '<a class="ezbizi-cta-button' . ($animation !== 'none' ? ' ezbizi-cta-' . esc_attr($animation) : '') . '" href="' . esc_url($href) . '"'
        . ($away ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
        . (isset($preset['icon_position']) && $preset['icon_position'] === 'right' ? $label . $glyph : $glyph . $label)
        . '</a>';
    $out .= ezbizi_cta_motion($id, $preset);

    if ($placement === 'popup') {
        $out .= '<div class="ezbizi-cta ezbizi-cta-popup" id="' . esc_attr($id)
            . '" hidden role="dialog" aria-label="' . esc_attr($text) . '">'
            . '<button type="button" class="ezbizi-cta-close" aria-label="'
            . esc_attr__('Close', 'ezbizi') . '">&times;</button>'
            . $button . '</div>';

        $out .= '<script>(function(){'
            . 'var root=document.getElementById(' . wp_json_encode($id) . ');'
            . 'if(!root)return;'
            . 'var seen=false;'
            . 'try{seen=window.sessionStorage.getItem("ezbizi_cta")==="1";}catch(e){}'
            . 'if(seen)return;'
            . 'root.hidden=false;'
            . 'try{window.sessionStorage.setItem("ezbizi_cta","1");}catch(e){}'
            . 'root.querySelector(".ezbizi-cta-close").addEventListener("click",function(){root.hidden=true;});'
            . '})();</script>';

        return $out . ezbizi_style_css('cta', $id, $preset, array(
            'box' => '', 'button' => ' .ezbizi-cta-button', 'icon' => ' .ezbizi-cta-icon',
        ));
    }

    return $out . '<div class="ezbizi-cta ezbizi-cta-' . esc_attr($placement) . '" id="'
        . esc_attr($id) . '">' . $button . '</div>' . ezbizi_style_css('cta', $id, $preset, array(
        'box' => '', 'button' => ' .ezbizi-cta-button', 'icon' => ' .ezbizi-cta-icon',
    ));
}

/**
 * The CTA's animation, icon spacing and phone behaviour, as CSS.
 *
 * Animation runs always or only while hovered; it stops for a visitor whose
 * system asks for reduced motion, whatever the setting, because a shaking
 * button is the thing that setting exists to stop.
 */
function ezbizi_cta_motion($id, $p) {
    $sel = '#' . $id;
    $css = $sel . ' .ezbizi-cta-button{gap:' . max(0, min(40, (int) (isset($p['icon_gap']) ? $p['icon_gap'] : 8))) . 'px}';
    // A phone number broken across two lines is not one anybody dials.
    $css .= $sel . ' .ezbizi-cta-text{white-space:nowrap}';
    $animation = isset($p['animation']) ? (string) $p['animation'] : 'none';
    $speed = max(1, min(10, (int) (isset($p['animation_speed']) ? $p['animation_speed'] : 2)));
    $glow = isset($p['glow_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $p['glow_color'])
        ? (string) $p['glow_color']
        : 'var(--ezbizi-accent,#0b5cad)';

    $frames = array(
        'pulse' => '0%,100%{transform:scale(1)}50%{transform:scale(1.06)}',
        'shake' => '0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-4px)}40%,80%{transform:translateX(4px)}',
        'bounce' => '0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}',
        'glow' => '0%,100%{box-shadow:0 0 0 0 ' . $glow . '}50%{box-shadow:0 0 18px 4px ' . $glow . '}',
    );

    if (isset($frames[$animation])) {
        $name = 'ezbizi-' . $animation . '-' . preg_replace('/[^a-z0-9-]/', '', $id);
        $run = 'animation:' . $name . ' ' . $speed . 's ease-in-out infinite';
        $trigger = isset($p['animate_on']) && $p['animate_on'] === 'hover' ? ':hover' : '';
        $css .= '@keyframes ' . $name . '{' . $frames[$animation] . '}'
            . $sel . ' .ezbizi-cta-button' . $trigger . '{' . $run . '}'
            . '@media (prefers-reduced-motion:reduce){' . $sel . ' .ezbizi-cta-button{animation:none}}';
    }

    $mobile = isset($p['mobile']) ? (string) $p['mobile'] : 'same';

    if ($mobile === 'hide') {
        $css .= '@media (max-width:767px){' . $sel . '{display:none!important}}';
    } elseif ($mobile === 'bar') {
        $css .= '@media (max-width:767px){' . $sel . '{position:fixed;left:0;right:0;bottom:0;z-index:9998;margin:0}'
            . $sel . ' .ezbizi-cta-button{width:100%;justify-content:center;border-radius:0}}';
    }

    return '<style>' . $css . '</style>';
}

function ezbizi_cta_style($id, $wide) {
    $sel = '#' . $id;
    $css = $sel . '{display:flex;justify-content:center}'
        . $sel . ' .ezbizi-cta-button{display:inline-flex;align-items:center;gap:8px;'
            . 'padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700;'
            . 'background:var(--ezbizi-accent,#0b5cad);color:var(--ezbizi-on-accent,#fff)}'
        . ($wide ? $sel . ' .ezbizi-cta-button{width:100%;justify-content:center}' : '')
        /*
         * Below the chat's z-index on purpose. The bubble already clears 88px
         * from the bottom for exactly this kind of strip — Jose photographed it
         * sitting inside a-1chimney.com's yellow bar — so a bar and a bubble on
         * one page stack rather than fight.
         */
        . $sel . '.ezbizi-cta-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147482000;'
            . 'padding:10px;background:var(--ezbizi-accent,#0b5cad)}'
        . $sel . '.ezbizi-cta-bar .ezbizi-cta-button{background:transparent}'
        . $sel . '.ezbizi-cta-popup{position:fixed;right:20px;bottom:160px;z-index:2147482500;'
            . 'padding:18px;border-radius:12px;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.25)}'
        . $sel . ' .ezbizi-cta-close{position:absolute;top:4px;right:8px;border:0;background:none;'
            . 'font-size:20px;line-height:1;cursor:pointer}';

    return '<style>' . $css . '</style>';
}

/**
 * [ezbizi_phone_box] — the number on its own, tappable.
 *
 * `[ezbizi_phone]` already publishes the digits as text. This is the same
 * number as a CONTROL: an icon, an optional line above it, and an anchor a
 * thumb can hit. It is the single most-placed widget on the A1 estate —
 * headers, hero sections, the top of every service card.
 */
function ezbizi_phone_box_shortcode($atts) {
    $preset = ezbizi_preset('phone_box', $atts);
    $which = isset($preset['which']) && $preset['which'] === 'secondary' ? 'phone_secondary' : 'phone';
    // The second number when asked for, and the first when there is no second.
    $value = ezbizi_value($which) !== '' ? ezbizi_value($which) : ezbizi_value('phone');

    if ($value === '') {
        return '';
    }

    $size = isset($preset['size']) ? (string) $preset['size'] : 'medium';
    $icon = ! empty($preset['icon']);
    $link = ! empty($preset['link']);
    $label = isset($preset['label']) ? (string) $preset['label'] : '';
    $position = isset($preset['icon_position']) ? (string) $preset['icon_position'] : 'left';
    $digits = preg_replace('/\D/', '', $value);
    $format = isset($preset['format']) ? (string) $preset['format'] : 'as_entered';

    // Written the way somebody chose, for a US number; anything else as entered.
    if ($format !== 'as_entered' && strlen($digits) === 10) {
        $a = substr($digits, 0, 3);
        $b = substr($digits, 3, 3);
        $c = substr($digits, 6);
        $shown = $format === 'dashes' ? "$a-$b-$c" : ($format === 'dots' ? "$a.$b.$c" : "($a) $b-$c");
    } else {
        $shown = $value;
    }

    $type = isset($preset['link_type']) ? (string) $preset['link_type'] : 'call';
    $full = strlen($digits) === 10 ? '1' . $digits : $digits;
    $href = $type === 'sms' ? 'sms:' . ezbizi_dial($value) : ($type === 'whatsapp' ? 'https://wa.me/' . $full : 'tel:' . ezbizi_dial($value));

    $id = ezbizi_widget_id('ezbizi-phone');
    $sizes = array('small' => '16px', 'medium' => '22px', 'large' => '30px');
    $font = isset($sizes[$size]) ? $sizes[$size] : $sizes['medium'];

    $out = '<style>#' . $id . '{display:inline-flex;align-items:center;gap:10px'
        . ($position === 'right' ? ';flex-direction:row-reverse' : ($position === 'top' ? ';flex-direction:column' : '')) . '}'
        . '#' . $id . ' .ezbizi-phone-number{font-size:' . $font . ';font-weight:700;line-height:1.2}'
        . '#' . $id . ' a{color:inherit;text-decoration:none}'
        . '#' . $id . ' .ezbizi-phone-label{display:block;font-size:.8em;font-weight:400;opacity:.8}'
        . '</style>';

    $inner = ($label === '' ? '' : '<span class="ezbizi-phone-label">' . esc_html($label) . '</span>')
        . '<span class="ezbizi-phone-number">' . esc_html($shown) . '</span>';

    $out .= '<div class="ezbizi-phone-box" id="' . esc_attr($id) . '">';

    if ($icon) {
        $out .= '<span class="ezbizi-phone-icon" aria-hidden="true">'
            . ezbizi_contact_icon($type === 'whatsapp' ? 'chat' : 'phone') . '</span>';
    }

    $out .= '<div>';
    $out .= $link
        ? '<a href="' . esc_url($href) . '"' . ($type === 'whatsapp' ? ' target="_blank" rel="noopener"' : '') . '>' . $inner . '</a>'
        : $inner;

    return $out . '</div></div>' . ezbizi_style_css('phone_box', $id, $preset, array(
        'box' => '', 'icon' => ' .ezbizi-phone-icon', 'label' => ' .ezbizi-phone-label',
        'number' => ' .ezbizi-phone-number',
    ));
}

/** Whether a link points at the page being drawn, on this site. */
function ezbizi_is_this_page($url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

    if ($host !== '' && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $home)) {
        return false;
    }

    $path = '/' . trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

    return ($path === '/' ? '' : strtolower($path)) === ezbizi_path();
}

// ── The state, in words ─────────────────────────────────────────────

/**
 * The state's full name, from the two letters EzBizi stores ("NJ" →
 * "New Jersey"). A1 Tools printed the name, in headings like "#1 Garage Door
 * Repair Company in the [state]", and a heading reading "in the NJ" would be
 * the migration showing. Anything that is not a known code prints as stored.
 */
function ezbizi_state_full($code) {
    $names = array(
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    );
    $code = trim((string) $code);

    return isset($names[strtoupper($code)]) ? $names[strtoupper($code)] : $code;
}

/** [ezbizi_state_name] — "New Jersey" on a site whose state is NJ. */
function ezbizi_state_name_shortcode($atts) {
    return esc_html(ezbizi_state_full(ezbizi_value('state')));
}

/** [ezbizi_state_anchor] — the state's name, linked to its own website when EzBizi has one. */
function ezbizi_state_anchor_shortcode($atts) {
    $name = ezbizi_state_full(ezbizi_value('state'));
    $link = ezbizi_value('state_link');

    if ($name === '') {
        return '';
    }

    return preg_match('~^https?://~i', $link)
        ? '<a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>'
        : esc_html($name);
}

// ── Street View ─────────────────────────────────────────────────────

/**
 * [ezbizi_street_view] — the page's `street_view_url` as a Street View.
 *
 * Only a Google Maps embed is drawn. The value is typed by a person and lands
 * in an iframe on somebody's live page, and an iframe of any other address is
 * a way to put a stranger's page inside theirs. A whole `<iframe …>` pasted
 * where the link should go is read for its src rather than refused, since
 * that is what Google's "Embed a map" hands over.
 */
function ezbizi_street_view_src($raw) {
    $raw = trim(html_entity_decode((string) $raw, ENT_QUOTES));

    if (stripos($raw, '<iframe') !== false) {
        $raw = preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m) ? $m[1] : '';
    }

    $parts = wp_parse_url($raw);
    $host = isset($parts['host']) ? strtolower($parts['host']) : '';

    if (($parts['scheme'] ?? '') !== 'https'
        || ! preg_match('/^(www\.|maps\.)?google\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/', $host)
        || strpos($parts['path'] ?? '', '/maps/embed') !== 0) {
        return '';
    }

    return $raw;
}

function ezbizi_street_view_shortcode($atts) {
    $src = ezbizi_street_view_src(ezbizi_value('street_view_url'));

    if ($src === '') {
        return '';
    }

    return '<div class="ezbizi-street-view"><iframe src="' . esc_url($src) . '" width="100%" height="450"'
        . ' style="border:0;display:block" allowfullscreen loading="lazy"'
        . ' referrerpolicy="no-referrer-when-downgrade" title="Street View"></iframe></div>';
}

// ── Review faces ────────────────────────────────────────────────────

/**
 * [ezbizi_review_faces] — A1 Tools' smiley widget, answered from EzBizi.
 *
 * Happy sends the visitor straight to the Google review link, no step
 * between (Jose, 2026-10-13). Sad opens a short form
 * that posts to the same public endpoint as [ezbizi_form], so the complaint
 * lands in Leads with this site's host on it instead of on Google.
 *
 * With no `review_link` the happy face still works: it says thank you instead
 * of offering a button to nowhere.
 */
function ezbizi_review_faces_shortcode($atts) {
    // No chat bubble on the reviews page (Jose, 2026-10-09); see the footer
    // hook in enquiries.php.
    $GLOBALS['ezbizi_chat_hidden'] = true;
    $p = ezbizi_preset('review_faces', $atts);
    $key = ezbizi_public_key();

    if ($key === '') {
        return ezbizi_widget_silent(
            __('EzBizi: this site has no browser key yet. Click EzBizi in the sidebar.', 'ezbizi')
        );
    }

    $t = function ($k) use ($p) {
        return esc_html(isset($p[$k]) ? (string) $p[$k] : '');
    };
    // The Google link first. `review_link` is where a "Review us" button goes,
    // and on 2026-09-25 every A1 site's was set to its own /reviews/ page —
    // this page — so a happy face that used it sent the customer round in a
    // circle. A link to the page the faces are on is never drawn.
    $link = ezbizi_value('google_review_link');

    if ($link === '') {
        $link = ezbizi_value('review_link');
    }

    if ($link !== '' && ezbizi_is_this_page($link)) {
        $link = '';
    }
    $review = $link === '' ? '' : '<a class="ezbizi-rf-btn" href="' . esc_url($link)
        . '" target="_blank" rel="noopener noreferrer">' . $t('button') . '</a>';

    $id = ezbizi_widget_id('ezbizi-rf');
    $s = '#' . $id;
    $palette = ezbizi_widget_palette();
    $accent = esc_attr(ezbizi_widget_text($palette, 'accent', '#22B24C'));
    $on_accent = esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff'));
    $size = max(40, min(120, (int) (isset($p['face_size']) ? $p['face_size'] : 56)));
    $dark = isset($p['tone']) && $p['tone'] === 'dark';

    /*
     * Made for the phone it is opened on (UI queue 68; Jose, 2026-10-08: "this
     * is how it looks on phone, so maybe improve it a bit"): two full-width
     * buttons to tap, Great first and larger; one button shape for Continue,
     * the review and Join; the membership as its own card; and the bottom kept
     * clear of the chat bubble, which shrinks to its icon on this page only.
     */
    $small = (int) round($size * 0.7);
    $out = '<style>' . $s . '{text-align:center;width:100%;max-width:440px;box-sizing:border-box;margin:0 auto;padding-bottom:96px' . ($dark ? ';color:#fff' : '') . '}'
        . $s . ' h3{color:' . $accent . ';font-size:clamp(22px,6vw,30px);line-height:1.15;margin:0 0 8px}'
        . $s . ' p,' . $s . ' label,' . $s . ' .ezbizi-rf-face{color:inherit}'
        . $s . ' input,' . $s . ' textarea{background:#fff;color:#1b1b1b}'
        . $s . ' .ezbizi-rf-faces{display:flex;flex-direction:column;gap:12px;margin:20px 0}'
        . $s . ' .ezbizi-rf-face{display:flex;align-items:center;gap:14px;width:100%;min-height:64px;box-sizing:border-box;padding:12px 18px;border:2px solid;border-radius:12px;background:none;cursor:pointer;color:inherit;font:inherit;font-weight:800;font-size:18px;text-align:left}'
        . $s . ' .ezbizi-rf-face[data-go=happy]{order:-1;min-height:84px;font-size:20px;border-color:#22B24C;background:rgba(34,178,76,.12)}'
        . $s . ' .ezbizi-rf-face[data-go=sad]{border-color:rgba(226,61,75,.7);background:rgba(226,61,75,.08)}'
        . $s . ' .ezbizi-rf-face small{display:block;font-size:13px;font-weight:500;opacity:.75}'
        . $s . ' .ezbizi-rf-face svg{flex:0 0 auto;transition:transform .15s}'
        . $s . ' .ezbizi-rf-face:hover svg,' . $s . ' .ezbizi-rf-face:focus svg{transform:scale(1.08)}'
        . $s . ' .ezbizi-rf-face[data-go=happy] svg{width:' . $size . 'px;height:' . $size . 'px}'
        . $s . ' .ezbizi-rf-face[data-go=sad] svg{width:' . $small . 'px;height:' . $small . 'px}'
        . $s . ' .ezbizi-rf-btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:52px;box-sizing:border-box;padding:12px 22px;border:2px solid ' . $accent . ';border-radius:12px;background:' . $accent . ';color:' . $on_accent . ';text-decoration:none;font-weight:800;font-size:16px;cursor:pointer}'
        . $s . ' .ezbizi-rf-skip{display:block;margin:10px auto 0;min-height:44px}'
        . $s . ' .ezbizi-rf-check{display:flex;align-items:center;justify-content:center;width:56px;height:56px;margin:0 auto 10px;border:2px solid #22B24C;border-radius:50%;background:rgba(34,178,76,.12);color:#22B24C;font-size:28px;font-weight:900}'
        . $s . ' .ezbizi-rf-member{text-align:left}'
        // After a not-so-great review: the thank-you, centred (Jose, 2026-10-08).
        . $s . ' [data-step=sent]{text-align:center}'
        . $s . ' [data-step=sent] .ezbizi-rf-btn{margin-left:auto;margin-right:auto}'
        . $s . ' .ezbizi-rf-member h3{font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin:0 0 4px}'
        . $s . ' .ezbizi-rf-plan{margin:8px 0;padding:14px;border:1px solid rgba(127,127,127,.35);border-radius:12px;background:rgba(127,127,127,.08)}'
        . $s . ' .ezbizi-rf-plan strong{font-size:17px}'
        . $s . ' .ezbizi-rf-plan p:last-of-type{margin:6px 0 10px;font-size:18px;font-weight:800}'
        . $s . ' .ezbizi-rf-plan .ezbizi-rf-btn{background:transparent;color:' . $accent . '}'
        . '@media (max-width:600px){' . $s . '{min-height:70svh}}'
        . 'body:has(' . $s . ') .ezbizi-chat-launch-text{display:none}'
        . $s . ' form{text-align:left}'
        . $s . ' label{display:block;margin:10px 0 4px;font-weight:600}'
        . $s . ' .ezbizi-rf-hint{display:block;margin:-2px 0 6px;font-size:.85em;opacity:.8}'
        . $s . ' input,' . $s . ' textarea{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccc;border-radius:12px;font:inherit;min-height:48px}'
        . $s . ' .ezbizi-rf-hp{position:absolute;left:-9999px}'
        . $s . ' .ezbizi-rf-consent{margin:14px auto 0;max-width:460px;font-size:12px;line-height:1.4;opacity:.75}'
        . '</style>';

    // A face drawn rather than an emoji, so it looks the same on every phone.
    $face = function ($happy) {
        $colour = $happy ? '#22B24C' : '#E23D4B';
        $mouth = $happy ? 'M20 38 Q32 50 44 38' : 'M20 46 Q32 34 44 46';

        return '<svg viewBox="0 0 64 64" width="56" height="56" aria-hidden="true" focusable="false">'
            . '<circle cx="32" cy="32" r="29" fill="none" stroke="' . $colour . '" stroke-width="4"/>'
            . '<circle cx="23" cy="26" r="3.5" fill="' . $colour . '"/><circle cx="41" cy="26" r="3.5" fill="' . $colour . '"/>'
            . '<path d="' . $mouth . '" fill="none" stroke="' . $colour . '" stroke-width="4" stroke-linecap="round"/></svg>';
    };

    $field = function ($name, $label, $type, $hint = '') use ($id) {
        $fid = $id . '-' . $name;
        $described = $hint === '' ? '' : ' aria-describedby="' . $fid . '-hint"';
        $input = $type === 'textarea'
            ? '<textarea id="' . $fid . '" name="' . $name . '" rows="4" required' . $described . '></textarea>'
            : '<input id="' . $fid . '" name="' . $name . '" type="' . $type . '" required' . $described . ' />';

        return '<label for="' . $fid . '">' . esc_html($label) . '</label>'
            . ($hint === '' ? '' : '<small class="ezbizi-rf-hint" id="' . $fid . '-hint">' . esc_html($hint) . '</small>') . $input;
    };

    // Texts after a review (thank-you, Membership offer) need consent shown before
    // the tap (Jose, 2026-11-03: "so any text we sent is legal").
    $company = ezbizi_value('company_name');
    $consent = '<p class="ezbizi-rf-consent">' . esc_html(sprintf(
        __('By continuing you agree to receive texts from %s about your service and offers. Msg & data rates may apply. Reply STOP to opt out.', 'ezbizi'),
        $company !== '' ? $company : __('us', 'ezbizi')
    )) . '</p>';

    $out .= '<div class="ezbizi-review-faces" id="' . esc_attr($id) . '">'
        . '<div data-step="pick"><h3>' . $t('title') . '</h3><p>' . $t('text') . '</p>'
        . '<div class="ezbizi-rf-faces">'
        . '<button type="button" class="ezbizi-rf-face" data-go="sad">' . $face(false) . '<span>' . $t('sad_label')
        . '<small>' . esc_html__('Tell us what to fix', 'ezbizi') . '</small></span></button>'
        . '<button type="button" class="ezbizi-rf-face" data-go="happy">' . $face(true) . '<span>' . $t('happy_label')
        // With a Google link the green face IS the way to Google (Jose, 2026-10-13),
        // without naming Google on the button (Jose, 2026-10-20).
        . '<small>' . ($link === '' ? esc_html__('Happy with the visit', 'ezbizi')
            : esc_html__('Share your experience with us', 'ezbizi')) . '</small></span></button>'
        . '</div>' . $consent . '</div>'
        /*
         * Jose, 2026-10-05: a good review can bring a thank-you and the
         * Membership offer. A customer who came by their personal link (`?c=`)
         * is known; anybody else is asked, once, and can skip it, so the way
         * to Google never waits on a box.
         */
        . '<div data-step="ask" hidden><div class="ezbizi-rf-check" aria-hidden="true">&#10003;</div><h3>' . $t('happy_title') . '</h3>'
        . '<p>' . esc_html__('Leave your phone or email so we can thank you (optional).', 'ezbizi') . '</p>'
        . '<label for="' . esc_attr($id) . '-who">' . esc_html__('Phone or email', 'ezbizi') . '</label>'
        . '<input id="' . esc_attr($id) . '-who" class="ezbizi-rf-who" type="text" autocomplete="email" />'
        . '<p><button type="button" class="ezbizi-rf-btn ezbizi-rf-continue">' . esc_html__('Continue', 'ezbizi') . '</button> '
        . '<button type="button" class="ezbizi-rf-skip" style="background:none;border:0;text-decoration:underline;cursor:pointer;color:inherit">'
        . esc_html__('Skip', 'ezbizi') . '</button></p></div>'
        . '<div data-step="happy" hidden><div class="ezbizi-rf-check" aria-hidden="true">&#10003;</div><h3>' . $t('happy_title') . '</h3><p>' . $t('happy_text') . '</p>'
        . ($review === '' ? '<p>' . $t('thanks') . '</p>' : $review) . '</div>'
        . '<div data-step="sad" hidden><h3>' . $t('sad_title') . '</h3><p>' . $t('sad_text') . '</p>'
        . '<form novalidate>'
        . $field('name', 'Your name', 'text')
        . $field('phone', 'Phone', 'tel')
        . $field('email', 'Email', 'email')
        // Jose, 2026-10-13: ask for detail, so the office can act on it.
        . $field('feedback', 'What happened?', 'textarea', __('Please be as descriptive as possible.', 'ezbizi'))
        . '<input class="ezbizi-rf-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" />'
        . ezbizi_turnstile_box()
        . '<p><button type="submit" class="ezbizi-rf-btn">' . $t('submit') . '</button></p>'
        . '<p class="ezbizi-rf-note" role="status" aria-live="polite"></p></form></div>'
        . '<div data-step="sent" hidden><p>' . $t('thanks') . '</p>'
        . ($review !== '' && ! empty($p['ask_public']) ? '<p>' . $t('public_prompt') . '</p>' . $review : '')
        // The membership, for a customer who came by their link (Membership, 2026-10-08).
        . '</div></div>';

    $out .= "<script>(function(){
var w=document.getElementById(" . wp_json_encode($id) . ");if(!w)return;
function show(n){Array.prototype.forEach.call(w.querySelectorAll('[data-step]'),function(d){d.hidden=d.getAttribute('data-step')!==n;});}
var api=" . wp_json_encode(ezbizi_api_base()) . ",key=" . wp_json_encode($key) . ",google=" . wp_json_encode($link) . ";
var c='';try{c=new URLSearchParams(location.search).get('c')||'';}catch(x){}
// The review itself, for the customer's record and the Membership offer. Never waited on.
function tell(v){v.key=key;if(c)v.c=c;try{fetch(api+'/api/v1/web/review',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(v)}).catch(function(){});}catch(x){}}
// A link that names the customer: the sad form stops asking for their name,
// phone and email, once the server confirms the link (Jose, 2026-10-08).
if(c){fetch(api+'/api/v1/web/membership?key='+encodeURIComponent(key)+'&c='+encodeURIComponent(c),{headers:{'Accept':'application/json'}})
  .then(function(r){return r.ok?r.json():null;}).then(function(d){if(!d||!d.first_name)return;var sf=w.querySelector('form');w._ezAs=1;
    ['name','phone','email'].forEach(function(n){var i=sf.elements[n];if(!i)return;i.required=false;i.style.display='none';var l=w.querySelector('label[for=\"'+i.id+'\"]');if(l)l.style.display='none';});
    var p=document.createElement('p');p.className='ezbizi-rf-as';p.textContent=" . wp_json_encode(__('Sending as', 'ezbizi')) . "+' '+d.first_name;sf.insertBefore(p,sf.firstChild);}).catch(function(){});}
// What the customer did with their link, for the Customer Experience report
// (Jose, 2026-10-15). Only with a link: the server counts nothing else.
function track(k){if(!c)return;try{fetch(api+'/api/v1/web/review-link',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({key:key,c:c,kind:k})}).catch(function(){});}catch(x){}}
track('opened');
function who(raw){var s=String(raw||'').trim(),o={};if(!s)return o;if(s.indexOf('@')>-1)o.email=s;else o.phone=s;return o;}
Array.prototype.forEach.call(w.querySelectorAll('[data-go]'),function(b){b.addEventListener('click',function(){
  var go=b.getAttribute('data-go');
  // Jose, 2026-10-13: the green face goes straight to Google, no step between.
  // Without a Google link it keeps the old path rather than a dead end.
  track(go);
  if(go==='happy'&&google){if(c)tell({rating:'happy'});track('google');location.href=google;return;}
  if(go==='happy'){if(c){tell({rating:'happy'});show('happy');}else show('ask');return;}
  show(go);
});});
Array.prototype.forEach.call(w.querySelectorAll('[data-step] a[target]'),function(a){a.addEventListener('click',function(){track('google');});});
var whoBox=w.querySelector('.ezbizi-rf-who');
w.querySelector('.ezbizi-rf-continue').addEventListener('click',function(){var v=who(whoBox.value);v.rating='happy';if(v.email||v.phone)tell(v);show('happy');});
w.querySelector('.ezbizi-rf-skip').addEventListener('click',function(){show('happy');});
var f=w.querySelector('form'),note=w.querySelector('.ezbizi-rf-note');
" . ezbizi_solver_js() . "
f.addEventListener('submit',function(e){
  e.preventDefault();
  if(f.elements.website.value!==''){show('sent');return;}
  if(!f.checkValidity()){f.reportValidity();return;}
  var ts=ezTs(f);if(ts===''){note.textContent=" . wp_json_encode(__('Please complete the check above the button.', 'ezbizi')) . ";return;}
  var b=f.querySelector('button');b.disabled=true;
  var g=function(n){return f.elements[n].value;};
  var v={key:" . wp_json_encode($key) . ",name:g('name'),email:g('email'),phone:g('phone'),page_url:location.href,
    fields:{'Rating':'Not happy','Name':g('name'),'Phone':g('phone'),'Email':g('email'),'What happened?':g('feedback')}};
  // Known by the link: the server fills who they are; nothing typed, nothing shown.
  if(w._ezAs){v={key:" . wp_json_encode($key) . ",c:c,page_url:location.href,fields:{'Rating':'Not happy','What happened?':g('feedback')}};}
  // The robot check every EzBizi form carries (Jose, 2026-10-06).
  if(ts)v.turnstile=ts;
  ezSolve(api).catch(function(){return null;}).then(function(sol){if(sol)v.captcha=sol;
  return fetch(api+'/api/v1/web/form',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(v)});})
  .then(function(r){ezTsReset(f);if(!r.ok)throw new Error(r.status);tell({rating:'sad',phone:g('phone'),email:g('email'),comment:g('feedback')});track('submitted');show('sent');})
  .catch(function(){note.textContent='Sorry, that did not send. Please try again or call us.';b.disabled=false;});
});
})();</script>";

    return $out . ezbizi_style_css('review_faces', $id, $p, array(
        'box' => '', 'title' => ' h3', 'text' => ' p', 'button' => ' .ezbizi-rf-btn',
    ));
}

add_action('init', function () {
    add_shortcode('ezbizi_social_links', 'ezbizi_social_links_shortcode');
    add_shortcode('ezbizi_google_map', 'ezbizi_google_map_shortcode');
    add_shortcode('ezbizi_post_title', 'ezbizi_post_title_shortcode');
    add_shortcode('ezbizi_hours_table', 'ezbizi_hours_table_shortcode');
    add_shortcode('ezbizi_city_list', 'ezbizi_city_list_shortcode');
    add_shortcode('ezbizi_contact_info', 'ezbizi_contact_info_shortcode');
    add_shortcode('ezbizi_cta', 'ezbizi_cta_shortcode');
    add_shortcode('ezbizi_phone_box', 'ezbizi_phone_box_shortcode');
    add_shortcode('ezbizi_review_faces', 'ezbizi_review_faces_shortcode');
    add_shortcode('ezbizi_street_view', 'ezbizi_street_view_shortcode');
    add_shortcode('ezbizi_state_name', 'ezbizi_state_name_shortcode');
    add_shortcode('ezbizi_state_anchor', 'ezbizi_state_anchor_shortcode');
});
