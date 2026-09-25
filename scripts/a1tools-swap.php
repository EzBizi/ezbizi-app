<?php
/**
 * Replace every A1 Tools widget and shortcode on one WordPress site with its
 * EzBizi counterpart. Run inside the site:
 *
 *     wp eval-file a1tools-swap.php              # dry run: what would change
 *     wp eval-file a1tools-swap.php write        # does it, backing up first
 *
 * Jose, 2026-09-25: "Do a whole review of all the sites that we have which
 * are using a1tools widgets, we need to replace them all, so we can get rid of
 * a1tools", "make sure to import all the data as well into ezbizi so they
 * aren't left blank", "make sure it's all displayed correctly".
 *
 * ── What it carries across ──
 *
 * Content (a gallery's pictures, a carousel's videos, a form's questions) and
 * LOOK: each A1 widget's colours, type, borders and spacing become per-widget
 * overrides on the EzBizi widget (`ezo_<option>`), so a page keeps the look it
 * had without a company preset being bent to one page. A1's DEFAULTS count
 * too — most instances store only what differs from them — so settings are
 * read through Elementor with the widget's control defaults merged in, and
 * Elementor global colours are resolved to the hex they stand for.
 *
 * ── What it never does ──
 *
 * Change anything without a copy: every post it writes is saved first, whole,
 * under /tmp/a1swap/<host>/. Guess at a shortcode it does not know: those are
 * reported and left. Deactivate A1 Tools: that is a separate step, for a site
 * this reports clean.
 *
 * The data behind the widgets (values, team, stores, branches, Street View)
 * was imported into EzBizi before this runs; this only swaps what draws it.
 */

if (! defined('ABSPATH')) {
    exit;
}

$a1swap_write = in_array('write', isset($args) ? (array) $args : array(), true);
$a1swap_host = preg_replace('/^www\./', '', (string) parse_url(home_url(), PHP_URL_HOST));
$a1swap_report = array('host' => $a1swap_host, 'posts' => 0, 'widgets' => array(), 'shortcodes' => array(), 'unknown' => array(), 'left' => 0, 'form' => null);

// ── Values ──────────────────────────────────────────────────────────

/** A1 site variable → the EzBizi shortcode that prints the same thing. */
function a1swap_var_shortcode($key) {
    $map = array(
        'phone_primary' => 'phone', 'phone_secondary' => 'phone_secondary',
        'email_primary' => 'email', 'email_secondary' => 'email_secondary',
        'business_name' => 'company_name', 'tagline' => 'tagline',
        'full_address' => 'address', 'address_single' => 'address', 'address_formatted' => 'address',
        'address_line1' => 'address_line1', 'address_line2' => 'address_line2',
        'city' => 'city', 'city_name' => 'city', 'zip' => 'postal_code', 'country' => 'country',
        'state' => '@state_name', 'location_name' => '@state_name',
        'google_maps_url' => 'maps_url', 'review_url' => 'google_review_link',
        'city_url' => 'city_link', 'state_url' => 'state_link',
        'licence' => 'license', 'timezone' => 'timezone', 'operating_hours' => 'hours',
        'latitude' => 'latitude', 'longitude' => 'longitude',
    );

    foreach (array('facebook', 'instagram', 'youtube', 'twitter', 'linkedin', 'tiktok', 'yelp', 'google_business', 'pinterest', 'bbb', 'nextdoor', 'houzz', 'angi', 'thumbtack', 'homeadvisor') as $net) {
        $map[$net . '_url'] = $net;
    }

    if (! isset($map[$key])) {
        return null;
    }

    return $map[$key][0] === '@' ? '[ezbizi_' . substr($map[$key], 1) . ']' : '[ezbizi_' . $map[$key] . ']';
}

/** A1 shortcode (name, attributes) → its EzBizi replacement, or null when there is none. */
function a1swap_shortcode($name, $attrs) {
    $fixed = array(
        'website_url' => '[ezbizi_website_url]',
        'post_title' => '[ezbizi_post_title]', 'page_title' => '[ezbizi_post_title]',
        'viewpoint' => '[ezbizi_street_view]',
        'city_phone' => '[ezbizi_phone]', 'location_phone' => '[ezbizi_phone]',
        'city_name' => '[ezbizi_city]', 'location_city' => '[ezbizi_city]',
        'city_address' => '[ezbizi_address]', 'address' => '[ezbizi_address]',
        'location_state' => '[ezbizi_state_name]',
        'state_link' => '[ezbizi_state_anchor]',
        'city_web' => '[ezbizi_city_link]', 'seo_city_web' => '[ezbizi_city_link]',
        'state_web' => '[ezbizi_state_link]',
    );

    if ($name === 'var') {
        return preg_match('/key\s*=\s*["\'\x{201C}\x{201D}]?([a-z0-9_]+)/u', $attrs, $m) ? a1swap_var_shortcode($m[1]) : null;
    }

    return isset($fixed[$name]) ? $fixed[$name] : null;
}

/**
 * Every A1 shortcode in a string, replaced. Handles the forms they are
 * stored in: plain, HTML-escaped (`&quot;`, curly quotes), and inside an
 * Elementor dynamic tag, where the shortcode sits URL-encoded in JSON.
 */
function a1swap_text($s, &$report) {
    if (! is_string($s) || stripos($s, 'a1tools') === false) {
        return $s;
    }

    // Dynamic tags first: [elementor-tag … settings="%7B…%7D"].
    $s = preg_replace_callback('/(settings=\\\\?")([^"\\\\]*%5Ba1tools_[^"\\\\]*)(\\\\?")/', function ($m) use (&$report) {
        $decoded = rawurldecode($m[2]);
        $json = json_decode($decoded, true);

        if (! is_array($json)) {
            return $m[0];
        }

        array_walk_recursive($json, function (&$v) use (&$report) {
            $v = a1swap_text($v, $report);
        });

        return $m[1] . rawurlencode(wp_json_encode($json)) . $m[3];
    }, $s);

    // A link written as `http://[a1tools_seo_city_web]` was broken: that
    // shortcode no longer exists. The city link carries its own scheme.
    $s = preg_replace('~https?://(\[a1tools_(?:seo_city_web|city_web|state_web|website_url)[^\]]*\])~', '$1', $s);

    return preg_replace_callback('/\[a1tools_([a-z0-9_]+)((?:[^\]"]|"[^"]*")*)\]/u', function ($m) use (&$report) {
        $attrs = html_entity_decode($m[2], ENT_QUOTES);
        $with = a1swap_shortcode($m[1], $attrs);

        if ($with === null) {
            $report['unknown'][$m[1]] = (isset($report['unknown'][$m[1]]) ? $report['unknown'][$m[1]] : 0) + 1;

            return $m[0];
        }

        $report['shortcodes'][$m[1]] = (isset($report['shortcodes'][$m[1]]) ? $report['shortcodes'][$m[1]] : 0) + 1;

        return $with;
    }, $s);
}

// ── Reading A1's settings as they render ────────────────────────────

/** The Elementor kit's global colours, id → hex. */
function a1swap_globals() {
    static $colors = null;

    if ($colors !== null) {
        return $colors;
    }

    $colors = array();

    try {
        $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();

        foreach (array('system_colors', 'custom_colors') as $set) {
            foreach ((array) $kit->get_settings($set) as $c) {
                if (! empty($c['_id']) && ! empty($c['color'])) {
                    $colors[$c['_id']] = $c['color'];
                }
            }
        }
    } catch (\Throwable $e) {
        // No kit: global colours stay unresolved, and the setting is skipped.
    }

    return $colors;
}

/** Settings with the widget's control defaults merged in, and globals resolved. */
function a1swap_settings($element) {
    $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array();

    try {
        $widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance($element);

        if ($widget) {
            $settings = array_merge((array) $widget->get_settings(), $settings);
        }
    } catch (\Throwable $e) {
        // A1 not loadable: the stored settings are all there is.
    }

    foreach ((array) (isset($settings['__globals__']) ? $settings['__globals__'] : array()) as $key => $ref) {
        if (is_string($ref) && preg_match('/colors\?id=([a-z0-9_-]+)/i', $ref, $m) && isset(a1swap_globals()[$m[1]])) {
            $settings[$key] = a1swap_globals()[$m[1]];
        }
    }

    return $settings;
}

/** A size ({unit,size}) in whole pixels, or null. */
function a1swap_px($v) {
    if (! is_array($v) || ! isset($v['size']) || ! is_numeric($v['size'])) {
        return null;
    }

    $unit = isset($v['unit']) ? $v['unit'] : 'px';
    $n = (float) $v['size'];

    return $unit === 'px' || $unit === '' ? (int) round($n) : (in_array($unit, array('em', 'rem'), true) ? (int) round($n * 16) : null);
}

/** A box ({top,right,bottom,left,unit}) as [vertical, horizontal] pixels, or null. */
function a1swap_dims($v) {
    if (! is_array($v) || ! isset($v['top'])) {
        return null;
    }

    $unit = isset($v['unit']) ? $v['unit'] : 'px';
    $k = $unit === 'px' || $unit === '' ? 1 : (in_array($unit, array('em', 'rem'), true) ? 16 : null);

    if ($k === null || ($v['top'] === '' && $v['left'] === '')) {
        return null;
    }

    return array((int) round((float) $v['top'] * $k), (int) round((float) $v['left'] * $k));
}

function a1swap_color($v) {
    return is_string($v) && preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? strtolower($v) : null;
}

/**
 * Collects overrides. `$o->set('button_bg', $color)` only when there is a
 * value, so a missing A1 setting leaves EzBizi's default rather than writing
 * an empty one.
 */
final class A1Swap_Overrides {
    public $out = array();

    public function set($key, $value) {
        if ($value === null || $value === '' || $value === array()) {
            return $this;
        }

        $this->out[$key] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;

        return $this;
    }

    public function color($key, $value) {
        return $this->set($key, a1swap_color($value));
    }

    /** Elementor typography group `<prefix>_typography_*` → `<part>_font_*`. */
    public function type($s, $prefix, $part) {
        if (($s[$prefix . '_typography_typography'] ?? '') !== 'custom') {
            return $this;
        }

        $this->set($part . '_font_family', $s[$prefix . '_typography_font_family'] ?? null);
        $this->set($part . '_font_size', a1swap_px($s[$prefix . '_typography_font_size'] ?? null));
        $this->set($part . '_font_weight', in_array((string) ($s[$prefix . '_typography_font_weight'] ?? ''), array('300', '400', '500', '600', '700', '800', '900'), true) ? $s[$prefix . '_typography_font_weight'] : null);
        $this->set($part . '_text_transform', in_array($s[$prefix . '_typography_text_transform'] ?? '', array('none', 'uppercase', 'lowercase', 'capitalize'), true) ? $s[$prefix . '_typography_text_transform'] : null);
        $this->set($part . '_letter_spacing', a1swap_px($s[$prefix . '_typography_letter_spacing'] ?? null));
        $lh = $s[$prefix . '_typography_line_height'] ?? null;

        if (is_array($lh) && is_numeric($lh['size'] ?? null) && ($lh['unit'] ?? '') === 'em') {
            $this->set($part . '_line_height', (int) round($lh['size'] * 100));
        }

        return $this;
    }

    /** A border group (`<prefix>_border`, `_width`, `_color`) on a part. */
    public function border($s, $prefix, $part) {
        if (($s[$prefix . '_border'] ?? '') === 'solid') {
            $w = a1swap_dims($s[$prefix . '_width'] ?? null);
            $this->set($part . '_border_width', $w ? max($w[0], $w[1]) : 1);
            $this->color($part . '_border_color', $s[$prefix . '_color'] ?? null);
        } elseif (($s[$prefix . '_border'] ?? '') === 'none') {
            $this->set($part . '_border_width', 0);
        }

        return $this;
    }

    public function padding($v, $part) {
        $d = a1swap_dims($v);

        if ($d) {
            $this->set($part . '_padding_y', $d[0])->set($part . '_padding_x', $d[1]);
        }

        return $this;
    }

    public function radius($v, $part) {
        $d = a1swap_dims($v);

        return $d ? $this->set($part . '_radius', $d[0]) : $this;
    }
}

// ── Widgets ─────────────────────────────────────────────────────────

/** The CTA: action, words, icon, colours, type, animation, placement. */
function a1swap_cta($s) {
    $o = new A1Swap_Overrides();
    $action = array('phone' => 'call', 'email' => 'email', 'url' => 'url')[$s['cta_action'] ?? 'phone'] ?? 'call';
    $o->set('action', $action);

    if ($action === 'url') {
        $url = is_array($s['custom_url'] ?? null) ? ($s['custom_url']['url'] ?? '') : '';
        $o->set('url', $url);
        $o->set('new_tab', ! empty($s['custom_url']['is_external']));
    }

    if (($s['use_custom_text'] ?? '') === 'yes' && trim((string) ($s['custom_text'] ?? '')) !== '') {
        $o->set('text', $s['custom_text'])->set('label', 'words');
    } else {
        $o->set('label', 'value');
    }

    $icon = is_array($s['icon'] ?? null) ? (string) ($s['icon']['value'] ?? '') : '';
    $o->set('icon', $icon !== '');

    foreach (array('phone' => 'phone', 'envelope' => 'email', 'calendar' => 'calendar', 'star' => 'star', 'arrow' => 'arrow', 'comment' => 'chat', 'whatsapp' => 'chat') as $needle => $kind) {
        if ($icon !== '' && stripos($icon, $needle) !== false) {
            $o->set('icon_type', $kind);
            break;
        }
    }

    $o->set('icon_position', in_array($s['icon_position'] ?? '', array('left', 'right'), true) ? $s['icon_position'] : null);
    $o->set('icon_size', a1swap_px($s['icon_size'] ?? null));
    $o->set('icon_gap', a1swap_px($s['icon_spacing'] ?? ($s['icon_gap'] ?? null)));

    $align = (string) ($s['alignment'] ?? '');
    $wide = ($s['full_width'] ?? '') === 'yes' || $align === 'justify'
        || stripos((string) ($s['custom_css'] ?? ''), 'width: 100%') !== false;
    $o->set('full_width', $wide);
    $o->set('box_align', in_array($align, array('left', 'center', 'right'), true) ? $align : null);

    $o->color('button_bg', $s['bg_color'] ?? null)->color('button_color', $s['text_color'] ?? null)
        ->color('button_hover_bg', $s['hover_bg_color'] ?? null)->color('button_hover_color', $s['hover_text_color'] ?? null)
        ->padding($s['button_padding'] ?? null, 'button')->radius($s['border_radius'] ?? null, 'button')
        ->border($s, 'button_border', 'button')->type($s, 'button_typography', 'button');

    if (($s['button_shadow_box_shadow_type'] ?? '') === 'yes') {
        $o->set('button_shadow', 'soft');
    }

    $animation = (string) ($s['button_animation'] ?? '');

    if (in_array($animation, array('pulse', 'shake', 'bounce', 'glow'), true)) {
        $o->set('animation', $animation)
            ->set('animation_speed', array('slow' => 3, 'fast' => 1)[$s['animation_speed'] ?? ''] ?? 2)
            ->set('animate_on', ($s['animation_hover_only'] ?? '') === 'yes' ? 'hover' : 'always')
            ->color('glow_color', $s['glow_color'] ?? null);
    }

    if (($s['sticky'] ?? '') === 'bottom') {
        $o->set('placement', 'bar');
    }

    return $o->out;
}

/** One A1 info box (phone, email or location) as Contact details with that one row. */
function a1swap_info_box($s, $kind) {
    $o = new A1Swap_Overrides();

    foreach (array('phone', 'email', 'address') as $k) {
        $o->set($k, $k === $kind);
    }

    $o->set('hours', false)->set('license', false)->set('layout', 'stack');
    $title = trim((string) ($s['title_text'] ?? ''));
    $o->set('labels', $title !== '');

    if ($title !== '') {
        $o->set($kind . '_title', $title);
    }

    $o->set('links', ($s['enable_link'] ?? 'yes') !== '');
    a1swap_info_style($o, $s);

    return $o->out;
}

/** The styling A1's info boxes and contact block share. */
function a1swap_info_style($o, $s) {
    $o->color('icon_color', $s['icon_color'] ?? null)->color('icon_hover_color', $s['hover_icon_color'] ?? null)
        ->color('icon_bg', $s['icon_bg_color'] ?? null)
        ->set('icon_size', a1swap_px($s['icon_size'] ?? null))
        ->set('icon_padding', ($d = a1swap_dims($s['icon_padding'] ?? null)) ? $d[0] : null)
        ->radius($s['icon_border_radius'] ?? null, 'icon')->border($s, 'icon_border', 'icon')
        ->set('icon_gap', a1swap_px($s['icon_gap'] ?? null))
        ->color('label_color', $s['title_color'] ?? null)->color('label_hover_color', $s['hover_title_color'] ?? null)
        ->type($s, 'title_typography', 'label')
        ->color('value_color', $s['value_color'] ?? null)->color('value_hover_color', $s['hover_value_color'] ?? null)
        ->type($s, 'value_typography', 'value')
        ->color('box_bg', $s['box_bg_color'] ?? null)->padding($s['box_padding'] ?? null, 'box')
        ->radius($s['box_border_radius'] ?? null, 'box')->border($s, 'box_border', 'box');

    if (($s['box_shadow_box_shadow_type'] ?? '') === 'yes') {
        $o->set('box_shadow', 'soft');
    }

    if (in_array($s['alignment'] ?? '', array('left', 'center', 'right'), true)) {
        $o->set('box_align', $s['alignment']);
    }
}

function a1swap_contact_info($s) {
    $o = new A1Swap_Overrides();
    $o->set('phone', ($s['show_phone'] ?? 'yes') === 'yes')->set('email', ($s['show_email'] ?? 'yes') === 'yes')
        ->set('address', ($s['show_location'] ?? 'yes') === 'yes')->set('hours', ($s['show_hours'] ?? '') === 'yes')
        ->set('license', ($s['show_license'] ?? '') === 'yes')
        ->set('phone_title', $s['phone_title'] ?? null)->set('email_title', $s['email_title'] ?? null)
        ->set('address_title', $s['location_title'] ?? null)->set('hours_title', $s['hours_title'] ?? null)
        ->set('closed_text', $s['hours_closed_text'] ?? null)
        ->color('day_color', $s['hours_day_color'] ?? null)->color('time_color', $s['hours_time_color'] ?? null);

    if (in_array($s['layout'] ?? '', array('stack', 'row', 'grid', 'inline', 'compact'), true)) {
        $o->set('layout', $s['layout']);
    }

    // A1 hides a section's title when it is empty; EzBizi hides all titles at
    // once, so an all-empty set is labels off.
    if (trim(($s['phone_title'] ?? '') . ($s['email_title'] ?? '') . ($s['location_title'] ?? '')) === '') {
        $o->set('labels', false);
    }

    a1swap_info_style($o, $s);

    return $o->out;
}

function a1swap_social($s) {
    $o = new A1Swap_Overrides();
    $style = (string) ($s['icon_style'] ?? '');
    $o->set('style', $style === 'custom' || $style === 'official' ? 'solid' : (in_array($style, array('plain', 'solid', 'outline'), true) ? $style : null));
    $o->set('colors', $style === 'official' ? 'brand' : null);
    $o->set('shape', in_array($s['shape'] ?? '', array('circle', 'square', 'rounded'), true) ? $s['shape'] : null);
    $glyph = a1swap_px($s['icon_size'] ?? null);
    $box = a1swap_px($s['size'] ?? null);
    $o->set('size', $glyph !== null ? max(16, min(64, $glyph)) : null);

    if ($glyph !== null && $box !== null && $box > $glyph) {
        $o->set('icon_padding', (int) floor(($box - $glyph) / 2));
    }

    $o->set('gap', a1swap_px($s['spacing'] ?? null))
        ->color('icon_bg', $s['custom_color'] ?? null)->color('icon_color', $s['custom_icon_color'] ?? null)
        ->set('icon_border_width', ($d = a1swap_dims($s['custom_border_width'] ?? null)) ? max($d) : null);

    if (in_array($s['alignment'] ?? '', array('left', 'center', 'right'), true)) {
        $o->set('box_align', $s['alignment']);
    }

    return $o->out;
}

function a1swap_city_list($s) {
    $o = new A1Swap_Overrides();
    $groups = ($s['show_groups'] ?? 'yes') === 'yes';
    $o->set('group', $groups)
        ->set('layout', $groups && ($s['enable_accordion'] ?? '') === 'yes' ? 'accordion' : (($s['layout'] ?? '') === 'list' ? 'list' : 'columns'))
        ->set('columns', is_numeric($s['columns'] ?? null) ? max(1, min(6, (int) $s['columns'])) : null)
        ->set('search', ($s['show_search'] ?? '') === 'yes')->set('search_placeholder', $s['search_placeholder'] ?? null)
        ->set('show_state', ($s['append_state'] ?? '') === 'yes')
        ->set('count', $groups && ($s['show_city_count'] ?? '') === 'yes')
        ->set('bullets', ($s['show_bullets'] ?? '') === 'yes')
        ->set('column_gap', a1swap_px($s['column_gap'] ?? null))
        ->color('link_color', $s['item_text_color'] ?? null)->color('link_hover_color', $s['item_hover_color'] ?? null)
        ->type($s, 'item_typography', 'link')
        ->color('heading_color', $s['group_heading_color'] ?? null)->type($s, 'group_heading_typography', 'heading')
        ->color('group_bg', $s['group_heading_bg_color'] ?? null)->padding($s['group_heading_padding'] ?? null, 'group')
        ->border($s, 'group_heading_border', 'group')
        ->color('arrow_color', $s['accordion_icon_color'] ?? null)->set('arrow_size', a1swap_px($s['accordion_icon_size'] ?? null))
        ->color('count_color', $s['city_count_color'] ?? null)->color('bullet_color', $s['bullet_color'] ?? null)
        ->color('search_bg', $s['search_bg_color'] ?? null)->color('search_color', $s['search_text_color'] ?? null)
        ->border($s, 'search_border', 'search')->type($s, 'search_typography', 'search')
        ->padding($s['container_padding'] ?? null, 'box');

    return $o->out;
}

function a1swap_team($s) {
    $o = new A1Swap_Overrides();
    $o->set('photo', ($s['photo_shape'] ?? '') === 'circle' ? 'circle' : 'square')
        ->set('bio', ($s['show_bio'] ?? 'yes') === 'yes')
        ->set('columns', is_numeric($s['columns'] ?? null) ? (int) $s['columns'] : null)
        ->color('name_color', $s['name_color'] ?? null)->type($s, 'name_typography', 'name')
        ->color('role_color', $s['role_color'] ?? null)->type($s, 'role_typography', 'role')
        ->color('heading_color', $s['category_heading_color'] ?? null)->type($s, 'category_heading_typography', 'heading')
        ->border($s, 'photo_border', 'photo');

    return $o->out;
}

function a1swap_franchise($s) {
    $o = new A1Swap_Overrides();
    $o->set('columns', is_numeric($s['columns'] ?? null) ? max(1, min(4, (int) $s['columns'])) : null)
        ->set('address', ($s['show_address'] ?? 'yes') === 'yes')->set('map', ($s['show_map'] ?? 'yes') === 'yes')
        ->set('directions', ($s['show_directions'] ?? 'yes') === 'yes');

    return $o->out;
}

function a1swap_stores($s) {
    $o = new A1Swap_Overrides();
    $o->set('search', ($s['show_search'] ?? 'yes') === 'yes')->set('images', ($s['show_images'] ?? 'yes') === 'yes')
        ->color('card_bg', $s['card_bg_color'] ?? null)->color('name_color', $s['card_text_color'] ?? null)
        ->type($s, 'name_typography', 'name')
        ->color('link_color', $s['card_accent_color'] ?? null)->color('link_hover_color', $s['card_accent_hover_color'] ?? null)
        ->type($s, 'link_typography', 'link');

    if (($s['card_shadow_box_shadow_type'] ?? '') === 'yes') {
        $o->set('card_shadow', 'soft');
    }

    return $o->out;
}

/** The form's look; its questions go to the site's form config (see the report). */
function a1swap_form_look($s) {
    $o = new A1Swap_Overrides();
    $o->color('box_bg', $s['container_bg'] ?? null)->border($s, 'container_border', 'box')
        ->color('title_color', $s['title_color'] ?? null)->type($s, 'title_typography', 'title')
        ->color('subtitle_color', $s['subtitle_color'] ?? null)->type($s, 'subtitle_typography', 'subtitle')
        ->color('label_color', $s['label_color'] ?? null)->type($s, 'label_typography', 'label')
        ->color('input_color', $s['input_text_color'] ?? null)->color('input_bg', $s['input_bg'] ?? null)
        ->border($s, 'input_border', 'input')->type($s, 'input_typography', 'input')
        ->color('submit_bg', $s['submit_bg'] ?? null)->color('submit_color', $s['submit_color'] ?? null)
        ->color('submit_hover_bg', $s['submit_bg_hover'] ?? null)->color('submit_hover_color', $s['submit_color_hover'] ?? null)
        ->type($s, 'submit_typography', 'submit')
        ->color('consent_color', $s['consent_text_color'] ?? null)->type($s, 'consent_typography', 'consent')
        ->set('button_width', ($s['submit_alignment'] ?? '') === 'full' ? 'full' : null);

    return $o->out;
}

/** The questions and words of an A1 form, as EzBizi's form config. */
function a1swap_form_config($s) {
    $fields = array();
    $width = function ($v) {
        $v = (int) $v;

        foreach (array(100, 75, 66, 50, 33, 25) as $w) {
            if ($v >= $w) {
                return $w;
            }
        }

        return 100;
    };
    $types = array('email' => 'email', 'phone' => 'tel');

    foreach ((array) ($s['standard_fields'] ?? array()) as $f) {
        $fields[] = array(
            'label' => (string) ($f['field_label'] ?? ''), 'type' => $types[$f['field_name'] ?? ''] ?? 'text',
            'required' => ($f['field_required'] ?? 'yes') !== 'no', 'placeholder' => (string) ($f['field_placeholder'] ?? ''),
            'width' => $width($f['field_width'] ?? 100),
        );
    }

    foreach ((array) ($s['custom_fields'] ?? array()) as $f) {
        $type = (string) ($f['field_type'] ?? 'text');
        $one = array(
            'label' => (string) ($f['field_label'] ?? ''),
            'type' => in_array($type, array('text', 'textarea', 'email', 'tel', 'select', 'number', 'date', 'time', 'checkbox', 'radio', 'hidden'), true) ? $type : 'text',
            'required' => ($f['field_required'] ?? '') === 'yes', 'placeholder' => (string) ($f['field_placeholder'] ?? ''),
            'width' => $width($f['field_width'] ?? 100),
        );

        if (trim((string) ($f['field_options'] ?? '')) !== '') {
            $one['options'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $f['field_options'])))));
        }

        $fields[] = $one;
    }

    return array(
        'title' => (string) ($s['form_title'] ?? ''), 'subtitle' => (string) ($s['form_subtitle'] ?? ''),
        'button' => (string) ($s['submit_label'] ?? 'Send'),
        'thanks' => (string) ($s['success_message'] ?? ''), 'error' => (string) ($s['error_message'] ?? ''),
        'antibot' => ($s['antibot_enabled'] ?? '') === 'yes', 'antibot_label' => (string) ($s['antibot_label'] ?? ''),
        'consent_on' => ($s['consent_enabled'] ?? '') === 'yes',
        // Links survive as [words](url): an SMS opt-in without its Privacy
        // Policy and SMS Terms links is not the opt-in A1 showed.
        'consent' => trim(wp_strip_all_tags(preg_replace(
            '~<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',
            '[$2]($1)',
            // A1 fills these in as it renders, from its own fallbacks.
            strtr((string) ($s['consent_text'] ?? ''), array(
                '{{privacy_url}}' => ! empty($s['consent_privacy_url']['url']) ? $s['consent_privacy_url']['url'] : home_url('/privacy-policy/'),
                '{{sms_terms_url}}' => ! empty($s['consent_sms_terms_url']['url']) ? $s['consent_sms_terms_url']['url'] : home_url('/terms-and-conditions/'),
                '{{help_phone}}' => (string) ($s['consent_help_phone'] ?? ''),
            ))
        ))),
        'fields' => $fields,
    );
}

/**
 * One element: an A1 widget becomes EzBizi's, and every string setting of any
 * element has its A1 shortcodes replaced.
 */
function a1swap_element(&$e, &$report) {
    $type = isset($e['widgetType']) ? (string) $e['widgetType'] : '';
    $keep_all = array('a1tools_gallery' => 'ezbizi-gallery', 'a1tools_video_carousel' => 'ezbizi-video-carousel');
    $mapped = array(
        'a1tools_cta' => array('ezbizi-cta', 'a1swap_cta'),
        'a1tools_phone' => array('ezbizi-contact-info', function ($s) { return a1swap_info_box($s, 'phone'); }),
        'a1tools_email' => array('ezbizi-contact-info', function ($s) { return a1swap_info_box($s, 'email'); }),
        'a1tools_location' => array('ezbizi-contact-info', function ($s) { return a1swap_info_box($s, 'address'); }),
        'a1tools_contact_info' => array('ezbizi-contact-info', 'a1swap_contact_info'),
        'a1tools_social_icons' => array('ezbizi-social-links', 'a1swap_social'),
        'a1tools_city_list' => array('ezbizi-city-list', 'a1swap_city_list'),
        'a1tools_team' => array('ezbizi-team', 'a1swap_team'),
        'a1tools_franchise' => array('ezbizi-branches', 'a1swap_franchise'),
        'a1tools_store_locator' => array('ezbizi-store-locator', 'a1swap_stores'),
        'a1tools-web-form' => array('ezbizi-form', 'a1swap_form_look'),
        'a1tools_review_funnel' => array('ezbizi-review-faces', function ($s) { return array(); }),
        // No live page uses these four; mapped so a page that does is not left behind.
        'a1tools_services' => array('ezbizi-services', function ($s) {
            $o = new A1Swap_Overrides();
            return $o->set('layout', ($s['layout'] ?? '') === 'list' ? 'list' : 'grid')->set('columns', is_numeric($s['columns'] ?? null) ? max(1, min(4, (int) $s['columns'])) : null)
                ->set('description', ($s['show_description'] ?? 'yes') === 'yes')->set('icon', ($s['show_icon'] ?? 'yes') === 'yes')
                ->set('image', ($s['show_image'] ?? 'yes') === 'yes')->set('phone', ($s['show_phone'] ?? '') === 'yes')
                ->set('new_tab', ($s['link_target'] ?? '') === '_blank')->out;
        }),
        'a1tools_reviews' => array('ezbizi-reviews', function ($s) {
            $o = new A1Swap_Overrides();
            return $o->set('columns', is_numeric($s['columns'] ?? null) ? max(1, min(4, (int) $s['columns'])) : null)
                ->set('stars', ($s['show_stars'] ?? 'yes') === 'yes')->set('show_source', ($s['show_source'] ?? 'yes') === 'yes')
                ->set('date', ($s['show_date'] ?? 'yes') === 'yes')->set('photo', ($s['show_image'] ?? 'yes') === 'yes')
                ->set('limit', is_numeric($s['limit'] ?? null) ? (int) $s['limit'] : null)->out;
        }),
        'a1tools_before_after' => array('ezbizi-before-after', function ($s) {
            $o = new A1Swap_Overrides();
            return $o->set('columns', is_numeric($s['columns'] ?? null) ? max(1, min(4, (int) $s['columns'])) : null)
                ->set('title', ($s['show_title'] ?? 'yes') === 'yes')->set('description', ($s['show_description'] ?? 'yes') === 'yes')->out;
        }),
        'a1tools_faq' => array('ezbizi-faq', function ($s) {
            $o = new A1Swap_Overrides();
            return $o->set('category', $s['category'] ?? null)->set('show_category', ($s['show_category'] ?? '') === 'yes')
                ->set('schema', ($s['enable_schema'] ?? 'yes') === 'yes')->out;
        }),
        'a1tools_smiley_review' => array('ezbizi-review-faces', function ($s) { return array('ezbizi_preset' => 'reviews'); }),
    );

    if (isset($keep_all[$type])) {
        $e['widgetType'] = $keep_all[$type];
        $report['widgets'][$type] = ($report['widgets'][$type] ?? 0) + 1;
    } elseif (isset($mapped[$type])) {
        $s = a1swap_settings($e);

        if ($type === 'a1tools-web-form' && $report['form'] === null) {
            $report['form'] = a1swap_form_config($s);
        }

        // Elementor's own settings stay: margins, visibility per device,
        // motion, custom attributes. The A1 widget's own do not.
        $settings = array('ezbizi_preset' => '');

        foreach ((array) ($e['settings'] ?? array()) as $k => $v) {
            if ((strpos($k, '_') === 0 && strpos($k, '__') !== 0) || strpos($k, 'hide_') === 0) {
                $settings[$k] = $v;
            }
        }

        foreach (call_user_func($mapped[$type][1], $s) as $k => $v) {
            $settings[$k === 'ezbizi_preset' ? $k : 'ezo_' . $k] = $v;
        }

        $e['widgetType'] = $mapped[$type][0];
        $e['settings'] = $settings;
        $report['widgets'][$type] = ($report['widgets'][$type] ?? 0) + 1;
    } elseif (strpos($type, 'a1tools') === 0) {
        $report['unknown']['widget:' . $type] = ($report['unknown']['widget:' . $type] ?? 0) + 1;
    }

    if (isset($e['settings']) && is_array($e['settings'])) {
        array_walk_recursive($e['settings'], function (&$v) use (&$report) {
            $v = a1swap_text($v, $report);
        });
    }

    foreach ($e['elements'] ?? array() as $i => $child) {
        a1swap_element($e['elements'][$i], $report);
    }
}

// ── The site ────────────────────────────────────────────────────────

global $wpdb;
$a1swap_dir = '/tmp/a1swap/' . $a1swap_host;

$ids = $wpdb->get_col("SELECT DISTINCT p.ID FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
    WHERE p.post_status IN ('publish','draft','private','future','pending')
      AND (p.post_content LIKE '%a1tools%' OR (m.meta_value LIKE '%a1tools%' AND m.meta_key NOT LIKE '\\_elementor\\_css%' AND m.meta_key <> '_elementor_element_cache'))");

foreach ($ids as $id) {
    $post = get_post($id);
    $changed = false;
    $backup = array('ID' => (int) $id, 'post_content' => $post->post_content, 'meta' => array());

    // Elementor layout.
    $raw = get_post_meta($id, '_elementor_data', true);

    if (is_string($raw) && stripos($raw, 'a1tools') !== false) {
        $data = json_decode($raw, true);

        if (is_array($data)) {
            foreach ($data as $i => $el) {
                a1swap_element($data[$i], $a1swap_report);
            }

            $new = wp_json_encode($data);

            if ($new !== $raw) {
                $backup['meta']['_elementor_data'] = $raw;
                $changed = true;

                if ($a1swap_write) {
                    update_post_meta($id, '_elementor_data', wp_slash($new));
                }
            }
        }
    }

    // Content outside Elementor.
    $content = a1swap_text($post->post_content, $a1swap_report);

    if ($content !== $post->post_content) {
        $changed = true;

        if ($a1swap_write) {
            $wpdb->update($wpdb->posts, array('post_content' => $content), array('ID' => $id));
        }
    }

    // Any other field carrying a shortcode (a theme's "description-4", say).
    foreach ($wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_elementor_data','_elementor_element_cache') AND meta_key NOT LIKE %s AND meta_value LIKE %s",
        $id, $wpdb->esc_like('_elementor_css') . '%', '%a1tools%'
    )) as $meta) {
        $value = maybe_unserialize($meta->meta_value);

        if (is_array($value)) {
            array_walk_recursive($value, function (&$v) use (&$a1swap_report) {
                $v = a1swap_text($v, $a1swap_report);
            });
        } else {
            $value = a1swap_text($value, $a1swap_report);
        }

        if (maybe_serialize($value) !== $meta->meta_value) {
            $backup['meta'][$meta->meta_key] = $meta->meta_value;
            $changed = true;

            if ($a1swap_write) {
                $wpdb->update($wpdb->postmeta, array('meta_value' => maybe_serialize($value)), array('meta_id' => $meta->meta_id));
            }
        }
    }

    if (! $changed) {
        continue;
    }

    $a1swap_report['posts']++;

    if ($a1swap_write) {
        if (! is_dir($a1swap_dir)) {
            mkdir($a1swap_dir, 0700, true);
        }

        file_put_contents($a1swap_dir . '/' . $id . '.json', wp_json_encode($backup));
        delete_post_meta($id, '_elementor_css');
        delete_post_meta($id, '_elementor_element_cache');
        clean_post_cache($id);
    }
}

if ($a1swap_write) {
    try {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    } catch (\Throwable $e) {
    }
}

// What is still there, after (or, in a dry run, before) the swap.
$a1swap_report['left'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
    WHERE p.post_status = 'publish' AND (p.post_content LIKE '%a1tools%' OR (m.meta_value LIKE '%a1tools%' AND m.meta_key NOT LIKE '\\_elementor\\_css%' AND m.meta_key <> '_elementor_element_cache'))");
$a1swap_report['mode'] = $a1swap_write ? 'write' : 'dry';

echo 'SWAP ', wp_json_encode($a1swap_report), "\n";
