<?php
/**
 * [ezbizi_team], [ezbizi_branches] and [ezbizi_store_locator]: the widgets
 * that draw a LIST EzBizi holds for the company, rather than one value.
 *
 * The lists arrive in the payload beside the values (`team`, and `locations`
 * split into this site's branches and the company's stores), so they refresh
 * with everything else and a site EzBizi cannot reach keeps its last good
 * copy. Each draws nothing when its list is empty, like the other widgets: a
 * heading over no people is worse on a live page than no heading.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** One of the payload's lists, or an empty one. */
function ezbizi_list($which) {
    $payload = ezbizi_payload();

    if ($which === 'team') {
        return isset($payload['team']) && is_array($payload['team']) ? $payload['team'] : array();
    }

    return isset($payload['locations'][$which]) && is_array($payload['locations'][$which])
        ? $payload['locations'][$which]
        : array();
}

/** A string field of a list row. */
function ezbizi_row($row, $key) {
    return isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
}

/** A location's address on one line. */
function ezbizi_location_address($l) {
    $street = implode(', ', array_filter(array(ezbizi_row($l, 'address_line1'), ezbizi_row($l, 'address_line2'))));
    $tail = trim(ezbizi_row($l, 'state') . ' ' . ezbizi_row($l, 'postal_code'));

    return implode(', ', array_filter(array($street, ezbizi_row($l, 'city'), $tail)));
}

/** Where "Get directions" goes: the location's own Maps link, else a search for it. */
function ezbizi_location_directions($l) {
    if (ezbizi_row($l, 'maps_url') !== '') {
        return ezbizi_row($l, 'maps_url');
    }

    $to = ezbizi_row($l, 'latitude') !== '' && ezbizi_row($l, 'longitude') !== ''
        ? ezbizi_row($l, 'latitude') . ',' . ezbizi_row($l, 'longitude')
        : ezbizi_location_address($l);

    return $to === '' ? '' : 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($to);
}

/** A keyless map of the location, as [ezbizi_google_map] draws one. */
function ezbizi_location_map($l, $height) {
    $at = ezbizi_row($l, 'latitude') !== '' && ezbizi_row($l, 'longitude') !== ''
        ? ezbizi_row($l, 'latitude') . ',' . ezbizi_row($l, 'longitude')
        : ezbizi_location_address($l);

    if ($at === '') {
        return '';
    }

    return '<iframe src="' . esc_url('https://maps.google.com/maps?q=' . rawurlencode($at) . '&z=15&output=embed') . '"'
        . ' width="100%" height="' . (int) $height . '" style="border:0;display:block;border-radius:6px"'
        . ' loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="' . esc_attr(ezbizi_row($l, 'name')) . '"></iframe>';
}

/** The details every location card shares. */
function ezbizi_location_lines($l, $address) {
    $out = '';
    $phone = ezbizi_row($l, 'phone');

    if ($address && ezbizi_location_address($l) !== '') {
        $out .= '<p class="ezbizi-loc-address">' . esc_html(ezbizi_location_address($l)) . '</p>';
    }

    if ($phone !== '') {
        $out .= '<p class="ezbizi-loc-phone"><a href="' . esc_url('tel:' . ezbizi_dial($phone)) . '">' . esc_html($phone) . '</a></p>';
    }

    return $out;
}

// ── Team ────────────────────────────────────────────────────────────

function ezbizi_team_shortcode($atts) {
    $members = ezbizi_list('team');

    if ($members === array()) {
        return '';
    }

    $p = ezbizi_preset('team', $atts);
    $columns = max(1, min(6, (int) (isset($p['columns']) ? $p['columns'] : 3)));
    $round = isset($p['photo']) && $p['photo'] === 'circle';
    $bio = ! empty($p['bio']);

    // Grouped under their headings, in the order the list first names them.
    $groups = array();

    foreach ($members as $m) {
        if (ezbizi_row($m, 'name') !== '') {
            $groups[ezbizi_row($m, 'category')][] = $m;
        }
    }

    $id = ezbizi_widget_id('ezbizi-team');
    $s = '#' . $id;
    $out = '<style>'
        . $s . ' .ezbizi-team-grid{display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:24px;margin:0 0 32px}'
        . '@media (max-width:767px){' . $s . ' .ezbizi-team-grid{grid-template-columns:repeat(' . min(2, $columns) . ',minmax(0,1fr))}}'
        . $s . ' .ezbizi-team-member{text-align:center}'
        . $s . ' .ezbizi-team-member img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;margin:0 0 10px;border-radius:' . ($round ? '50%' : '6px') . '}'
        . $s . ' .ezbizi-team-member h4{margin:0 0 4px}'
        . $s . ' .ezbizi-team-role{margin:0 0 8px;opacity:.85}'
        . $s . ' .ezbizi-team-heading{text-align:center}'
        . '</style>';

    $out .= '<div class="ezbizi-team" id="' . esc_attr($id) . '">';

    foreach ($groups as $heading => $people) {
        if ($heading !== '' && count($groups) > 1) {
            $out .= '<h3 class="ezbizi-team-heading">' . esc_html($heading) . '</h3>';
        }

        $out .= '<div class="ezbizi-team-grid">';

        foreach ($people as $m) {
            $out .= '<div class="ezbizi-team-member">'
                . (ezbizi_row($m, 'photo_url') !== '' ? '<img src="' . esc_url(ezbizi_row($m, 'photo_url')) . '" alt="' . esc_attr(ezbizi_row($m, 'name')) . '" loading="lazy" />' : '')
                . '<h4>' . esc_html(ezbizi_row($m, 'name')) . '</h4>'
                . (ezbizi_row($m, 'role') !== '' ? '<p class="ezbizi-team-role">' . esc_html(ezbizi_row($m, 'role')) . '</p>' : '')
                . ($bio && ezbizi_row($m, 'bio') !== '' ? '<p class="ezbizi-team-bio">' . nl2br(esc_html(ezbizi_row($m, 'bio'))) . '</p>' : '')
                . '</div>';
        }

        $out .= '</div>';
    }

    return $out . '</div>';
}

// ── Branches ────────────────────────────────────────────────────────

function ezbizi_branches_shortcode($atts) {
    $branches = ezbizi_list('site');

    if ($branches === array()) {
        return '';
    }

    $p = ezbizi_preset('branches', $atts);
    $columns = max(1, min(4, (int) (isset($p['columns']) ? $p['columns'] : 3)));
    $palette = ezbizi_widget_palette();
    $accent = esc_attr(ezbizi_widget_text($palette, 'accent', '#0b5cad'));
    $on_accent = esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff'));

    $id = ezbizi_widget_id('ezbizi-branches');
    $s = '#' . $id;
    $out = '<style>'
        . $s . '{display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:24px}'
        . '@media (max-width:767px){' . $s . '{grid-template-columns:1fr}}'
        . $s . ' .ezbizi-branch{text-align:center}'
        . $s . ' .ezbizi-branch h3{margin:0 0 6px}'
        . $s . ' .ezbizi-branch p{margin:0 0 8px}'
        . $s . ' .ezbizi-branch a{color:inherit}'
        . $s . ' .ezbizi-branch .ezbizi-loc-directions{display:inline-block;margin-top:10px;padding:10px 18px;border-radius:6px;background:' . $accent . ';color:' . $on_accent . ';text-decoration:none;font-weight:700}'
        . '</style>';

    $out .= '<div class="ezbizi-branches" id="' . esc_attr($id) . '">';

    foreach ($branches as $l) {
        if (ezbizi_row($l, 'name') === '') {
            continue;
        }

        $directions = ezbizi_location_directions($l);
        $out .= '<div class="ezbizi-branch"><h3>' . esc_html(ezbizi_row($l, 'name')) . '</h3>'
            . ezbizi_location_lines($l, ! empty($p['address']))
            . (! empty($p['map']) ? ezbizi_location_map($l, 220) : '')
            . (! empty($p['directions']) && $directions !== ''
                ? '<a class="ezbizi-loc-directions" href="' . esc_url($directions) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Get Directions', 'ezbizi') . '</a>'
                : '')
            . '</div>';
    }

    return $out . '</div>';
}

// ── Store locator ───────────────────────────────────────────────────

function ezbizi_store_locator_shortcode($atts) {
    $stores = ezbizi_list('company');

    if ($stores === array()) {
        return '';
    }

    $p = ezbizi_preset('store_locator', $atts);
    $palette = ezbizi_widget_palette();
    $accent = esc_attr(ezbizi_widget_text($palette, 'accent', '#0b5cad'));

    $states = array_values(array_unique(array_filter(array_map(function ($l) {
        return strtoupper(ezbizi_row($l, 'state'));
    }, $stores))));
    sort($states);

    $id = ezbizi_widget_id('ezbizi-stores');
    $s = '#' . $id;
    $out = '<style>'
        . $s . ' .ezbizi-stores-bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}'
        . $s . ' .ezbizi-stores-bar input,' . $s . ' .ezbizi-stores-bar select{flex:1 1 200px;padding:10px;border:1px solid #ccc;border-radius:6px;font:inherit;background:#fff;color:#1b1b1b}'
        . $s . ' .ezbizi-stores-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px}'
        . $s . ' .ezbizi-store{border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.12);padding:16px}'
        . $s . ' .ezbizi-store img{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:6px;display:block;margin:0 0 10px}'
        . $s . ' .ezbizi-store h3{margin:0 0 6px}'
        . $s . ' .ezbizi-store p{margin:0 0 6px}'
        . $s . ' .ezbizi-store a{color:' . $accent . '}'
        . $s . ' .ezbizi-store-links a{margin-right:12px;font-weight:700;text-transform:uppercase;font-size:.85em}'
        . $s . ' .ezbizi-store iframe{margin-top:10px}'
        . '</style>';

    $out .= '<div class="ezbizi-stores" id="' . esc_attr($id) . '">';

    if (! empty($p['search'])) {
        $out .= '<div class="ezbizi-stores-bar"><input type="search" placeholder="' . esc_attr__('Search by town, state or ZIP', 'ezbizi') . '" aria-label="' . esc_attr__('Search locations', 'ezbizi') . '" />';

        if (count($states) > 1) {
            $out .= '<select aria-label="' . esc_attr__('State', 'ezbizi') . '"><option value="">' . esc_html__('All states', 'ezbizi') . '</option>';

            foreach ($states as $state) {
                $out .= '<option value="' . esc_attr($state) . '">' . esc_html($state) . '</option>';
            }

            $out .= '</select>';
        }

        $out .= '</div>';
    }

    $out .= '<div class="ezbizi-stores-list">';

    foreach ($stores as $l) {
        if (ezbizi_row($l, 'name') === '') {
            continue;
        }

        $haystack = strtolower(ezbizi_row($l, 'name') . ' ' . ezbizi_location_address($l));
        $email = ezbizi_row($l, 'email');
        $site = ezbizi_row($l, 'website_url');
        $directions = ezbizi_location_directions($l);

        $out .= '<div class="ezbizi-store" data-find="' . esc_attr($haystack) . '" data-state="' . esc_attr(strtoupper(ezbizi_row($l, 'state'))) . '">'
            . (! empty($p['images']) && ezbizi_row($l, 'image_url') !== '' ? '<img src="' . esc_url(ezbizi_row($l, 'image_url')) . '" alt="" loading="lazy" />' : '')
            . '<h3>' . esc_html(ezbizi_row($l, 'name')) . '</h3>'
            . ezbizi_location_lines($l, true)
            . ($email !== '' ? '<p><a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a></p>' : '')
            . '<p class="ezbizi-store-links">'
            . ($site !== '' ? '<a href="' . esc_url($site) . '">' . esc_html__('Website', 'ezbizi') . '</a>' : '')
            . ($directions !== '' ? '<a href="' . esc_url($directions) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Directions', 'ezbizi') . '</a>' : '')
            . '</p>'
            . (! empty($p['maps']) ? ezbizi_location_map($l, 180) : '')
            . '</div>';
    }

    $out .= '</div><p class="ezbizi-stores-none" hidden>' . esc_html__('No locations match that search.', 'ezbizi') . '</p></div>';

    if (! empty($p['search'])) {
        $out .= "<script>(function(){
var w=document.getElementById(" . wp_json_encode($id) . ");if(!w)return;
var q=w.querySelector('input'),st=w.querySelector('select'),none=w.querySelector('.ezbizi-stores-none');
function run(){var t=(q.value||'').toLowerCase().trim(),s=st?st.value:'',n=0;
  Array.prototype.forEach.call(w.querySelectorAll('.ezbizi-store'),function(c){
    var ok=(!t||c.getAttribute('data-find').indexOf(t)>-1)&&(!s||c.getAttribute('data-state')===s);c.hidden=!ok;if(ok)n++;});
  none.hidden=n>0;}
q.addEventListener('input',run);if(st)st.addEventListener('change',run);
})();</script>";
    }

    return $out;
}

add_action('init', function () {
    add_shortcode('ezbizi_team', 'ezbizi_team_shortcode');
    add_shortcode('ezbizi_branches', 'ezbizi_branches_shortcode');
    add_shortcode('ezbizi_store_locator', 'ezbizi_store_locator_shortcode');
});
