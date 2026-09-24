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
function ezbizi_preset($widget, $atts) {
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

    return array_merge(ezbizi_preset_fallback($widget), $chosen);
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
        'thanks' => 'Thank you! We have received your feedback and will be in touch shortly.',
        'public_prompt' => 'If you like, you can also share your experience publicly on Google.',
        'ask_public' => true,
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

    foreach (ezbizi_social_networks() as $key => $label) {
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
            . ' target="_blank" rel="noopener noreferrer"'
            // The only name a screen reader gets when `labels` is off.
            . ' aria-label="' . esc_attr($link['label']) . '"'
            . ' title="' . esc_attr($link['label']) . '">'
            . ezbizi_social_icon($link['key'], $link['label'])
            . ($labels ? '<span class="ezbizi-social-label">' . esc_html($link['label']) . '</span>' : '')
            . '</a>';
    }

    return $out . '</div>';
}

/**
 * One network's mark, drawn inline.
 *
 * ── Why some of these are letters ──
 *
 * No icon font and no CDN, so every glyph is markup in this file, and a glyph
 * in this file is one somebody here drew. Where primitives draw the real mark
 * — Instagram's rounded square, YouTube's play, the X — they draw it. Where
 * they cannot, the network's initial stands in, because a hand-recalled
 * approximation of a trademarked logo is wrong in a way a letter never is: it
 * looks like the brand and is not it, on 110 sites we do not own.
 *
 * Everything is `currentColor`, with no knocked-out white anywhere, so one
 * glyph works plain, solid and outlined without three copies of it. The box is
 * sized in CSS rather than here, so `size` does not have to be threaded
 * through every glyph to be honoured.
 */
function ezbizi_social_icon($key, $label) {
    $inner = '';

    if ($key === 'instagram') {
        $inner = '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>'
            . '<circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="2"/>'
            . '<circle cx="17.3" cy="6.7" r="1.35" fill="currentColor"/>';
    } elseif ($key === 'youtube') {
        $inner = '<rect x="2.5" y="5.5" width="19" height="13" rx="3.5" fill="none" stroke="currentColor" stroke-width="2"/>'
            . '<path d="M10.2 9.2l5.6 2.8-5.6 2.8z" fill="currentColor"/>';
    } elseif ($key === 'twitter') {
        $inner = '<path d="M4.5 4.5l15 15M19.5 4.5l-15 15" fill="none" stroke="currentColor"'
            . ' stroke-width="2.6" stroke-linecap="round"/>';
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
        . $s . ' .ezbizi-social-link svg{display:block;flex:0 0 auto;'
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
        $src = 'https://maps.google.com/maps?q=' . rawurlencode($latitude) . ',' . rawurlencode($longitude)
            . '&z=' . (int) $preset['zoom'] . '&output=embed';

        return '<iframe class="ezbizi-map" src="' . esc_url($src) . '"'
            // Named, because an unnamed iframe is announced as "frame" and a
            // visitor using a screen reader has no way to know what it holds.
            . ' title="' . esc_attr($title) . '"'
            . ' style="display:block;width:100%;height:' . (int) $preset['height'] . 'px;border:0"'
            // A map below the fold must not cost the page its load time.
            . ' loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
    }

    $listing = esc_url(ezbizi_value('maps_url'));

    if ($listing !== '') {
        return '<a class="ezbizi-map-link" href="' . $listing . '"'
            . ' target="_blank" rel="noopener noreferrer">' . esc_html($title) . '</a>';
    }

    /*
     * Nothing, not a message. A site that has filled in neither the
     * coordinates nor the listing is mid-setup, and an empty space is a better
     * thing for a visitor to meet than a box explaining our data model.
     */
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
    /*
     * A widget area, a footer, a 404 and an archive all render with no post in
     * scope, and `get_the_title()` with nothing to read answers with the title
     * of whatever was last set up — which is how a footer ends up captioned
     * with an unrelated page. Asking for the id first is what makes "there is
     * no post here" an answer rather than a wrong one.
     */
    $id = function_exists('get_the_ID') ? get_the_ID() : 0;

    if (! $id) {
        return '';
    }

    /*
     * Decoded, then escaped. A title has already been through `the_title`, so
     * it arrives as HTML: "Sweeps &amp; Repairs" and a curly apostrophe as
     * `&#8217;`. Escaping that directly would print the entities on the page,
     * which is the visible bug; decoding first and escaping after prints the
     * characters and still leaves nothing a browser will execute.
     */
    $title = html_entity_decode((string) get_the_title($id), ENT_QUOTES, 'UTF-8');

    return esc_html($title);
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
    $id = ezbizi_widget_id('ezbizi-hours');

    $out = ezbizi_hours_style($id, $compact);
    $out .= '<table class="ezbizi-hours" id="' . esc_attr($id) . '"><tbody>';

    foreach ($week as $day) {
        if (! is_array($day) || ! isset($day['day'])) {
            continue;
        }

        $name = (string) $day['day'];
        $is_today = $today !== '' && $today === $name;

        $hours = ! empty($day['closed'])
            ? esc_html__('Closed', 'ezbizi')
            : esc_html(
                ezbizi_clock(isset($day['open']) ? (string) $day['open'] : '')
                . ' – '
                . ezbizi_clock(isset($day['close']) ? (string) $day['close'] : '')
            );

        $out .= '<tr' . ($is_today ? ' class="ezbizi-hours-today" aria-current="true"' : '') . '>'
            . '<th scope="row">' . esc_html($name) . '</th>'
            . '<td>' . $hours . '</td>'
            . '</tr>';
    }

    return $out . '</tbody></table>';
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

    foreach ($towns as $town) {
        if (! empty($preset['group']) && $town['group'] !== '') {
            $grouped[$town['group']][] = $town;

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
    $out .= '<div class="ezbizi-cities" id="' . esc_attr($id) . '">';

    /*
     * An accordion with nothing to fold is a list behind a button, so an
     * ungrouped site gets the list. The groups are the panels; there is no
     * other axis to fold a flat list of towns along.
     */
    $folding = $layout === 'accordion' && $grouped !== array();

    foreach ($grouped as $name => $entries) {
        if ($folding) {
            $out .= '<details class="ezbizi-cities-group"><summary>' . esc_html($name) . '</summary>'
                . ezbizi_city_links($entries, $link) . '</details>';

            continue;
        }

        /*
         * `<strong>` and not `<h3>`: this renders inside a page whose heading
         * levels we cannot see, and a widget that guesses one either skips a
         * level or outranks the page's own title. A group name is a label, not
         * a section of the document.
         */
        $out .= '<div class="ezbizi-cities-group">'
            . '<strong class="ezbizi-cities-label">' . esc_html($name) . '</strong>'
            . ezbizi_city_links($entries, $link) . '</div>';
    }

    // Last, and with no label, because there is no honest one to give them.
    if ($loose !== array()) {
        $out .= ezbizi_city_links($loose, $link);
    }

    return $out . '</div>';
}

/** Towns in the order a reader expects them. */
function ezbizi_sort_towns(&$towns) {
    usort($towns, function ($a, $b) {
        return strnatcasecmp($a['label'], $b['label']);
    });
}

function ezbizi_city_links($towns, $link) {
    $out = '<ul class="ezbizi-cities-list">';

    foreach ($towns as $town) {
        $label = esc_html($town['label']);

        /*
         * A path that does not survive `esc_url` is shown as text rather than
         * dropped. The town is a fact about the service area and stands on its
         * own; the link is how somebody reads more about it.
         */
        $href = $link ? esc_url(home_url($town['path'])) : '';

        $out .= '<li>' . ($href === ''
            ? $label
            : '<a href="' . $href . '">' . $label . '</a>') . '</li>';
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
        . $s . ' .ezbizi-cities-group{margin:0 0 10px}'
        . $s . ' summary{cursor:pointer;font-weight:700;margin:0 0 6px}'
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

    $wanted = array();

    foreach (array('phone', 'email', 'address') as $what) {
        // Absent means yes. An older payload has none of these keys, and a
        // plugin that read that as "show nothing" would blank the block on
        // every site that had not refreshed yet.
        if (! isset($preset[$what]) || ! empty($preset[$what])) {
            $wanted[] = $what;
        }
    }

    $rows = array();

    foreach ($wanted as $what) {
        if ($what === 'phone') {
            $value = ezbizi_value('phone');
            $href = $value === '' ? '' : 'tel:' . ezbizi_dial($value);
            $rows[] = array('phone', __('Phone', 'ezbizi'), $value, $href);
        } elseif ($what === 'email') {
            $value = ezbizi_value('email');
            $rows[] = array('email', __('Email', 'ezbizi'), $value, $value === '' ? '' : 'mailto:' . $value);
        } elseif ($what === 'address') {
            $value = ezbizi_value('address');
            $rows[] = array('address', __('Location', 'ezbizi'), $value, ezbizi_value('maps_url'));
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
        list($kind, $label, $value, $href) = $row;

        $out .= '<div class="ezbizi-contact-item">';

        if ($icons) {
            $out .= '<span class="ezbizi-contact-icon" aria-hidden="true">'
                . ezbizi_contact_icon($kind) . '</span>';
        }

        $out .= '<div class="ezbizi-contact-text">';

        if ($labels) {
            $out .= '<span class="ezbizi-contact-label">' . esc_html($label) . '</span>';
        }

        $out .= '<span class="ezbizi-contact-value">';
        $out .= $href === ''
            ? esc_html($value)
            : '<a href="' . esc_url($href) . '">' . esc_html($value) . '</a>';
        $out .= '</span></div></div>';
    }

    return $out . '</div>';
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
        . $sel . ' .ezbizi-contact-item{display:flex;align-items:center;gap:10px}'
        . $sel . ' .ezbizi-contact-icon{display:inline-flex;align-items:center;justify-content:center;'
            . 'width:40px;height:40px;border-radius:8px;border:1px solid currentColor;flex:none}'
        . $sel . '.ezbizi-contact-compact .ezbizi-contact-icon{width:28px;height:28px;border:0}'
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

    if ($action === 'call') {
        $value = ezbizi_value('phone');
        $href = $value === '' ? '' : 'tel:' . ezbizi_dial($value);
        $text = __('Call us today', 'ezbizi');
    } elseif ($action === 'book') {
        $href = ezbizi_value('booking_url');
        $text = __('Book online', 'ezbizi');
    } else {
        $href = '#ezbizi-form';
        $text = __('Get a free estimate', 'ezbizi');
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

    $button = '<a class="ezbizi-cta-button" href="' . esc_url($href) . '">'
        . ($icon ? '<span class="ezbizi-cta-icon" aria-hidden="true">'
            . ezbizi_contact_icon($action === 'call' ? 'phone' : 'email') . '</span>' : '')
        . '<span class="ezbizi-cta-text">' . esc_html($text) . '</span></a>';

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

        return $out;
    }

    return $out . '<div class="ezbizi-cta ezbizi-cta-' . esc_attr($placement) . '" id="'
        . esc_attr($id) . '">' . $button . '</div>';
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
    $value = ezbizi_value('phone');

    if ($value === '') {
        return '';
    }

    $size = isset($preset['size']) ? (string) $preset['size'] : 'medium';
    $icon = ! empty($preset['icon']);
    $link = ! empty($preset['link']);
    $label = isset($preset['label']) ? (string) $preset['label'] : '';

    $id = ezbizi_widget_id('ezbizi-phone');
    $sizes = array('small' => '16px', 'medium' => '22px', 'large' => '30px');
    $font = isset($sizes[$size]) ? $sizes[$size] : $sizes['medium'];

    $out = '<style>#' . $id . '{display:inline-flex;align-items:center;gap:10px}'
        . '#' . $id . ' .ezbizi-phone-number{font-size:' . $font . ';font-weight:700;line-height:1.2}'
        . '#' . $id . ' a{color:inherit;text-decoration:none}'
        . '#' . $id . ' .ezbizi-phone-label{display:block;font-size:.8em;font-weight:400;opacity:.8}'
        . '</style>';

    $inner = ($label === '' ? '' : '<span class="ezbizi-phone-label">' . esc_html($label) . '</span>')
        . '<span class="ezbizi-phone-number">' . esc_html($value) . '</span>';

    $out .= '<div class="ezbizi-phone-box" id="' . esc_attr($id) . '">';

    if ($icon) {
        $out .= '<span class="ezbizi-phone-icon" aria-hidden="true">'
            . ezbizi_contact_icon('phone') . '</span>';
    }

    $out .= '<div>';
    $out .= $link
        ? '<a href="' . esc_url('tel:' . ezbizi_dial($value)) . '">' . $inner . '</a>'
        : $inner;

    return $out . '</div></div>';
}

// ── Review faces ────────────────────────────────────────────────────

/**
 * [ezbizi_review_faces] — A1 Tools' smiley widget, answered from EzBizi.
 *
 * Happy sends the visitor to the site's `review_link`. Sad opens a short form
 * that posts to the same public endpoint as [ezbizi_form], so the complaint
 * lands in Leads with this site's host on it instead of on Google.
 *
 * With no `review_link` the happy face still works: it says thank you instead
 * of offering a button to nowhere.
 */
function ezbizi_review_faces_shortcode($atts) {
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
    $link = ezbizi_value('review_link');
    $review = $link === '' ? '' : '<a class="ezbizi-rf-btn" href="' . esc_url($link)
        . '" target="_blank" rel="noopener noreferrer">' . $t('button') . '</a>';

    $id = ezbizi_widget_id('ezbizi-rf');
    $s = '#' . $id;
    $palette = ezbizi_widget_palette();
    $accent = esc_attr(ezbizi_widget_text($palette, 'accent', '#22B24C'));
    $on_accent = esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff'));
    $size = max(40, min(120, (int) (isset($p['face_size']) ? $p['face_size'] : 56)));
    $dark = isset($p['tone']) && $p['tone'] === 'dark';

    $out = '<style>' . $s . '{text-align:center;max-width:560px;margin:0 auto' . ($dark ? ';color:#fff' : '') . '}'
        . $s . ' h3{color:' . $accent . '}'
        . $s . ' p,' . $s . ' label,' . $s . ' .ezbizi-rf-face{color:inherit}'
        . $s . ' input,' . $s . ' textarea{background:#fff;color:#1b1b1b}'
        . $s . ' .ezbizi-rf-faces{display:flex;justify-content:center;gap:32px;margin:16px 0}'
        . $s . ' .ezbizi-rf-face{background:none;border:0;cursor:pointer;padding:8px;color:inherit;font:inherit}'
        . $s . ' .ezbizi-rf-face svg{display:block;margin:0 auto 6px;transition:transform .15s}'
        . $s . ' .ezbizi-rf-face:hover svg,' . $s . ' .ezbizi-rf-face:focus svg{transform:scale(1.1)}'
        . $s . ' .ezbizi-rf-face svg{width:' . $size . 'px;height:' . $size . 'px}'
        . $s . ' .ezbizi-rf-btn{display:inline-block;padding:12px 22px;border:0;border-radius:6px;background:' . $accent . ';color:' . $on_accent . ';text-decoration:none;font-weight:700;cursor:pointer}'
        . $s . ' form{text-align:left}'
        . $s . ' label{display:block;margin:10px 0 4px;font-weight:600}'
        . $s . ' input,' . $s . ' textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:6px;font:inherit}'
        . $s . ' .ezbizi-rf-hp{position:absolute;left:-9999px}'
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

    $field = function ($name, $label, $type) use ($id) {
        $fid = $id . '-' . $name;
        $input = $type === 'textarea'
            ? '<textarea id="' . $fid . '" name="' . $name . '" rows="4" required></textarea>'
            : '<input id="' . $fid . '" name="' . $name . '" type="' . $type . '" required />';

        return '<label for="' . $fid . '">' . esc_html($label) . '</label>' . $input;
    };

    $out .= '<div class="ezbizi-review-faces" id="' . esc_attr($id) . '">'
        . '<div data-step="pick"><h3>' . $t('title') . '</h3><p>' . $t('text') . '</p>'
        . '<div class="ezbizi-rf-faces">'
        . '<button type="button" class="ezbizi-rf-face" data-go="sad">' . $face(false) . $t('sad_label') . '</button>'
        . '<button type="button" class="ezbizi-rf-face" data-go="happy">' . $face(true) . $t('happy_label') . '</button>'
        . '</div></div>'
        . '<div data-step="happy" hidden><h3>' . $t('happy_title') . '</h3><p>' . $t('happy_text') . '</p>'
        . ($review === '' ? '<p>' . $t('thanks') . '</p>' : $review) . '</div>'
        . '<div data-step="sad" hidden><h3>' . $t('sad_title') . '</h3><p>' . $t('sad_text') . '</p>'
        . '<form novalidate>'
        . $field('name', 'Your name', 'text')
        . $field('phone', 'Phone', 'tel')
        . $field('email', 'Email', 'email')
        . $field('feedback', 'What happened?', 'textarea')
        . '<input class="ezbizi-rf-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" />'
        . '<p><button type="submit" class="ezbizi-rf-btn">' . $t('submit') . '</button></p>'
        . '<p class="ezbizi-rf-note" role="status" aria-live="polite"></p></form></div>'
        . '<div data-step="sent" hidden><p>' . $t('thanks') . '</p>'
        . ($review !== '' && ! empty($p['ask_public']) ? '<p>' . $t('public_prompt') . '</p>' . $review : '')
        . '</div></div>';

    $out .= "<script>(function(){
var w=document.getElementById(" . wp_json_encode($id) . ");if(!w)return;
function show(n){Array.prototype.forEach.call(w.querySelectorAll('[data-step]'),function(d){d.hidden=d.getAttribute('data-step')!==n;});}
Array.prototype.forEach.call(w.querySelectorAll('[data-go]'),function(b){b.addEventListener('click',function(){show(b.getAttribute('data-go'));});});
var f=w.querySelector('form'),note=w.querySelector('.ezbizi-rf-note');
f.addEventListener('submit',function(e){
  e.preventDefault();
  if(f.elements.website.value!==''){show('sent');return;}
  if(!f.checkValidity()){f.reportValidity();return;}
  var b=f.querySelector('button');b.disabled=true;
  var g=function(n){return f.elements[n].value;};
  var v={key:" . wp_json_encode($key) . ",name:g('name'),email:g('email'),phone:g('phone'),page_url:location.href,
    fields:{'Rating':'Not happy','Name':g('name'),'Phone':g('phone'),'Email':g('email'),'What happened?':g('feedback')}};
  fetch(" . wp_json_encode(ezbizi_api_base() . '/api/v1/web/form') . ",{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(v)})
  .then(function(r){if(!r.ok)throw new Error(r.status);show('sent');})
  .catch(function(){note.textContent='Sorry, that did not send. Please try again or call us.';b.disabled=false;});
});
})();</script>";

    return $out;
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
});
