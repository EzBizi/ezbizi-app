<?php
/**
 * The two things a visitor can start on a customer's website: a chat and a form.
 *
 * ── The shortcodes take no arguments, and that is the point ──
 *
 * Everything a visitor sees — the colours, the typeface, the wording, which
 * questions the form asks, whether either widget is on at all — is set in
 * EzBizi under Web Management → Widgets and arrives here with the values. So
 * `[ezbizi_chat]` is the whole shortcode, and changing the accent colour or
 * adding a question is a change somebody makes in EzBizi once, for every site
 * that follows the primary, without editing a WordPress page or updating this
 * plugin.
 *
 * A site owner who could also set these in the shortcode would be a site owner
 * with two places to set them and no way to tell which won.
 *
 * ── Why these post to EzBizi and not to WordPress ──
 *
 * An enquiry is a Lead, and Leads live in EzBizi beside the ones from Google
 * and Thumbtack. Storing them here first would put half a company's enquiries
 * on a server we do not run, in a table nobody reads, waiting for a sync to go
 * wrong. The visitor's browser talks to EzBizi directly, with the PUBLIC key
 * that is already in page source for the values snippet.
 *
 * ── What the public key can and cannot do ──
 *
 * It can start an enquiry on this site, and that is all. Reading a conversation
 * back needs the per-conversation token EzBizi mints when a chat begins, which
 * lives in this browser's sessionStorage and nowhere else.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** The public key this site publishes with, or '' if it has never had one. */
function ezbizi_public_key() {
    $payload = ezbizi_payload();

    return isset($payload['public_key']) && is_string($payload['public_key'])
        ? $payload['public_key']
        : '';
}

/** The API base, for the browser to call. */
function ezbizi_api_base() {
    $settings = ezbizi_settings();

    return rtrim($settings['api'], '/');
}

/**
 * The widget settings EzBizi sent, or an empty shape.
 *
 * Never invents defaults of its own. The server sanitises and completes the
 * config before sending it, so a missing key here means this site has never had
 * a successful read — in which case the widgets render nothing rather than
 * guessing at somebody's colours.
 */
/**
 * How long EzBizi has been refusing this site, in seconds, or 0 if it is not.
 *
 * Its own function because two different things ask: the widgets, which stop
 * drawing, and the notice that tells an administrator why.
 */
function ezbizi_refused_for() {
    $since = (int) get_option(EZBIZI_REFUSED_SINCE, 0);

    return $since > 0 ? max(0, time() - $since) : 0;
}

/**
 * Whether EzBizi is refusing this site at all.
 *
 * Asked as a yes or no rather than as a duration, because a duration is zero
 * for the first second of a refusal and "has this lasted long enough" is not
 * the question any more.
 */
function ezbizi_is_refused() {
    return (int) get_option(EZBIZI_REFUSED_SINCE, 0) > 0;
}

function ezbizi_widget_config($kind) {
    /*
     * A site EzBizi has been refusing for a week draws no widgets.
     *
     * The deploy desk found the hole: the guard below asks whether a key is
     * ABSENT, and a site whose token the server rejects still has the last
     * good payload, key and all. So a pairing that had ended went on showing a
     * chat box on every page. The values are a different question and keep
     * publishing — a phone number that is a week stale is still the right
     * phone number, while a chat box is a promise that somebody is listening.
     */
    if (ezbizi_is_refused()) {
        return array();
    }

    $payload = ezbizi_payload();

    if (! isset($payload['widgets']) || ! is_array($payload['widgets'])) {
        return array();
    }

    $widgets = $payload['widgets'];

    if (! isset($widgets[$kind]) || ! is_array($widgets[$kind])) {
        return array();
    }

    $config = $widgets[$kind];
    $config['style'] = isset($widgets['style']) && is_array($widgets['style'])
        ? $widgets['style']
        : array();

    return $config;
}

/** One config value as a string, or '' — never a warning on somebody's page. */
function ezbizi_widget_text($config, $key, $fallback = '') {
    return isset($config[$key]) && is_scalar($config[$key])
        ? (string) $config[$key]
        : $fallback;
}

/**
 * The shared look, as a scoped stylesheet.
 *
 * Every value is interpolated into CSS on a page we do not serve, so every one
 * of them is escaped here as well as validated on the server. Two gates for the
 * same value is deliberate: this one is the last thing before a stranger's
 * browser, and it cannot see what the other one allowed.
 */
function ezbizi_widget_style($id, $style) {
    $accent = esc_attr(ezbizi_widget_text($style, 'accent', '#0b5cad'));
    $on_accent = esc_attr(ezbizi_widget_text($style, 'on_accent', '#ffffff'));
    $text = esc_attr(ezbizi_widget_text($style, 'text', '#1b1b1b'));
    $background = esc_attr(ezbizi_widget_text($style, 'background', '#ffffff'));
    $font = esc_attr(ezbizi_widget_text($style, 'font', 'inherit'));
    $radius = (int) ezbizi_widget_text($style, 'radius', '12');

    return '<style>#' . esc_attr($id) . '{'
        . '--ezbizi-accent:' . $accent . ';'
        . '--ezbizi-on-accent:' . $on_accent . ';'
        . '--ezbizi-text:' . $text . ';'
        . '--ezbizi-bg:' . $background . ';'
        . 'font-family:' . $font . ';'
        . 'color:var(--ezbizi-text);'
        . '}'
        . '#' . esc_attr($id) . ' button{background:var(--ezbizi-accent);color:var(--ezbizi-on-accent);'
        . 'border:0;border-radius:' . $radius . 'px;padding:10px 16px;cursor:pointer;font:inherit}'
        . '#' . esc_attr($id) . ' input,#' . esc_attr($id) . ' textarea,#' . esc_attr($id) . ' select{'
        . 'width:100%;padding:8px;border:1px solid #ccc;border-radius:' . max(0, $radius - 6) . 'px;font:inherit}'
        . '#' . esc_attr($id) . ' .ezbizi-consent-box,#' . esc_attr($id) . ' .ezbizi-antibot-box{width:auto;margin:.2em 8px 0 0;padding:0;flex:0 0 auto}'
        . '#' . esc_attr($id) . ' .ezbizi-consent label,#' . esc_attr($id) . ' .ezbizi-antibot label{display:flex;align-items:flex-start;line-height:1.5}'
        . '#' . esc_attr($id) . ' .ezbizi-consent a{color:var(--ezbizi-accent);text-decoration:underline}'
        . '</style>';
}

/**
 * A unique id for one widget on this page.
 *
 * ── Why not a random number ──
 *
 * It was `wp_rand(1000, 9999)`, which is fine for one form on a page and not
 * for the case Jose described: a contact form dropped into several places on
 * the same page. Two draws can match — one in nine thousand per pair, which is
 * small until you multiply it by every page view of every site — and when they
 * do, the second form's script binds to the first form's element and one of
 * them silently stops working.
 *
 * Counting removes the possibility rather than shrinking it. Per request,
 * which is the only scope that matters: ids need to be unique within one
 * rendered page and nothing compares them across two.
 */
function ezbizi_widget_id($prefix) {
    static $n = 0;

    return $prefix . '-' . (++$n);
}

/** Nothing on a visitor's page; a sentence for somebody who can fix it. */
function ezbizi_widget_silent($why) {
    return current_user_can('manage_options') ? '<p>' . esc_html($why) . '</p>' : '';
}

/** [ezbizi_form] — the contact form EzBizi describes, landing as a Lead. */
/** Consent words, escaped, with [words](https://…) drawn as a link. */
function ezbizi_consent_html($text) {
    $parts = preg_split('~\[([^\]]+)\]\((https?://[^\s()"\'<>]+)\)~', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';

    // Every third piece is plain text, then the link's words, then its address.
    foreach ($parts as $i => $part) {
        if ($i % 3 === 0) {
            $out .= esc_html($part);
        } elseif ($i % 3 === 1) {
            $out .= '<a href="' . esc_url($parts[$i + 1]) . '" target="_blank" rel="noopener">' . esc_html($part) . '</a>';
        }
    }

    return $out;
}

function ezbizi_form_shortcode($atts) {
    $key = ezbizi_public_key();

    if ($key === '') {
        return ezbizi_widget_silent(
            __('EzBizi: this site has no browser key yet. Click EzBizi in the sidebar.', 'ezbizi')
        );
    }

    $config = ezbizi_widget_config('form');

    if (empty($config)) {
        return ezbizi_widget_silent(
            __('EzBizi: no widget settings have arrived yet. They come with the next refresh.', 'ezbizi')
        );
    }

    $fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();

    if (empty($fields)) {
        return '';
    }

    // The questions and words come from the form's config; a preset only
    // styles them (see WidgetPresets `form`), which is all an argument can
    // reach — the fields and the button are EzBizi's.
    $preset = ezbizi_preset('form', $atts);
    $id = ezbizi_widget_id('ezbizi-form');
    $gap = max(0, min(40, (int) (isset($preset['field_gap']) ? $preset['field_gap'] : 12)));
    $out = ezbizi_widget_style($id, $config['style']);
    $out .= '<style>#' . $id . ' .ezbizi-form-fields{display:flex;flex-wrap:wrap;gap:' . $gap . 'px}'
        . '#' . $id . ' .ezbizi-field{margin:0;box-sizing:border-box}'
        . '#' . $id . ' .ezbizi-field label{display:block;margin:0 0 4px}'
        . '#' . $id . ' .ezbizi-help{display:block;opacity:.8;font-size:.85em;margin-top:3px}'
        . '#' . $id . ' .ezbizi-choice{display:flex;align-items:center;gap:6px;margin:2px 0}'
        . '#' . $id . ' .ezbizi-choice input{width:auto}'
        . '#' . $id . ' .ezbizi-form-title{margin:0 0 4px}'
        . '#' . $id . ' .ezbizi-form-subtitle{margin:0 0 14px}'
        . (isset($preset['button_width']) && $preset['button_width'] === 'full' ? '#' . $id . ' .ezbizi-form-submit{width:100%}' : '')
        . '@media (max-width:767px){#' . $id . ' .ezbizi-field{flex-basis:100%!important;max-width:100%!important}}'
        . '</style>';

    $out .= '<form class="ezbizi-form" id="' . esc_attr($id) . '" novalidate>';

    $title = ezbizi_widget_text($config, 'title');
    $subtitle = ezbizi_widget_text($config, 'subtitle');

    if ($title !== '') {
        $out .= '<h3 class="ezbizi-form-title">' . esc_html($title) . '</h3>';
    }

    if ($subtitle !== '') {
        $out .= '<p class="ezbizi-form-subtitle">' . esc_html($subtitle) . '</p>';
    }

    $out .= '<div class="ezbizi-form-fields">';

    foreach ($fields as $index => $field) {
        if (! is_array($field)) {
            continue;
        }

        $label = ezbizi_widget_text($field, 'label');

        if ($label === '') {
            continue;
        }

        $type = ezbizi_widget_text($field, 'type', 'text');
        $required = ! empty($field['required']);
        $field_id = $id . '-' . $index;
        $placeholder = ezbizi_widget_text($field, 'placeholder');
        $help = ezbizi_widget_text($field, 'help');
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        $width = in_array((int) (isset($field['width']) ? $field['width'] : 100), array(100, 75, 66, 50, 33, 25), true)
            ? (int) $field['width'] : 100;

        if ($type === 'hidden') {
            $out .= '<input type="hidden" name="' . esc_attr($label) . '" value="' . esc_attr(ezbizi_widget_text($field, 'value')) . '" />';

            continue;
        }

        // Shown only when another field's answer matches (Toula); the script below toggles it.
        $if = isset($field['show_if']['field']) ? ' data-if-field="' . esc_attr((string) $field['show_if']['field']) . '"'
            . ' data-if-value="' . esc_attr(isset($field['show_if']['value']) ? (string) $field['show_if']['value'] : '') . '"' : '';
        $out .= '<div class="ezbizi-field"' . $if . ' style="flex:0 0 calc(' . $width . '% - ' . $gap . 'px);max-width:' . $width . '%">';
        $mark = $required ? ' <span class="ezbizi-required" style="color:var(--ezbizi-accent)" aria-hidden="true">*</span>' : '';
        $common = 'name="' . esc_attr($label) . '"' . ($required ? ' required' : '');

        if ($type === 'checkbox' || $type === 'radio') {
            $out .= '<fieldset style="border:0;margin:0;padding:0"><legend>' . esc_html($label) . $mark . '</legend>';
            $choices = $options === array() ? array(__('Yes', 'ezbizi')) : $options;

            foreach ($choices as $i => $option) {
                $option = (string) $option;
                $out .= '<label class="ezbizi-choice"><input type="' . $type . '" ' . $common
                    . ' value="' . esc_attr($option) . '" data-group="' . esc_attr($label) . '" /> '
                    . esc_html($options === array() ? $label : $option) . '</label>';
            }

            $out .= '</fieldset>';
        } else {
            $out .= '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . $mark . '</label>';
            $attrs = 'id="' . esc_attr($field_id) . '" class="ezbizi-input" ' . $common
                . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '');

            if ($type === 'textarea') {
                $out .= '<textarea ' . $attrs . ' rows="4"></textarea>';
            } elseif ($type === 'select') {
                $out .= '<select ' . $attrs . '><option value="">' . esc_html($placeholder !== '' ? $placeholder : __('Choose…', 'ezbizi')) . '</option>';

                foreach ($options as $option) {
                    $option = (string) $option;
                    $out .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                }

                $out .= '</select>';
            } elseif ($type === 'file') {
                // Photos and documents, 10 MB each: what EzBizi accepts.
                $out .= '<input type="file" ' . $attrs . ' accept="image/*,.heic,.pdf,.doc,.docx,.txt" />';
            } else {
                $html_type = in_array($type, array('email', 'tel', 'number', 'date', 'time'), true) ? $type : 'text';
                $out .= '<input type="' . esc_attr($html_type) . '" ' . $attrs . ' />';
            }
        }

        if ($help !== '') {
            $out .= '<small class="ezbizi-help">' . esc_html($help) . '</small>';
        }

        $out .= '</div>';
    }

    $out .= '</div>';

    /*
     * THE OPT-IN, unticked. Jose, 2026-09-16: somebody who sent an enquiry has
     * not agreed to marketing email, so the box must be ticked by them. The
     * words go with the tick: EzBizi records exactly what they agreed to.
     */
    if (! isset($config['consent_on']) || ! empty($config['consent_on'])) {
        $out .= '<p class="ezbizi-consent"><label><input type="checkbox" class="ezbizi-consent-box" /> <span>'
            . ezbizi_consent_html(ezbizi_widget_text($config, 'consent', 'Yes, email me news and offers. I can unsubscribe at any time.'))
            . '</span></label></p>';
    }

    // A box a person ticks and a script does not bother with: A1's "I'm not a
    // robot". Not a CAPTCHA, and not presented as one.
    if (! empty($config['antibot'])) {
        $out .= '<p class="ezbizi-antibot"><label><input type="checkbox" class="ezbizi-antibot-box" /> <span>'
            . esc_html(ezbizi_widget_text($config, 'antibot_label', "I'm not a robot")) . '</span></label></p>';
    }

    $out .= '<p><button type="submit" class="ezbizi-form-submit">' . esc_html(ezbizi_widget_text($config, 'button', 'Send')) . '</button></p>';
    $out .= '<p class="ezbizi-form-note" role="status" aria-live="polite"></p>';
    $out .= '</form>';

    $redirect = ezbizi_widget_text($config, 'redirect');
    $redirect = preg_match('~^https?://~i', $redirect) ? esc_url_raw($redirect) : '';

    $out .= "<script>(function(){
var f=document.getElementById(" . wp_json_encode($id) . ");
if(!f)return;
var note=f.querySelector('.ezbizi-form-note');
var api=" . wp_json_encode(ezbizi_api_base()) . ",captcha=" . (! empty($config['captcha']) ? 'true' : 'false') . ";
function answer(n){var out=[];Array.prototype.forEach.call(f.querySelectorAll('[name]'),function(i){
  if(i.name!==n||i.disabled)return;if((i.type==='checkbox'||i.type==='radio')&&!i.checked)return;if(i.value)out.push(i.value);});return out.join(', ');}
function sync(){Array.prototype.forEach.call(f.querySelectorAll('[data-if-field]'),function(w){
  var a=answer(w.getAttribute('data-if-field')).toLowerCase(),want=w.getAttribute('data-if-value').toLowerCase();
  var on=want===''?a!=='':a.split(', ').indexOf(want)>=0;w.style.display=on?'':'none';
  Array.prototype.forEach.call(w.querySelectorAll('input,textarea,select'),function(i){i.disabled=!on;});});}
f.addEventListener('change',sync);f.addEventListener('input',sync);sync();
function hex(b){return Array.prototype.map.call(new Uint8Array(b),function(x){return('0'+x.toString(16)).slice(-2);}).join('');}
function solve(){return fetch(api+'/api/v1/web/form/challenge').then(function(r){return r.json();}).then(function(c){
  var enc=new TextEncoder(),n=0;
  function step(){var batch=[];for(var k=0;k<500&&n+k<=c.max;k++)batch.push(n+k);
    return Promise.all(batch.map(function(x){return crypto.subtle.digest('SHA-256',enc.encode(c.salt+x)).then(function(h){return hex(h)===c.challenge?x:-1;});}))
    .then(function(rs){var hit=rs.filter(function(x){return x>=0;});
      if(hit.length)return JSON.stringify({salt:c.salt,number:hit[0],challenge:c.challenge,signature:c.signature});
      n+=500;if(n>c.max)throw new Error('captcha');return step();});}
  return step();});}
f.addEventListener('submit',function(e){
  e.preventDefault();
  var bot=f.querySelector('.ezbizi-antibot-box');
  if(bot&&!bot.checked){note.textContent=" . wp_json_encode(__('Please tick the box to show you are not a robot.', 'ezbizi')) . ";return;}
  if(!f.checkValidity()){f.reportValidity();return;}
  var b=f.querySelector('button');b.disabled=true;
  var fields={},files={},v={},first='',last='';
  Array.prototype.forEach.call(f.querySelectorAll('input,textarea,select'),function(i){
    if(i.disabled||i.classList.contains('ezbizi-consent-box')||i.classList.contains('ezbizi-antibot-box'))return;
    var n=i.name,low=n.toLowerCase(),val=i.value;
    if(i.type==='file'){if(i.files&&i.files[0])files[n]=i.files[0];return;}
    if(i.type==='checkbox'||i.type==='radio'){
      if(!i.checked){if(!(n in fields))fields[n]='';return;}
      fields[n]=fields[n]?fields[n]+', '+val:val;return;
    }
    if(low==='name'||low==='full name'){v.name=val;}
    else if(low==='first name'){first=val;}
    else if(low==='last name'){last=val;}
    else if(low==='email'||low==='email address'){v.email=val;}
    else if(low==='phone'||low==='phone number'){v.phone=val;}
    fields[n]=val;
  });
  if(!v.name&&(first||last))v.name=(first+' '+last).trim();
  v.key=" . wp_json_encode($key) . ";v.fields=fields;v.page_url=location.href;
  var c=f.querySelector('.ezbizi-consent-box');if(c&&c.checked){v.marketing_consent=true;v.marketing_consent_text=c.parentNode.textContent.trim();}
  (captcha?solve():Promise.resolve(null)).then(function(sol){
    if(sol)v.captcha=sol;
    var h={'Accept':'application/json'},body;
    // A file goes as a multipart form; everything else as it always has.
    if(Object.keys(files).length){body=new FormData();
      Object.keys(v).forEach(function(k){if(k!=='fields')body.append(k,v[k]===true?'1':v[k]);});
      Object.keys(fields).forEach(function(k){body.append('fields['+k+']',fields[k]);});
      Object.keys(files).forEach(function(k){body.append('files['+k+']',files[k]);});
    }else{h['Content-Type']='application/json';body=JSON.stringify(v);}
    return fetch(api+'/api/v1/web/form',{method:'POST',headers:h,body:body});
  }).then(function(r){
    if(!r.ok)throw new Error(r.status);
    f.reset();sync();note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'thanks', 'Thank you.')) . ";
    " . ($redirect !== '' ? 'location.href=' . wp_json_encode($redirect) . ';' : '') . "
  }).catch(function(){
    note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'That did not send.')) . ";
  }).then(function(){b.disabled=false;});
});
})();</script>";

    return $out . ezbizi_style_css('form', $id, $preset, array(
        'box' => '', 'title' => ' .ezbizi-form-title', 'subtitle' => ' .ezbizi-form-subtitle',
        'label' => ' .ezbizi-field label', 'input' => ' .ezbizi-input', 'submit' => ' .ezbizi-form-submit',
        'consent' => ' .ezbizi-consent', 'help' => ' .ezbizi-help',
    ));
}

/**
 * [ezbizi_chat] — the chat bubble EzBizi describes.
 *
 * ── Why it polls ──
 *
 * A reply reaches the visitor because the widget asks again, not because
 * anything is pushed. Real-time would mean a websocket server: a daemon, a
 * port, and a scaling story, for a feature nobody is using yet.
 */
function ezbizi_chat_shortcode($atts) {
    $key = ezbizi_public_key();

    if ($key === '') {
        return ezbizi_widget_silent(
            __('EzBizi: this site has no browser key yet. Click EzBizi in the sidebar.', 'ezbizi')
        );
    }

    $config = ezbizi_widget_config('chat');

    if (empty($config)) {
        return ezbizi_widget_silent(
            __('EzBizi: no widget settings have arrived yet. They come with the next refresh.', 'ezbizi')
        );
    }

    /*
     * Drawn once per page however it was asked for. The footer below renders
     * the bubble on every page; a shortcode or an Elementor widget may ask for
     * it too, and two chat bubbles on one page is two conversations.
     */
    $GLOBALS['ezbizi_chat_drawn'] = true;

    /*
     * Pages it never shows on, decided here in PHP. The page cache is keyed by
     * URL, so a path is the one thing a cached page cannot get wrong. A line
     * ending in * covers everything under it: "/careers/*".
     */
    $path = untrailingslashit((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/', PHP_URL_PATH));
    foreach (preg_split('/\R/', ezbizi_widget_text($config, 'hide_on')) as $hide) {
        $hide = trim($hide);
        if ($hide === '') {
            continue;
        }
        if (substr($hide, -1) === '*' ? strpos($path . '/', rtrim($hide, '*')) === 0 : $path === untrailingslashit($hide)) {
            return '';
        }
    }

    $id = ezbizi_widget_id('ezbizi-chat');
    $position = ezbizi_widget_text($config, 'position', 'bottom-right');
    $ox = max(0, min(300, (int) ezbizi_widget_text($config, 'offset_x', '20')));
    $oy = max(0, min(300, (int) ezbizi_widget_text($config, 'offset_y', '88')));

    $anchor = '';

    /*
     * 88px up, not 20.
     *
     * Jose photographed the launcher sitting in a-1chimney.com's yellow call
     * bar. A sticky strip along the bottom — a phone number, a cookie notice,
     * a book-now bar — is ordinary on the sites this plugin is installed on,
     * and the one place a bottom-anchored bubble is guaranteed to collide with
     * something is the bottom. Clearing a typical bar costs a little empty
     * space on sites that have none.
     */
    if ($position === 'bottom-right') {
        $anchor = 'position:fixed;right:' . $ox . 'px;bottom:' . $oy . 'px;z-index:2147483000';
    } elseif ($position === 'bottom-left') {
        $anchor = 'position:fixed;left:' . $ox . 'px;bottom:' . $oy . 'px;z-index:2147483000';
    }
    $delay = max(0, min(120, (int) ezbizi_widget_text($config, 'delay', '0')));
    if ($delay > 0) {
        $anchor .= ($anchor === '' ? '' : ';') . 'display:none';
    }
    $agent = ezbizi_widget_text($config, 'agent_name');
    $avatar = ezbizi_widget_text($config, 'avatar_url');

    $title = ezbizi_widget_text($config, 'title', 'Chat with us');
    $intro = ezbizi_widget_text($config, 'intro');
    $lead_in = ezbizi_widget_text($config, 'lead_in');
    $name_label = ezbizi_widget_text($config, 'name_label', 'Your name');
    $contact_label = ezbizi_widget_text($config, 'contact_label', 'Phone or email');
    $message_label = ezbizi_widget_text($config, 'message_label', 'How can we help?');

    $out = ezbizi_widget_style($id, $config['style']);
    $out .= ezbizi_chat_style($id, $config['style'], $position, $config);

    $out .= '<div class="ezbizi-chat" id="' . esc_attr($id) . '" style="' . esc_attr($anchor) . '">'
        . '<button type="button" class="ezbizi-chat-open" aria-expanded="false">'
        . ezbizi_chat_icon(ezbizi_widget_text($config, 'bubble_icon', 'chat'))
        . '<span>' . esc_html(ezbizi_widget_text($config, 'button', 'Chat')) . '</span>'
        . '</button>'

        . '<div class="ezbizi-chat-panel" hidden role="dialog" aria-label="' . esc_attr($title) . '">'

        . '<div class="ezbizi-chat-head">'
        . (preg_match('~^https?://~i', $avatar) ? '<img class="ezbizi-chat-avatar" src="' . esc_url($avatar) . '" alt="" width="36" height="36" />' : '')
        . '<div class="ezbizi-chat-head-text">'
        . '<strong>' . esc_html($title) . '</strong>'
        . ($agent === '' ? '' : '<span class="ezbizi-chat-agent">' . esc_html($agent) . '</span>')
        . (empty($config['show_status']) ? '' : '<span class="ezbizi-chat-status"><i></i>' . esc_html(ezbizi_widget_text($config, 'online_text', 'Online now')) . '</span>')
        . ($intro === '' ? '' : '<span>' . esc_html($intro) . '</span>')
        . '</div>'
        . '<button type="button" class="ezbizi-chat-close" aria-label="Close">&times;</button>'
        . '</div>'

        . '<div class="ezbizi-chat-body">'

        /*
         * Asked before the conversation, not after it.
         *
         * A chat that opens straight into a message box produces a Lead with
         * nobody's name on it: the visitor types, closes the tab, and the only
         * way to answer is to hope they come back. The same three answers a
         * contact form asks for, asked once, turn every chat into somebody
         * who can be called back.
         */
        . '<form class="ezbizi-chat-pre">'
        . ($lead_in === '' ? '' : '<p class="ezbizi-chat-leadin">' . esc_html($lead_in) . '</p>')
        . '<label>' . esc_html($name_label) . ' <span class="ezbizi-chat-req">*</span>'
        . '<input type="text" name="name" autocomplete="name" required /></label>'
        . '<label>' . esc_html($contact_label) . ' <span class="ezbizi-chat-req">*</span>'
        . '<input type="text" name="contact" autocomplete="email" required /></label>'
        . '<label>' . esc_html($message_label) . ' <span class="ezbizi-chat-req">*</span>'
        . '<textarea name="message" rows="3" required placeholder="'
        . esc_attr(ezbizi_widget_text($config, 'placeholder', 'Type a message')) . '"></textarea></label>'
        . '<p class="ezbizi-consent"><label><input type="checkbox" class="ezbizi-consent-box" /> <span>'
        . esc_html(ezbizi_widget_text($config, 'consent', 'Yes, email me news and offers. I can unsubscribe at any time.'))
        . '</span></label></p>'
        . '<button type="submit" class="ezbizi-chat-start">'
        . esc_html(ezbizi_widget_text($config, 'start', 'Start chat')) . '</button>'
        . '<p class="ezbizi-chat-error" hidden role="alert"></p>'
        . '</form>'

        . '<div class="ezbizi-chat-thread" hidden>'
        . '<div class="ezbizi-chat-log" role="log" aria-live="polite"></div>'
        . '<form class="ezbizi-chat-form">'
        . '<input type="text" class="ezbizi-chat-text" aria-label="' . esc_attr($message_label) . '" placeholder="'
        . esc_attr(ezbizi_widget_text($config, 'placeholder', 'Type a message')) . '" />'
        . '<button type="submit">' . esc_html(ezbizi_widget_text($config, 'send', 'Send')) . '</button>'
        . '</form>'
        . '</div>'

        . '</div></div></div>';

    /*
     * ── The opening-hours gate, and why it is JAVASCRIPT ──
     *
     * Jose, 2026-09-17: the bubble should be on the page during working hours
     * and gone outside them, "cause if no one is there to answer it's bad".
     *
     * The obvious place is here, in PHP: check the clock, return '' when shut.
     * That is WRONG on these sites and it would have looked right for about a
     * day. Every site on the estate serves pages from an nginx page cache, so
     * whatever PHP decides is baked into the cached HTML and served to
     * everybody afterwards — a page generated at 10am keeps showing the bubble
     * at midnight, and a page generated at midnight keeps hiding it at 10am.
     * The bug would be worst on the busiest pages, which are cached longest.
     *
     * So the schedule travels to the browser and the browser decides, once,
     * per view. The cached HTML is the same either way.
     *
     * `Intl.DateTimeFormat` with a `timeZone` reads the clock in the
     * BUSINESS's zone — not the visitor's, who may be anywhere — and it is
     * built into every browser this plugin supports, so there is no library
     * and no offset table to keep in step with the next DST change.
     *
     * IT FAILS OPEN, everywhere: no schedule, seven-entry mismatch, no zone, a
     * weekday name it cannot map, an unparseable time, or any throw at all,
     * and the bubble shows. A wrongly-shown chat is somebody replying late. A
     * wrongly-hidden one is every enquiry that day going nowhere, with nothing
     * on any screen to say so.
     */
    $out .= "<script>(function(){
var root=document.getElementById(" . wp_json_encode($id) . ");
if(!root)return;
var openHours=" . wp_json_encode(isset($config['hours']) && is_array($config['hours']) ? $config['hours'] : array()) . ";
var openZone=" . wp_json_encode(ezbizi_widget_text($config, 'timezone')) . ";
function ezbizi_minutes(hhmm){
  var m=/^([0-9]{2}):([0-9]{2})$/.exec(String(hhmm||''));
  return m?parseInt(m[1],10)*60+parseInt(m[2],10):null;
}
function ezbizi_open_now(){
  if(!openHours||openHours.length!==7||!openZone)return true;
  try{
    var parts=new Intl.DateTimeFormat('en-GB',{timeZone:openZone,weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false}).formatToParts(new Date());
    var got={};
    for(var i=0;i<parts.length;i++)got[parts[i].type]=parts[i].value;
    var index={Mon:0,Tue:1,Wed:2,Thu:3,Fri:4,Sat:5,Sun:6}[got.weekday];
    if(index===undefined)return true;
    var day=openHours[index];
    if(!day)return true;
    if(day.closed)return false;
    // '24' is midnight in some engines under hour12:false, and 24*60 would
    // sit past every close time.
    var hour=parseInt(got.hour,10)%24;
    var now=hour*60+parseInt(got.minute,10);
    var from=ezbizi_minutes(day.open),till=ezbizi_minutes(day.close);
    if(from===null||till===null||isNaN(now))return true;
    // A shift that ends after midnight is two ranges, not one backwards range.
    return till>from?(now>=from&&now<till):(now>=from||now<till);
  }catch(e){return true;}
}
if(!ezbizi_open_now()){if(root.parentNode)root.parentNode.removeChild(root);return;}
var delay=" . (int) $delay . ";
if(delay)setTimeout(function(){root.style.display='';},delay*1000);
var tell=" . (empty($config['analytics']) ? 'function(){}' : "function(e){try{(window.dataLayer=window.dataLayer||[]).push({event:'ezbizi_chat_'+e});if(window.gtag)gtag('event','ezbizi_chat_'+e);}catch(x){}}") . ";
var api=" . wp_json_encode(ezbizi_api_base()) . ",key=" . wp_json_encode($key) . ";
var panel=root.querySelector('.ezbizi-chat-panel'),log=root.querySelector('.ezbizi-chat-log');
var pre=root.querySelector('.ezbizi-chat-pre'),thread=root.querySelector('.ezbizi-chat-thread');
var err=root.querySelector('.ezbizi-chat-error');
var form=root.querySelector('.ezbizi-chat-form'),box=root.querySelector('.ezbizi-chat-text');
var opener=root.querySelector('.ezbizi-chat-open');
var store=window.sessionStorage,chat=null,token=null,timer=null;
try{chat=store.getItem('ezbizi_chat');token=store.getItem('ezbizi_chat_token');}catch(e){}
function started(){
  // Asked once per visit. Somebody who has already said who they are is not
  // asked again when they reopen the panel.
  pre.hidden=true;thread.hidden=false;
}
if(chat&&token)started();
function draw(ms){
  log.innerHTML='';
  ms.forEach(function(m){
    var row=document.createElement('div');
    row.className='ezbizi-msg ezbizi-msg-'+((m.from==='you')?'you':'them');
    // textContent, never innerHTML: this is a stranger's typing coming back.
    row.textContent=m.text||'';
    log.appendChild(row);
  });
  log.scrollTop=log.scrollHeight;
}
function poll(){
  if(!chat||!token)return;
  fetch(api+'/api/v1/web/chat/'+chat+'/messages',{headers:{'Authorization':'Bearer '+token}})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){if(d&&d.messages)draw(d.messages);}).catch(function(){});
}
function ticking(on){
  // Only while the panel is open. A closed widget polling on every page of
  // every visitor's session is a request per visitor every few seconds, for
  // something nobody is looking at.
  if(on){if(!timer)timer=setInterval(poll,5000);}
  else if(timer){clearInterval(timer);timer=null;}
}
function open(yes){
  if(yes&&panel.hidden)tell('open');
  panel.hidden=!yes;
  opener.setAttribute('aria-expanded',yes?'true':'false');
  if(yes){poll();ticking(true);var f=pre.hidden?box:pre.querySelector('input');if(f)f.focus();}
  else ticking(false);
}
opener.addEventListener('click',function(){open(panel.hidden);});
root.querySelector('.ezbizi-chat-close').addEventListener('click',function(){open(false);});
function keep(d){
  if(!d||!d.chat||!d.token)return false;
  chat=d.chat;token=d.token;
  try{store.setItem('ezbizi_chat',chat);store.setItem('ezbizi_chat_token',token);}catch(e){}
  return true;
}
pre.addEventListener('submit',function(e){
  e.preventDefault();
  var name=pre.name.value.trim(),contact=pre.contact.value.trim(),text=pre.message.value.trim();
  if(!name||!contact||!text)return;
  err.hidden=true;
  var body={key:key,message:text,name:name,page_url:location.href};
  var c=pre.querySelector('.ezbizi-consent-box');if(c&&c.checked){body.marketing_consent=true;body.marketing_consent_text=c.parentNode.textContent.trim();}
  // One field, because asking for both is asking twice. An address has an @
  // in it and a phone number does not.
  if(contact.indexOf('@')>-1)body.email=contact;else body.phone=contact;
  fetch(api+'/api/v1/web/chat',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){
      if(!keep(d)){err.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'Sorry — that did not send. Please try again.')) . ";err.hidden=false;return;}
      started();tell('start');
      if(d.messages)draw(d.messages);
      ticking(true);
      box.focus();
    }).catch(function(){err.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'Sorry — that did not send. Please try again.')) . ";err.hidden=false;});
});
form.addEventListener('submit',function(e){
  e.preventDefault();
  var text=box.value.trim();
  if(!text||!chat||!token)return;
  box.value='';
  fetch(api+'/api/v1/web/chat/'+chat+'/messages',{
    method:'POST',
    headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+token},
    body:JSON.stringify({message:text})
  }).then(function(r){return r.ok?r.json():null;})
    .then(function(d){if(d&&d.messages)draw(d.messages);}).catch(function(){});
});
})();</script>";

    return $out;
}

/** The launcher's icon, inline so the widget never waits on an icon font. */
function ezbizi_chat_icon($which = 'chat') {
    $paths = array(
        'chat' => 'M12 3C6.98 3 3 6.36 3 10.5c0 2.3 1.24 4.35 3.2 5.71-.13 1.2-.6 2.3-1.36 3.2'
            . '-.22.26-.03.66.31.63 1.94-.15 3.5-.87 4.6-1.66.72.14 1.47.22 2.25.22 5.02 0 9-3.36 '
            . '9-7.6S17.02 3 12 3z',
        'message' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm0 3.2V18h16V7.2l-8 5-8-5zM5.2 6 12 10.3 18.8 6H5.2z',
        'phone' => 'M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z',
        'help' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 17h-2v-2h2v2zm2.1-7.7-.9.9c-.7.8-1.2 1.4-1.2 2.8h-2v-.5c0-1.1.5-2.1 1.2-2.8l1.2-1.3a2 2 0 1 0-3.4-1.4H8a4 4 0 1 1 7.1 2.3z',
    );
    $d = isset($paths[$which]) ? $paths[$which] : $paths['chat'];

    return '<svg class="ezbizi-chat-icon" width="18" height="18" viewBox="0 0 24 24" '
        . 'fill="currentColor" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
}

/**
 * The chat's own look, written defensively.
 *
 * ── Why every property is stated ──
 *
 * The first version set almost nothing and let the page's own stylesheet
 * decide. On a-1chimney.com that produced what Jose photographed: the theme's
 * orange button styling on the launcher, a near-black text input nobody could
 * read, and a Send button sitting where the theme's grid put it. A widget
 * dropped into somebody else's stylesheet has to say what it wants — all of
 * it, including the things that look like they have obvious defaults.
 *
 * `box-sizing`, `line-height`, `margin` and `background` on every element are
 * the four a theme almost always sets, so they are the four that move a widget
 * that never asked to be moved.
 */
function ezbizi_chat_style($id, $style, $position, $config = array()) {
    $accent = esc_attr(ezbizi_widget_text($style, 'accent', '#0b5cad'));
    $on_accent = esc_attr(ezbizi_widget_text($style, 'on_accent', '#ffffff'));
    $text = esc_attr(ezbizi_widget_text($style, 'text', '#1b1b1b'));
    $background = esc_attr(ezbizi_widget_text($style, 'background', '#ffffff'));
    $radius = (int) ezbizi_widget_text($style, 'radius', '12');
    $s = '#' . esc_attr($id);

    // Toula's colours, each falling back to the site's accent pair when unset.
    $hex = function ($key, $fallback) use ($config) {
        $v = ezbizi_widget_text($config, $key);

        return preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $v) ? $v : $fallback;
    };
    $bubble_bg = $hex('bubble_bg', $accent);
    $bubble_color = $hex('bubble_color', $on_accent);
    $head_bg = $hex('header_bg', $accent);
    $head_color = $hex('header_color', $on_accent);
    $you_bg = $hex('visitor_bg', $accent);
    $them_bg = $hex('business_bg', '#f1f3f5');
    $shadow = (! isset($config['shadow']) || ! empty($config['shadow']));
    $size = max(36, min(96, (int) ezbizi_widget_text($config, 'bubble_size', '48')));
    $devices = ezbizi_widget_text($config, 'devices', 'all');
    $motion = ezbizi_widget_text($config, 'animation', 'slide');

    // Opens from the corner it sits in, so the panel never runs off the screen.
    $side = ($position === 'bottom-left') ? 'left:0' : 'right:0';

    return '<style>'
        . $s . ',' . $s . ' *{box-sizing:border-box}'
        . $s . '{line-height:1.45;text-align:left}'

        . $s . ' .ezbizi-chat-open{display:inline-flex;align-items:center;gap:8px;'
        . 'background:' . $bubble_bg . ';color:' . $bubble_color . ';border:0;border-radius:999px;'
        . 'padding:0 18px;font:inherit;font-weight:600;line-height:1;cursor:pointer;'
        . 'box-shadow:' . ($shadow ? '0 6px 20px rgba(0,0,0,.18)' : 'none') . ';margin:0;width:auto;text-transform:none;'
        . 'letter-spacing:normal;min-height:' . $size . 'px;height:' . $size . 'px}'
        . $s . ' .ezbizi-chat-open:hover{filter:brightness(.94)}'
        . $s . ' .ezbizi-chat-icon{flex:0 0 auto}'

        . $s . ' .ezbizi-chat-panel{position:absolute;bottom:calc(100% + 10px);' . $side . ';'
        . 'width:340px;max-width:calc(100vw - 40px);background:' . $background . ';color:' . $text . ';'
        . 'border-radius:' . max(8, $radius) . 'px;overflow:hidden;'
        . 'box-shadow:' . ($shadow ? '0 18px 50px rgba(0,0,0,.24)' : 'none') . ';border:1px solid rgba(0,0,0,.08)}'

        . $s . ' .ezbizi-chat-head{display:flex;align-items:flex-start;gap:10px;'
        . 'background:' . $head_bg . ';color:' . $head_color . ';padding:14px 16px}'
        . $s . ' .ezbizi-chat-head-text{flex:1 1 auto;min-width:0}'
        . $s . ' .ezbizi-chat-head strong{display:block;font-size:15px;line-height:1.3;color:' . $head_color . '}'
        . $s . ' .ezbizi-chat-head span{display:block;font-size:13px;opacity:.9;color:' . $head_color . '}'
        . $s . ' .ezbizi-chat-avatar{flex:0 0 auto;width:36px;height:36px;border-radius:50%;object-fit:cover;margin:0}'
        . $s . ' .ezbizi-chat-status i{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2e9d4f;margin:0 6px 0 0}'
        . $s . ' .ezbizi-chat-close{flex:0 0 auto;background:rgba(255,255,255,.22);color:' . $head_color . ';'
        . 'border:0;border-radius:999px;width:26px;height:26px;min-height:0;padding:0;margin:0;'
        . 'font:inherit;font-size:17px;line-height:26px;cursor:pointer;box-shadow:none}'

        . $s . ' .ezbizi-chat-body{padding:16px}'
        . $s . ' .ezbizi-chat-leadin{margin:0 0 12px;font-size:13px;color:' . $text . ';opacity:.85}'
        . $s . ' .ezbizi-chat-pre label{display:block;margin:0 0 10px;font-size:13px;font-weight:600;color:' . $text . '}'
        . $s . ' .ezbizi-chat-req{color:' . $accent . '}'
        . $s . ' .ezbizi-chat-pre input,' . $s . ' .ezbizi-chat-pre textarea,' . $s . ' .ezbizi-chat-text{'
        . 'display:block;width:100%;margin:4px 0 0;padding:9px 10px;background:#fff;color:#1b1b1b;'
        . 'border:1px solid #c8ccd2;border-radius:' . max(4, $radius - 6) . 'px;font:inherit;'
        . 'font-weight:400;line-height:1.4;box-shadow:none;min-height:0}'
        . $s . ' .ezbizi-chat-pre textarea{resize:vertical}'
        . $s . ' .ezbizi-chat-pre .ezbizi-consent label{font-weight:400}'
        . $s . ' .ezbizi-chat-pre .ezbizi-consent-box{display:inline;width:auto;margin:0 6px 0 0;padding:0}'
        . $s . ' .ezbizi-chat-pre input:focus,' . $s . ' .ezbizi-chat-pre textarea:focus,'
        . $s . ' .ezbizi-chat-text:focus{outline:2px solid ' . $accent . ';outline-offset:1px}'
        . $s . ' .ezbizi-chat-start{display:block;width:100%;margin:4px 0 0;background:' . $accent . ';'
        . 'color:' . $on_accent . ';border:0;border-radius:' . max(4, $radius - 4) . 'px;padding:11px 14px;'
        . 'font:inherit;font-weight:700;line-height:1.2;cursor:pointer;text-transform:none;min-height:0}'
        . $s . ' .ezbizi-chat-error{margin:10px 0 0;font-size:13px;color:#b3261e}'

        . $s . ' .ezbizi-chat-log{max-height:280px;overflow:auto;margin:0 0 10px}'
        . $s . ' .ezbizi-msg{margin:0 0 6px;padding:8px 11px;border-radius:14px;max-width:85%;'
        . 'font-size:14px;word-wrap:break-word}'
        . $s . ' .ezbizi-msg-you{margin-left:auto;background:' . $you_bg . ';color:' . $on_accent . '}'
        . $s . ' .ezbizi-msg-them{margin-right:auto;background:' . $them_bg . ';color:#1b1b1b}'
        . $s . ' .ezbizi-chat-form{display:flex;gap:8px;align-items:center}'
        . $s . ' .ezbizi-chat-form button{flex:0 0 auto;background:' . $accent . ';color:' . $on_accent . ';'
        . 'border:0;border-radius:' . max(4, $radius - 4) . 'px;padding:10px 14px;font:inherit;'
        . 'font-weight:600;line-height:1.2;cursor:pointer;margin:0;text-transform:none;min-height:0}'
        . ($motion === 'none' ? '' : '@keyframes ezbizi-chat-' . $motion . '{from{opacity:0' . ($motion === 'slide' ? ';transform:translateY(12px)' : '') . '}to{opacity:1;transform:none}}'
            . $s . ' .ezbizi-chat-panel:not([hidden]),' . $s . ' .ezbizi-chat-open{animation:ezbizi-chat-' . $motion . ' .25s ease-out}'
            . '@media (prefers-reduced-motion:reduce){' . $s . ' .ezbizi-chat-panel,' . $s . ' .ezbizi-chat-open{animation:none}}')
        . ($devices === 'desktop' ? '@media (max-width:767px){' . $s . '{display:none!important}}' : '')
        . ($devices === 'mobile' ? '@media (min-width:768px){' . $s . '{display:none!important}}' : '')
        . '</style>';
}

add_action('init', function () {
    add_shortcode('ezbizi_form', 'ezbizi_form_shortcode');
    add_shortcode('ezbizi_chat', 'ezbizi_chat_shortcode');
});

/**
 * The chat bubble, on every page, because that is what a bubble is.
 *
 * ── Why the footer and not placement ──
 *
 * The bubble draws itself `position:fixed; bottom:20px; right:20px` — it floats
 * over the page rather than sitting in it. A thing that floats over every page
 * but has to be pasted onto each one individually appears on the pages somebody
 * remembered and nowhere else, which is indistinguishable from broken. The
 * deploy desk asked whether chat was meant to be site-wide, having found it on
 * no page at all after the switches came off; `position:fixed` and the separate
 * `inline` option are the answer, and they were in the design from the start.
 *
 * The contact form is NOT here on purpose. A form belongs in the page it
 * belongs in — under the services it asks about, at the end of the article —
 * and there is no sensible place a footer could put it for everybody.
 *
 * ── The three ways this declines to draw ──
 *
 * `inline` means the site has said where it goes, so the shortcode or the
 * Elementor widget places it and the footer keeps out. A page that already drew
 * one does not get a second. And an unpaired site has no key, so the shortcode
 * returns nothing and this costs a function call.
 */
add_action('wp_footer', function () {
    if (! empty($GLOBALS['ezbizi_chat_drawn'])) {
        return;
    }

    $config = ezbizi_widget_config('chat');

    if (empty($config)) {
        return;
    }

    if (ezbizi_widget_text($config, 'position', 'bottom-right') === 'inline') {
        return;
    }

    // Escaped by the shortcode, which is the only thing that builds this markup.
    echo ezbizi_chat_shortcode(array());
}, 20);
