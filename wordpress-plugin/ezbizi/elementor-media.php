<?php
/**
 * Two Elementor widgets whose CONTENT is the page's own: a gallery and a
 * video carousel.
 *
 * ── Why these break the one-control rule the others keep ──
 *
 * Every other EzBizi widget draws a business fact EzBizi holds — a phone, the
 * hours, the towns — so its only control is a preset name. A gallery's
 * pictures and a carousel's videos are not facts about the business; they are
 * what somebody chose for THIS page, from this site's media library. Holding
 * them in EzBizi would mean uploading every site's photos twice. So the
 * content lives in the widget, like Elementor's own gallery, and only the
 * look comes from EzBizi: the accent is the site's, the text colour is the
 * page's.
 *
 * ── Why the setting names match A1 Tools' ──
 *
 * Jose, 2026-09-25: "whatever is missing that is being used add it to
 * ezbizi". 44 A1-Group sites carry an A1 gallery and 324 carousels hold 7
 * videos each. Naming the content settings the same (`images`, `bulk_groups`,
 * `work_gallery`, `videos` …) means moving a page across is renaming its
 * widget type, not re-entering 2,000 videos and pictures. The names are the
 * only thing shared; the code is this file's own.
 *
 * Loaded from `ezbizi_register_elementor_widgets()`, so only when Elementor
 * is. The render functions are plain functions so plugin-check can call them
 * without Elementor.
 */

if (! defined('ABSPATH')) {
    exit;
}

// ── Gallery ─────────────────────────────────────────────────────────

/**
 * The gallery's markup from its settings, as Elementor stores them.
 *
 * Two views when both are filled: "our work" (a plain paginated grid) first,
 * and a button to the projects (searchable, filterable by category). Either
 * one alone is shown alone.
 *
 * @param array $s Elementor settings.
 */
function ezbizi_gallery_render($s) {
    $text = function ($k, $d) use ($s) {
        // Elementor stores a number as an int or a string depending on the
        // control that wrote it; both are the setting.
        return isset($s[$k]) && is_scalar($s[$k]) && trim((string) $s[$k]) !== '' ? (string) $s[$k] : $d;
    };
    $url = function ($media) {
        return is_array($media) && ! empty($media['url']) ? (string) $media['url'] : '';
    };

    // Projects: one item per repeater row, then every picture of every bulk group.
    $items = array();

    foreach ((isset($s['images']) && is_array($s['images']) ? $s['images'] : array()) as $row) {
        $src = $url(isset($row['image']) ? $row['image'] : null);

        if ($src === '') {
            continue;
        }

        $items[] = array(
            'src' => $src,
            'category' => isset($row['category']) ? trim((string) $row['category']) : '',
            'before' => $url(isset($row['before_image']) ? $row['before_image'] : null),
            'before_label' => isset($row['before_label']) && $row['before_label'] !== '' ? (string) $row['before_label'] : 'Before',
            'after_label' => isset($row['after_label']) && $row['after_label'] !== '' ? (string) $row['after_label'] : 'After',
        );
    }

    foreach ((isset($s['bulk_groups']) && is_array($s['bulk_groups']) ? $s['bulk_groups'] : array()) as $group) {
        $category = isset($group['category']) ? trim((string) $group['category']) : '';

        foreach ((isset($group['gallery']) && is_array($group['gallery']) ? $group['gallery'] : array()) as $media) {
            if ($url($media) !== '') {
                $items[] = array('src' => $url($media), 'category' => $category, 'before' => '');
            }
        }
    }

    $work = array();

    foreach ((isset($s['work_gallery']) && is_array($s['work_gallery']) ? $s['work_gallery'] : array()) as $media) {
        if ($url($media) !== '') {
            $work[] = $url($media);
        }
    }

    if ($items === array() && $work === array()) {
        return '';
    }

    $columns = max(1, min(6, (int) $text('columns', '3')));
    $per_page = max(1, (int) $text('per_page', '12'));
    $work_per_page = max(1, (int) $text('work_per_page', '9'));
    $categories = array_values(array_unique(array_filter(array_column($items, 'category'))));
    sort($categories);

    $palette = function_exists('ezbizi_widget_palette') ? ezbizi_widget_palette() : array();
    $accent = isset($palette['accent']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $palette['accent']) ? $palette['accent'] : '#0b5cad';
    $on_accent = isset($palette['on_accent']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $palette['on_accent']) ? $palette['on_accent'] : '#ffffff';

    $id = function_exists('ezbizi_widget_id') ? ezbizi_widget_id('ezbizi-gallery') : 'ezbizi-gallery-' . wp_rand();
    $g = '#' . $id;

    $out = '<style>'
        . $g . '{--ezg-accent:' . $accent . ';--ezg-on:' . $on_accent . '}'
        . $g . ' .ezg-grid{display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:12px}'
        . '@media (max-width:767px){' . $g . ' .ezg-grid{grid-template-columns:repeat(' . min(2, $columns) . ',minmax(0,1fr))}}'
        . $g . ' .ezg-item{position:relative;margin:0;cursor:zoom-in;overflow:hidden;border-radius:6px}'
        . $g . ' .ezg-item img{display:block;width:100%;height:auto;transition:transform .2s}'
        . $g . ' .ezg-item:hover img{transform:scale(1.04)}'
        . $g . ' .ezg-pair{display:grid;grid-template-columns:1fr 1fr;gap:4px}'
        . $g . ' .ezg-tag{position:absolute;left:6px;top:6px;font-size:12px;padding:2px 8px;border-radius:10px;background:rgba(0,0,0,.6);color:#fff}'
        . $g . ' .ezg-bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}'
        . $g . ' .ezg-bar input,' . $g . ' .ezg-bar select{flex:1 1 200px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font:inherit;background:#fff;color:#1b1b1b}'
        . $g . ' .ezg-pages{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin:12px 0}'
        . $g . ' .ezg-pages button,' . $g . ' .ezg-switch{border:1px solid var(--ezg-accent);background:transparent;color:inherit;border-radius:6px;padding:6px 12px;cursor:pointer;font:inherit}'
        . $g . ' .ezg-pages button[aria-current=true],' . $g . ' .ezg-switch{background:var(--ezg-accent);color:var(--ezg-on)}'
        . $g . ' .ezg-actions{text-align:center;margin:12px 0}'
        . $g . ' .ezg-empty{text-align:center;opacity:.8}'
        . '.ezg-box{position:fixed;inset:0;background:rgba(0,0,0,.88);display:flex;align-items:center;justify-content:center;z-index:99999;cursor:zoom-out}'
        . '.ezg-box img{max-width:92vw;max-height:92vh}'
        . '</style>';

    $figure = function ($item) {
        $inner = $item['before'] !== ''
            ? '<div class="ezg-pair"><div><img src="' . esc_url($item['before']) . '" alt="' . esc_attr($item['before_label']) . '" loading="lazy" /></div>'
                . '<div><img src="' . esc_url($item['src']) . '" alt="' . esc_attr($item['after_label']) . '" loading="lazy" /></div></div>'
            : '<img src="' . esc_url($item['src']) . '" alt="' . esc_attr($item['category']) . '" loading="lazy" />';

        return '<figure class="ezg-item" tabindex="0" data-cat="' . esc_attr(strtolower($item['category'])) . '">'
            . $inner
            . ($item['category'] !== '' ? '<span class="ezg-tag">' . esc_html($item['category']) . '</span>' : '')
            . '</figure>';
    };

    $out .= '<div class="ezbizi-gallery" id="' . esc_attr($id) . '">';

    if ($work !== array()) {
        $out .= '<section data-view="work"><div class="ezg-grid" data-per="' . $work_per_page . '">';

        foreach ($work as $src) {
            $out .= '<figure class="ezg-item" tabindex="0" data-cat=""><img src="' . esc_url($src) . '" alt="" loading="lazy" /></figure>';
        }

        $out .= '</div><nav class="ezg-pages"></nav>';

        if ($items !== array()) {
            $out .= '<p class="ezg-actions"><button type="button" class="ezg-switch" data-show="projects">'
                . esc_html($text('projects_button_label', 'View Our Projects')) . '</button></p>';
        }

        $out .= '</section>';
    }

    if ($items !== array()) {
        $out .= '<section data-view="projects"' . ($work !== array() ? ' hidden' : '') . '>'
            . '<div class="ezg-bar"><input type="search" placeholder="' . esc_attr($text('search_placeholder', 'Search…')) . '" aria-label="Search pictures" />';

        if (count($categories) > 1) {
            $out .= '<select aria-label="Category"><option value="">' . esc_html($text('filter_all_label', 'All categories')) . '</option>';

            foreach ($categories as $category) {
                $out .= '<option value="' . esc_attr(strtolower($category)) . '">' . esc_html($category) . '</option>';
            }

            $out .= '</select>';
        }

        $out .= '</div><div class="ezg-grid" data-per="' . $per_page . '">';

        foreach ($items as $item) {
            $out .= $figure($item);
        }

        $out .= '</div><p class="ezg-empty" hidden>' . esc_html($text('empty_text', 'No pictures found.')) . '</p><nav class="ezg-pages"></nav>';

        if ($work !== array()) {
            $out .= '<p class="ezg-actions"><button type="button" class="ezg-switch" data-show="work">'
                . esc_html($text('back_button_label', 'Back to Our Work')) . '</button></p>';
        }

        $out .= '</section>';
    }

    $out .= '</div>';

    $out .= "<script>(function(){
var g=document.getElementById(" . wp_json_encode($id) . ");if(!g)return;
function page(sec,n){
  var grid=sec.querySelector('.ezg-grid'),per=+grid.getAttribute('data-per')||12,nav=sec.querySelector('.ezg-pages');
  var q=(sec.querySelector('input')||{}).value||'',c=(sec.querySelector('select')||{}).value||'';q=q.toLowerCase();
  var shown=Array.prototype.filter.call(grid.children,function(f){var cat=f.getAttribute('data-cat');return(!c||cat===c)&&(!q||cat.indexOf(q)>-1||(f.textContent||'').toLowerCase().indexOf(q)>-1);});
  var pages=Math.max(1,Math.ceil(shown.length/per));n=Math.min(Math.max(1,n),pages);
  Array.prototype.forEach.call(grid.children,function(f){f.hidden=true;});
  shown.slice((n-1)*per,n*per).forEach(function(f){f.hidden=false;});
  var e=sec.querySelector('.ezg-empty');if(e)e.hidden=shown.length>0;
  nav.innerHTML='';if(pages<2)return;
  for(var i=1;i<=pages;i++){(function(i){var b=document.createElement('button');b.type='button';b.textContent=i;if(i===n)b.setAttribute('aria-current','true');b.onclick=function(){page(sec,i);};nav.appendChild(b);})(i);}
}
Array.prototype.forEach.call(g.querySelectorAll('section'),function(sec){
  page(sec,1);
  Array.prototype.forEach.call(sec.querySelectorAll('input,select'),function(i){i.addEventListener('input',function(){page(sec,1);});});
});
Array.prototype.forEach.call(g.querySelectorAll('[data-show]'),function(b){b.addEventListener('click',function(){
  var to=b.getAttribute('data-show');Array.prototype.forEach.call(g.querySelectorAll('section'),function(s){s.hidden=s.getAttribute('data-view')!==to;});
  g.scrollIntoView({behavior:'smooth',block:'start'});
});});
function zoom(f){var img=f.querySelectorAll('img');img=img[img.length-1];if(!img)return;
  var box=document.createElement('div');box.className='ezg-box';box.innerHTML='<img alt=\"\">';box.firstChild.src=img.src;
  function close(){box.remove();document.removeEventListener('keydown',key);}function key(e){if(e.key==='Escape')close();}
  box.onclick=close;document.addEventListener('keydown',key);document.body.appendChild(box);}
g.addEventListener('click',function(e){var f=e.target.closest('.ezg-item');if(f)zoom(f);});
g.addEventListener('keydown',function(e){var f=e.target.closest&&e.target.closest('.ezg-item');if(f&&(e.key==='Enter'||e.key===' ')){e.preventDefault();zoom(f);}});
})();</script>";

    return $out;
}

// ── Video carousel ──────────────────────────────────────────────────

/** A YouTube video id from any of the link shapes people paste, or ''. */
function ezbizi_youtube_id($url) {
    $url = trim((string) $url);

    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:shorts/|embed/|live/|v/|watch\?(?:[^#]*&)?v=))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }

    return preg_match('/^[A-Za-z0-9_-]{11}$/', $url) ? $url : '';
}

/**
 * The carousel's markup from its settings.
 *
 * A row that scrolls, snapping a card at a time, with arrows; not a slider
 * library. Tapping a card swaps its thumbnail for the player, so a page with
 * 7 videos loads 7 pictures and no YouTube until somebody asks for one.
 *
 * @param array $s Elementor settings.
 */
function ezbizi_video_carousel_render($s) {
    $videos = array();

    foreach ((isset($s['videos']) && is_array($s['videos']) ? $s['videos'] : array()) as $row) {
        $vid = ezbizi_youtube_id(isset($row['video_url']) ? (is_array($row['video_url']) ? ($row['video_url']['url'] ?? '') : $row['video_url']) : '');

        if ($vid === '') {
            continue;
        }

        $thumb = isset($row['custom_thumbnail']['url']) && $row['custom_thumbnail']['url'] !== ''
            ? (string) $row['custom_thumbnail']['url']
            : 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg';

        $videos[] = array('id' => $vid, 'title' => isset($row['video_title']) ? (string) $row['video_title'] : '', 'thumb' => $thumb);
    }

    if ($videos === array()) {
        return '';
    }

    $on = function ($k, $default) use ($s) {
        return isset($s[$k]) ? $s[$k] === 'yes' : $default;
    };
    $per_view = max(1, min(6, (int) (isset($s['slides_per_view']) && $s['slides_per_view'] !== '' ? $s['slides_per_view'] : 4)));
    $step = max(1, (int) (isset($s['slides_to_scroll']) && $s['slides_to_scroll'] !== '' ? $s['slides_to_scroll'] : 1));
    $speed = max(1500, (int) (isset($s['autoplay_speed']) && $s['autoplay_speed'] !== '' ? $s['autoplay_speed'] : 5000));
    $autoplay = $on('carousel_autoplay', false);
    $titles = $on('show_title', false);

    $params = 'autoplay=1&playsinline=1&rel=0'
        . ($on('video_mute', false) ? '&mute=1' : '')
        . ($on('video_loop', false) ? '&loop=1' : '');

    $palette = function_exists('ezbizi_widget_palette') ? ezbizi_widget_palette() : array();
    $accent = isset($palette['accent']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $palette['accent']) ? $palette['accent'] : '#0b5cad';

    $id = function_exists('ezbizi_widget_id') ? ezbizi_widget_id('ezbizi-videos') : 'ezbizi-videos-' . wp_rand();
    $c = '#' . $id;

    // A1's arrow, dot and gap settings, read under A1's own names.
    $hex = function ($k, $default) use ($s) {
        return isset($s[$k]) && is_string($s[$k]) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $s[$k]) ? $s[$k] : $default;
    };
    $px = function ($k, $default, $min, $max) use ($s) {
        $v = isset($s[$k]['size']) && is_numeric($s[$k]['size']) ? (int) $s[$k]['size'] : $default;

        return max($min, min($max, $v));
    };
    $gap = $px('gap', 12, 0, 60);
    $box = $px('arrows_box_size', 40, 20, 80);
    $glyph = $px('arrows_icon_size', 22, 10, 64);
    $outside = isset($s['arrows_position']) && $s['arrows_position'] === 'outside';
    $dots = $on('show_dots', true) && count($videos) > $per_view;

    $out = '<style>'
        . $c . '{position:relative;--n:' . $per_view . ($outside ? ';padding:0 ' . ($box + 8) . 'px' : '') . '}'
        . '@media (max-width:1024px){' . $c . '{--n:' . min(3, $per_view) . '}}'
        . '@media (max-width:767px){' . $c . '{--n:' . min(2, $per_view) . '}}'
        . $c . ' .ezv-track{display:flex;gap:' . $gap . 'px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none;padding:0 2px}'
        . $c . ' .ezv-track::-webkit-scrollbar{display:none}'
        . $c . ' .ezv-card{flex:0 0 calc((100% - (var(--n) - 1) * ' . $gap . 'px) / var(--n));scroll-snap-align:start;margin:0}'
        . $c . ' .ezv-frame{position:relative;aspect-ratio:9/16;border-radius:10px;overflow:hidden;background:#000}'
        . $c . ' .ezv-frame img,' . $c . ' .ezv-frame iframe{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border:0}'
        . $c . ' .ezv-play{position:absolute;inset:0;margin:auto;width:64px;height:64px;border-radius:50%;border:0;background:' . $accent . ';cursor:pointer;display:flex;align-items:center;justify-content:center}'
        . $c . ' .ezv-play:before{content:"";border-style:solid;border-width:11px 0 11px 18px;border-color:transparent transparent transparent #fff;margin-left:4px}'
        . $c . ' figcaption{margin-top:6px;font-size:.95em}'
        . $c . ' .ezv-arrow{position:absolute;top:50%;transform:translateY(-50%);width:' . $box . 'px;height:' . $box . 'px;padding:0;border-radius:50%;border:0;'
        . 'background:' . $hex('arrows_bg', 'rgba(0,0,0,.55)') . ';color:' . $hex('arrows_color', '#fff') . ';font-size:' . $glyph . 'px;line-height:' . $box . 'px;cursor:pointer;z-index:2}'
        . $c . ' .ezv-arrow:hover{background:' . $hex('arrows_bg_hover', $hex('arrows_bg', 'rgba(0,0,0,.7)')) . ';color:' . $hex('arrows_color_hover', $hex('arrows_color', '#fff')) . '}'
        . $c . ' .ezv-prev{left:' . ($outside ? '0' : '-6px') . '}' . $c . ' .ezv-next{right:' . ($outside ? '0' : '-6px') . '}'
        . $c . ' .ezv-dots{display:flex;justify-content:center;gap:6px;margin-top:10px}'
        . $c . ' .ezv-dots button{width:8px;height:8px;padding:0;border:0;border-radius:50%;cursor:pointer;background:' . $hex('dots_color', '#c8ccd2') . '}'
        . $c . ' .ezv-dots button[aria-current="true"]{background:' . $hex('dots_active_color', $accent) . '}'
        . '</style>';

    $out .= '<div class="ezbizi-videos" id="' . esc_attr($id) . '"><div class="ezv-track">';

    foreach ($videos as $v) {
        $label = $v['title'] !== '' ? $v['title'] : 'Play video';
        $out .= '<figure class="ezv-card"><div class="ezv-frame" data-src="' . esc_attr('https://www.youtube-nocookie.com/embed/' . $v['id'] . '?' . $params . (strpos($params, 'loop=1') !== false ? '&playlist=' . $v['id'] : '')) . '">'
            . '<img src="' . esc_url($v['thumb']) . '" alt="" loading="lazy" />'
            . '<button type="button" class="ezv-play" aria-label="' . esc_attr($label) . '"></button></div>'
            . ($titles && $v['title'] !== '' ? '<figcaption>' . esc_html($v['title']) . '</figcaption>' : '')
            . '</figure>';
    }

    $out .= '</div>';

    if (count($videos) > $per_view) {
        $out .= '<button type="button" class="ezv-arrow ezv-prev" aria-label="Previous">&#8249;</button>'
            . '<button type="button" class="ezv-arrow ezv-next" aria-label="Next">&#8250;</button>';
    }

    if ($dots) {
        $pages = (int) ceil((count($videos) - $per_view) / $step) + 1;
        $out .= '<div class="ezv-dots">';

        for ($i = 0; $i < $pages; $i++) {
            $out .= '<button type="button" aria-label="' . esc_attr(sprintf('Page %d', $i + 1)) . '"' . ($i === 0 ? ' aria-current="true"' : '') . '></button>';
        }

        $out .= '</div>';
    }

    $out .= '</div>';

    $out .= "<script>(function(){
var c=document.getElementById(" . wp_json_encode($id) . ");if(!c)return;
var t=c.querySelector('.ezv-track'),step=" . $step . ",timer=null,played=false;
function card(){var k=t.querySelector('.ezv-card');return k?k.getBoundingClientRect().width+" . $gap . ":t.clientWidth;}
function go(d){var end=t.scrollWidth-t.clientWidth-2;
  if(d>0&&t.scrollLeft>=end){t.scrollLeft=0;return;}if(d<0&&t.scrollLeft<=0){t.scrollLeft=end;return;}
  t.scrollBy({left:d*step*card(),behavior:'smooth'});}
var p=c.querySelector('.ezv-prev'),n=c.querySelector('.ezv-next');
if(p)p.onclick=function(){go(-1);};if(n)n.onclick=function(){go(1);};
var ds=c.querySelectorAll('.ezv-dots button');
Array.prototype.forEach.call(ds,function(b,i){b.onclick=function(){t.scrollTo({left:i*step*card(),behavior:'smooth'});};});
t.addEventListener('scroll',function(){if(!ds.length)return;var i=Math.min(ds.length-1,Math.round(t.scrollLeft/(step*card())));
  Array.prototype.forEach.call(ds,function(b,j){b.setAttribute('aria-current',j===i?'true':'false');});});
function stop(){clearInterval(timer);timer=null;}
function start(){if(" . ($autoplay ? 'true' : 'false') . "&&!played&&!timer&&t.scrollWidth>t.clientWidth)timer=setInterval(function(){go(1);}," . $speed . ");}
c.addEventListener('mouseenter',stop);c.addEventListener('mouseleave',start);c.addEventListener('focusin',stop);
t.addEventListener('click',function(e){var b=e.target.closest('.ezv-play');if(!b)return;
  var f=b.parentNode,i=document.createElement('iframe');i.src=f.getAttribute('data-src');
  i.allow='autoplay; encrypted-media; picture-in-picture';i.allowFullscreen=true;i.title=b.getAttribute('aria-label');
  f.innerHTML='';f.appendChild(i);played=true;stop();});
start();
})();</script>";

    return $out;
}

// ── The Elementor faces ─────────────────────────────────────────────

if (class_exists('EzBizi_Elementor_Widget')) {
    /** Pictures from this site's media library, in two views. */
    class EzBizi_Elementor_Gallery extends EzBizi_Elementor_Widget {
        public function get_name() {
            return 'ezbizi-gallery';
        }

        public function get_title() {
            return esc_html__('EzBizi Gallery', 'ezbizi');
        }

        public function get_icon() {
            return 'eicon-gallery-grid';
        }

        public function get_keywords() {
            return array('ezbizi', 'gallery', 'photos', 'projects', 'before after', 'work');
        }

        protected function register_controls() {
            $this->start_controls_section('ezbizi_gallery_work', array('label' => esc_html__('Our work', 'ezbizi')));
            $this->add_control('work_gallery', array(
                'label' => esc_html__('Pictures shown first', 'ezbizi'),
                'type' => \Elementor\Controls_Manager::GALLERY,
                'description' => esc_html__('Optional. A plain grid, with a button to the projects below.', 'ezbizi'),
            ));
            $this->add_control('work_per_page', array('label' => esc_html__('Per page', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 9, 'min' => 1));
            $this->add_control('projects_button_label', array('label' => esc_html__('Button to the projects', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'View Our Projects'));
            $this->add_control('back_button_label', array('label' => esc_html__('Button back', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Back to Our Work'));
            $this->end_controls_section();

            $this->start_controls_section('ezbizi_gallery_projects', array('label' => esc_html__('Projects', 'ezbizi')));
            $rows = new \Elementor\Repeater();
            $rows->add_control('image', array('label' => esc_html__('Picture', 'ezbizi'), 'type' => \Elementor\Controls_Manager::MEDIA));
            $rows->add_control('category', array('label' => esc_html__('Category', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT));
            $rows->add_control('before_image', array('label' => esc_html__('Before picture (optional)', 'ezbizi'), 'type' => \Elementor\Controls_Manager::MEDIA));
            $rows->add_control('before_label', array('label' => esc_html__('Before label', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Before'));
            $rows->add_control('after_label', array('label' => esc_html__('After label', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'After'));
            $this->add_control('images', array('label' => esc_html__('Pictures', 'ezbizi'), 'type' => \Elementor\Controls_Manager::REPEATER, 'fields' => $rows->get_controls(), 'title_field' => '{{{ category }}}'));

            $groups = new \Elementor\Repeater();
            $groups->add_control('category', array('label' => esc_html__('Category', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT));
            $groups->add_control('gallery', array('label' => esc_html__('Pictures', 'ezbizi'), 'type' => \Elementor\Controls_Manager::GALLERY));
            $this->add_control('bulk_groups', array('label' => esc_html__('Many pictures, one category', 'ezbizi'), 'type' => \Elementor\Controls_Manager::REPEATER, 'fields' => $groups->get_controls(), 'title_field' => '{{{ category }}}'));

            $this->add_control('columns', array('label' => esc_html__('Columns', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 3, 'min' => 1, 'max' => 6));
            $this->add_control('per_page', array('label' => esc_html__('Per page', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 12, 'min' => 1));
            $this->add_control('search_placeholder', array('label' => esc_html__('Search box text', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Search…'));
            $this->add_control('filter_all_label', array('label' => esc_html__('All-categories label', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'All categories'));
            $this->add_control('empty_text', array('label' => esc_html__('When nothing matches', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'No pictures found.'));
            $this->end_controls_section();
        }

        protected function render() {
            // Everything printed is escaped inside the render function.
            echo ezbizi_gallery_render($this->get_settings_for_display());
        }
    }

    /** YouTube videos as a row of tall cards. */
    class EzBizi_Elementor_Video_Carousel extends EzBizi_Elementor_Widget {
        public function get_name() {
            return 'ezbizi-video-carousel';
        }

        public function get_title() {
            return esc_html__('EzBizi Video Carousel', 'ezbizi');
        }

        public function get_icon() {
            return 'eicon-slider-video';
        }

        public function get_keywords() {
            return array('ezbizi', 'video', 'youtube', 'shorts', 'carousel', 'slider');
        }

        protected function register_controls() {
            $this->start_controls_section('ezbizi_videos', array('label' => esc_html__('Videos', 'ezbizi')));
            $rows = new \Elementor\Repeater();
            $rows->add_control('video_url', array('label' => esc_html__('YouTube link', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT, 'placeholder' => 'https://www.youtube.com/shorts/…'));
            $rows->add_control('video_title', array('label' => esc_html__('Title', 'ezbizi'), 'type' => \Elementor\Controls_Manager::TEXT));
            $rows->add_control('custom_thumbnail', array('label' => esc_html__('Own thumbnail (optional)', 'ezbizi'), 'type' => \Elementor\Controls_Manager::MEDIA));
            $this->add_control('videos', array('label' => esc_html__('Videos', 'ezbizi'), 'type' => \Elementor\Controls_Manager::REPEATER, 'fields' => $rows->get_controls(), 'title_field' => '{{{ video_title || video_url }}}'));

            $this->add_control('slides_per_view', array('label' => esc_html__('Cards in view', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 4, 'min' => 1, 'max' => 6));
            $this->add_control('slides_to_scroll', array('label' => esc_html__('Cards per arrow', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 1, 'min' => 1));
            $this->add_control('show_title', array('label' => esc_html__('Show titles', 'ezbizi'), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes'));
            $this->add_control('carousel_autoplay', array('label' => esc_html__('Move on its own', 'ezbizi'), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes'));
            $this->add_control('autoplay_speed', array('label' => esc_html__('Every (ms)', 'ezbizi'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 5000, 'condition' => array('carousel_autoplay' => 'yes')));
            $this->add_control('video_mute', array('label' => esc_html__('Start muted', 'ezbizi'), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes'));
            $this->add_control('video_loop', array('label' => esc_html__('Loop a video', 'ezbizi'), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes'));
            $this->end_controls_section();
        }

        protected function render() {
            echo ezbizi_video_carousel_render($this->get_settings_for_display());
        }
    }
}
