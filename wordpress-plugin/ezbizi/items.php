<?php
/**
 * [ezbizi_services], [ezbizi_reviews], [ezbizi_before_after] and [ezbizi_faq].
 *
 * Jose, 2026-09-25: "yes all of it" — A1 Tools' four widgets no page used yet,
 * built all the same. Each draws a list the company keeps in EzBizi (the
 * payload's `items`), and reviews fall back to the Google reviews EzBizi syncs
 * (`reviews`). Nothing to draw, nothing drawn, like every other widget here.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** One kind of the company's items, filtered to a category when asked. */
function ezbizi_items($kind, $category = '') {
    $payload = ezbizi_payload();
    $items = isset($payload['items'][$kind]) && is_array($payload['items'][$kind]) ? $payload['items'][$kind] : array();
    $category = strtolower(trim((string) $category));

    if ($category === '') {
        return $items;
    }

    return array_values(array_filter($items, function ($i) use ($category) {
        return strtolower(ezbizi_row($i, 'category')) === $category;
    }));
}

/** An inline SVG for a service icon: a small fixed set, no icon font. */
function ezbizi_service_icon($name) {
    $paths = array(
        'chimney' => 'M6 21V10l3-2V3h3v3l6-4v19zM9 21v-5h4v5',
        'fire' => 'M12 2s5 4 5 9a5 5 0 01-10 0c0-2 1-3 1-3s1 2 2 2c0-3 2-8 2-8z',
        'door' => 'M6 2h12v20H6zM15 12h1',
        'roof' => 'M2 12L12 3l10 9M5 10v11h14V10',
        'tools' => 'M14 6l4 4-8 8H6v-4zM16 4l4 4',
        'shield' => 'M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z',
        'star' => 'M12 2l3 6.9 7.5.7-5.7 5 1.7 7.4L12 18l-6.5 4 1.7-7.4-5.7-5 7.5-.7z',
        'check' => 'M4 12l5 5L20 6',
        'home' => 'M3 11l9-8 9 8v10H3z',
    );
    $d = isset($paths[$name]) ? $paths[$name] : $paths['check'];

    return '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr($d) . '"/></svg>';
}

// ── Services ────────────────────────────────────────────────────────

function ezbizi_services_shortcode($atts) {
    $p = ezbizi_preset('services', $atts);
    $items = ezbizi_items('service', isset($p['category']) ? $p['category'] : '');

    if ($items === array()) {
        return '';
    }

    $columns = max(1, min(4, (int) (isset($p['columns']) ? $p['columns'] : 3)));
    $list = isset($p['layout']) && $p['layout'] === 'list';
    $id = ezbizi_widget_id('ezbizi-services');
    $s = '#' . $id;
    $palette = ezbizi_widget_palette();
    $accent = esc_attr(ezbizi_widget_text($palette, 'accent', '#0b5cad'));
    $on_accent = esc_attr(ezbizi_widget_text($palette, 'on_accent', '#ffffff'));

    $out = '<style>' . $s . '{display:grid;gap:24px;grid-template-columns:' . ($list ? '1fr' : 'repeat(' . $columns . ',minmax(0,1fr))') . '}'
        . '@media (max-width:767px){' . $s . '{grid-template-columns:1fr}}'
        . $s . ' .ezbizi-service{display:flex;flex-direction:' . ($list ? 'row' : 'column') . ';gap:12px}'
        . $s . ' .ezbizi-service img{width:' . ($list ? '180px' : '100%') . ';aspect-ratio:4/3;object-fit:cover;border-radius:6px;display:block}'
        . $s . ' .ezbizi-service h3{margin:0}'
        . $s . ' .ezbizi-service p{margin:0}'
        . $s . ' .ezbizi-service-icon{color:' . $accent . '}'
        . $s . ' .ezbizi-service-link{align-self:flex-start;padding:8px 16px;border-radius:6px;background:' . $accent . ';color:' . $on_accent . ';text-decoration:none;font-weight:700}'
        . '</style><div class="ezbizi-services" id="' . esc_attr($id) . '">';

    foreach ($items as $i) {
        $link = ezbizi_row($i, 'link_url');
        $phone = ezbizi_row($i, 'phone');
        $out .= '<div class="ezbizi-service">'
            . (! empty($p['image']) && ezbizi_row($i, 'image_url') !== '' ? '<img src="' . esc_url(ezbizi_row($i, 'image_url')) . '" alt="" loading="lazy" />' : '')
            . '<div class="ezbizi-service-body">'
            . (! empty($p['icon']) && ezbizi_row($i, 'icon') !== '' ? '<span class="ezbizi-service-icon">' . ezbizi_service_icon(ezbizi_row($i, 'icon')) . '</span>' : '')
            . '<h3>' . esc_html(ezbizi_row($i, 'title')) . '</h3>'
            . (! empty($p['description']) && ezbizi_row($i, 'body') !== '' ? '<p>' . nl2br(esc_html(ezbizi_row($i, 'body'))) . '</p>' : '')
            . (! empty($p['phone']) && $phone !== '' ? '<p><a href="' . esc_url('tel:' . ezbizi_dial($phone)) . '">' . esc_html($phone) . '</a></p>' : '')
            . '</div>'
            . ($link !== '' && trim((string) (isset($p['button_text']) ? $p['button_text'] : '')) !== ''
                ? '<a class="ezbizi-service-link" href="' . esc_url($link) . '"' . (! empty($p['new_tab']) ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($p['button_text']) . '</a>'
                : '')
            . '</div>';
    }

    return $out . '</div>' . ezbizi_style_css('services', $id, $p, array(
        'box' => '', 'card' => ' .ezbizi-service', 'image' => ' .ezbizi-service img', 'icon' => ' .ezbizi-service-icon',
        'title' => ' .ezbizi-service h3', 'text' => ' .ezbizi-service p', 'button' => ' .ezbizi-service-link',
    ));
}

// ── Reviews ─────────────────────────────────────────────────────────

function ezbizi_stars($rating, $color) {
    $rating = max(0, min(5, (int) $rating));

    return '<span class="ezbizi-stars" role="img" aria-label="' . esc_attr($rating . ' out of 5') . '" style="color:' . $color . '">'
        . str_repeat('&#9733;', $rating) . '<span style="opacity:.3">' . str_repeat('&#9733;', 5 - $rating) . '</span></span>';
}

function ezbizi_reviews_shortcode($atts) {
    $p = ezbizi_preset('reviews', $atts);
    $payload = ezbizi_payload();
    $featured = ezbizi_items('review');
    $google = isset($payload['reviews']) && is_array($payload['reviews']) ? $payload['reviews'] : array();
    $source = isset($p['source']) ? (string) $p['source'] : 'auto';
    $reviews = $source === 'featured' ? $featured : ($source === 'google' ? $google : ($featured !== array() ? $featured : $google));
    $min = max(1, min(5, (int) (isset($p['min_rating']) ? $p['min_rating'] : 4)));

    $reviews = array_values(array_filter($reviews, function ($r) use ($min) {
        return ezbizi_row($r, 'body') !== '' && ((int) ezbizi_row($r, 'rating') === 0 || (int) ezbizi_row($r, 'rating') >= $min);
    }));

    if ($reviews === array()) {
        return '';
    }

    $all = $reviews;
    $reviews = array_slice($reviews, 0, max(1, min(50, (int) (isset($p['limit']) ? $p['limit'] : 6))));
    $layout = isset($p['layout']) ? (string) $p['layout'] : 'grid';
    $columns = max(1, min(4, (int) (isset($p['columns']) ? $p['columns'] : 3)));
    $star = isset($p['star_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $p['star_color']) ? (string) $p['star_color'] : '#f5b301';
    $excerpt = max(0, (int) (isset($p['excerpt']) ? $p['excerpt'] : 0));
    $id = ezbizi_widget_id('ezbizi-reviews');
    $s = '#' . $id;

    $out = '<style>'
        . $s . ' .ezbizi-reviews-list{display:grid;gap:20px;grid-template-columns:' . ($layout === 'list' ? '1fr' : 'repeat(' . $columns . ',minmax(0,1fr))') . '}'
        . ($layout === 'slider' ? $s . ' .ezbizi-reviews-list{display:flex;overflow-x:auto;scroll-snap-type:x mandatory}' . $s . ' .ezbizi-review{flex:0 0 calc((100% - ' . (($columns - 1) * 20) . 'px) / ' . $columns . ');scroll-snap-align:start}' : '')
        . '@media (max-width:767px){' . $s . ' .ezbizi-reviews-list{grid-template-columns:1fr}' . $s . ' .ezbizi-review{flex-basis:85%}}'
        . $s . ' .ezbizi-review{border-radius:8px;padding:16px;box-shadow:0 1px 6px rgba(0,0,0,.12)}'
        . $s . ' .ezbizi-review-head{display:flex;align-items:center;gap:10px;margin:0 0 8px}'
        . $s . ' .ezbizi-review-head img{width:40px;height:40px;border-radius:50%;object-fit:cover}'
        . $s . ' .ezbizi-review-name{font-weight:700;display:block}'
        . $s . ' .ezbizi-review-date{font-size:.85em;opacity:.75}'
        . $s . ' .ezbizi-stars{letter-spacing:2px}'
        . $s . ' .ezbizi-reviews-summary{margin:0 0 16px;font-weight:700}'
        . '</style><div class="ezbizi-reviews" id="' . esc_attr($id) . '">';

    if (! empty($p['summary'])) {
        $rated = array_filter(array_map(function ($r) {
            return (int) ezbizi_row($r, 'rating');
        }, $all));
        $average = $rated === array() ? 0 : array_sum($rated) / count($rated);
        $out .= '<p class="ezbizi-reviews-summary">' . ezbizi_stars((int) round($average), $star) . ' '
            . esc_html(number_format($average, 1) . ' · ' . count($all) . ' ' . _n('review', 'reviews', count($all), 'ezbizi')) . '</p>';
    }

    $out .= '<div class="ezbizi-reviews-list">';

    foreach ($reviews as $r) {
        $text = ezbizi_row($r, 'body');

        if ($excerpt > 0 && mb_strlen($text) > $excerpt) {
            $text = rtrim(mb_substr($text, 0, $excerpt)) . '…';
        }

        $date = ezbizi_row($r, 'dated');
        $out .= '<div class="ezbizi-review"><div class="ezbizi-review-head">'
            . (! empty($p['photo']) && ezbizi_row($r, 'image_url') !== '' ? '<img src="' . esc_url(ezbizi_row($r, 'image_url')) . '" alt="" loading="lazy" />' : '')
            . '<span><span class="ezbizi-review-name">' . esc_html(ezbizi_row($r, 'title')) . '</span>'
            . (! empty($p['date']) && $date !== '' ? '<span class="ezbizi-review-date">' . esc_html(date_i18n(get_option('date_format', 'F j, Y'), strtotime($date))) . '</span>' : '')
            . (! empty($p['show_source']) && ezbizi_row($r, 'subtitle') !== '' ? '<span class="ezbizi-review-date"> · ' . esc_html(ezbizi_row($r, 'subtitle')) . '</span>' : '')
            . '</span></div>'
            . (! empty($p['stars']) && (int) ezbizi_row($r, 'rating') > 0 ? ezbizi_stars(ezbizi_row($r, 'rating'), $star) : '')
            . '<p class="ezbizi-review-text">' . nl2br(esc_html($text)) . '</p></div>';
    }

    return $out . '</div></div>' . ezbizi_style_css('reviews', $id, $p, array(
        'box' => '', 'card' => ' .ezbizi-review', 'name' => ' .ezbizi-review-name', 'text' => ' .ezbizi-review-text',
        'date' => ' .ezbizi-review-date', 'summary' => ' .ezbizi-reviews-summary',
    ));
}

// ── Before & after ──────────────────────────────────────────────────

function ezbizi_before_after_shortcode($atts) {
    $p = ezbizi_preset('before_after', $atts);
    $pairs = array_values(array_filter(ezbizi_items('before_after', isset($p['category']) ? $p['category'] : ''), function ($i) {
        return ezbizi_row($i, 'image_url') !== '' && ezbizi_row($i, 'image2_url') !== '';
    }));
    // Inspection pairs approved in EzBizi come first (newest approval first), mapped to the item shape.
    foreach (array_reverse(ezbizi_before_after_feed(12)) as $f) {
        if (! empty($f['before']['url']) && ! empty($f['after']['url'])) {
            $where = implode(', ', array_filter(array(isset($f['city']) ? $f['city'] : '', isset($f['state']) ? $f['state'] : '')));
            array_unshift($pairs, array('image_url' => $f['before']['url'], 'image2_url' => $f['after']['url'],
                'title' => ! empty($f['label']) ? $f['label'] : (isset($f['service']) ? $f['service'] : ''), 'body' => $where));
        }
    }

    if ($pairs === array()) {
        return '';
    }

    $columns = max(1, min(4, (int) (isset($p['columns']) ? $p['columns'] : 2)));
    $slider = ! isset($p['layout']) || $p['layout'] === 'slider';
    $handle = isset($p['handle_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $p['handle_color']) ? (string) $p['handle_color'] : '#ffffff';
    $before = isset($p['before_label']) ? (string) $p['before_label'] : 'Before';
    $after = isset($p['after_label']) ? (string) $p['after_label'] : 'After';
    $id = ezbizi_widget_id('ezbizi-ba');
    $s = '#' . $id;

    $out = '<style>' . $s . '{display:grid;gap:24px;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr))}'
        . '@media (max-width:767px){' . $s . '{grid-template-columns:1fr}}'
        . $s . ' .ezbizi-ba-frame{position:relative;overflow:hidden;border-radius:8px;aspect-ratio:4/3;--at:50%}'
        . $s . ' .ezbizi-ba-frame img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
        . $s . ' .ezbizi-ba-after{clip-path:inset(0 0 0 var(--at))}'
        . $s . ' .ezbizi-ba-line{position:absolute;top:0;bottom:0;left:var(--at);width:3px;margin-left:-1px;background:' . $handle . ';pointer-events:none}'
        . $s . ' .ezbizi-ba-range{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:ew-resize;margin:0}'
        . $s . ' .ezbizi-ba-tag{position:absolute;top:8px;padding:2px 8px;border-radius:10px;background:rgba(0,0,0,.6);color:#fff;font-size:12px}'
        . $s . ' .ezbizi-ba-pair{display:grid;grid-template-columns:1fr 1fr;gap:4px}'
        . $s . ' .ezbizi-ba-pair div{position:relative}'
        . $s . ' .ezbizi-ba-pair img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;border-radius:6px}'
        . $s . ' h3{margin:8px 0 4px}' . $s . ' p{margin:0}'
        . '</style><div class="ezbizi-before-after" id="' . esc_attr($id) . '">';

    foreach ($pairs as $i) {
        $b = esc_url(ezbizi_row($i, 'image_url'));
        $a = esc_url(ezbizi_row($i, 'image2_url'));
        $title = ezbizi_row($i, 'title');
        $out .= '<figure class="ezbizi-ba" style="margin:0">';

        if ($slider) {
            // A range input drives the split: keyboard and touch work with no
            // script beyond one line that copies its value to the frame.
            $out .= '<div class="ezbizi-ba-frame">'
                . '<img src="' . $b . '" alt="' . esc_attr($before . ': ' . $title) . '" loading="lazy" />'
                . '<img class="ezbizi-ba-after" src="' . $a . '" alt="' . esc_attr($after . ': ' . $title) . '" loading="lazy" />'
                . '<span class="ezbizi-ba-tag" style="left:8px">' . esc_html($before) . '</span>'
                . '<span class="ezbizi-ba-tag" style="right:8px">' . esc_html($after) . '</span>'
                . '<span class="ezbizi-ba-line"></span>'
                . '<input class="ezbizi-ba-range" type="range" min="0" max="100" value="50" aria-label="' . esc_attr__('Compare before and after', 'ezbizi') . '" oninput="this.parentNode.style.setProperty(\'--at\',this.value+\'%\')" />'
                . '</div>';
        } else {
            $out .= '<div class="ezbizi-ba-pair">'
                . '<div><img src="' . $b . '" alt="' . esc_attr($before) . '" loading="lazy" /><span class="ezbizi-ba-tag" style="left:8px">' . esc_html($before) . '</span></div>'
                . '<div><img src="' . $a . '" alt="' . esc_attr($after) . '" loading="lazy" /><span class="ezbizi-ba-tag" style="left:8px">' . esc_html($after) . '</span></div>'
                . '</div>';
        }

        $out .= (! empty($p['title']) && $title !== '' ? '<h3>' . esc_html($title) . '</h3>' : '')
            . (! empty($p['description']) && ezbizi_row($i, 'body') !== '' ? '<p>' . esc_html(ezbizi_row($i, 'body')) . '</p>' : '')
            . '</figure>';
    }

    return $out . '</div>' . ezbizi_style_css('before_after', $id, $p, array(
        'box' => '', 'card' => ' .ezbizi-ba', 'title' => ' h3', 'text' => ' p', 'label' => ' .ezbizi-ba-tag',
    ));
}

// ── FAQ ─────────────────────────────────────────────────────────────

function ezbizi_faq_shortcode($atts) {
    $p = ezbizi_preset('faq', $atts);
    $faqs = array_values(array_filter(ezbizi_items('faq', isset($p['category']) ? $p['category'] : ''), function ($i) {
        return ezbizi_row($i, 'title') !== '' && ezbizi_row($i, 'body') !== '';
    }));

    if ($faqs === array()) {
        return '';
    }

    $id = ezbizi_widget_id('ezbizi-faq');
    $s = '#' . $id;
    $chevron = isset($p['icon']) && $p['icon'] === 'chevron';

    $out = '<style>'
        . $s . ' details{border-bottom:1px solid rgba(127,127,127,.35);padding:12px 0}'
        . $s . ' summary{cursor:pointer;font-weight:700;list-style:none;display:flex;justify-content:space-between;gap:12px}'
        . $s . ' summary::-webkit-details-marker{display:none}'
        . $s . ' summary::after{content:"' . ($chevron ? '\\203A' : '+') . '";transition:transform .2s;' . ($chevron ? 'transform:rotate(90deg)' : '') . '}'
        . $s . ' details[open] summary::after{transform:rotate(' . ($chevron ? '270deg' : '45deg') . ')}'
        . $s . ' .ezbizi-faq-answer{margin:8px 0 0}'
        . $s . ' .ezbizi-faq-category{margin:16px 0 4px}'
        . '</style><div class="ezbizi-faq" id="' . esc_attr($id) . '"' . (! empty($p['one_open']) ? ' data-one-open="1"' : '') . '>';

    $current = null;

    foreach ($faqs as $n => $f) {
        $category = ezbizi_row($f, 'category');

        if (! empty($p['show_category']) && $category !== '' && $category !== $current) {
            $out .= '<h3 class="ezbizi-faq-category">' . esc_html($category) . '</h3>';
            $current = $category;
        }

        $out .= '<details class="ezbizi-faq-item"' . ($n === 0 && ! empty($p['open_first']) ? ' open' : '') . '>'
            . '<summary class="ezbizi-faq-question">' . esc_html(ezbizi_row($f, 'title')) . '</summary>'
            . '<div class="ezbizi-faq-answer">' . wpautop(esc_html(ezbizi_row($f, 'body'))) . '</div></details>';
    }

    $out .= '</div>';

    if (! empty($p['one_open'])) {
        $out .= "<script>(function(){var w=document.getElementById(" . wp_json_encode($id) . ");if(!w)return;"
            . "Array.prototype.forEach.call(w.querySelectorAll('details'),function(d){d.addEventListener('toggle',function(){"
            . "if(d.open)Array.prototype.forEach.call(w.querySelectorAll('details'),function(o){if(o!==d)o.open=false;});});});})();</script>";
    }

    // The FAQPage markup search engines read, from the same words.
    if (! isset($p['schema']) || ! empty($p['schema'])) {
        $out .= '<script type="application/ld+json">' . wp_json_encode(array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(function ($f) {
                return array(
                    '@type' => 'Question',
                    'name' => ezbizi_row($f, 'title'),
                    'acceptedAnswer' => array('@type' => 'Answer', 'text' => ezbizi_row($f, 'body')),
                );
            }, $faqs),
        ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }

    return $out . ezbizi_style_css('faq', $id, $p, array(
        'box' => '', 'item' => ' .ezbizi-faq-item', 'question' => ' .ezbizi-faq-question',
        'answer' => ' .ezbizi-faq-answer', 'icon' => ' summary::after', 'category' => ' .ezbizi-faq-category',
    ));
}

add_action('init', function () {
    add_shortcode('ezbizi_services', 'ezbizi_services_shortcode');
    add_shortcode('ezbizi_reviews', 'ezbizi_reviews_shortcode');
    add_shortcode('ezbizi_before_after', 'ezbizi_before_after_shortcode');
    add_shortcode('ezbizi_faq', 'ezbizi_faq_shortcode');
});
