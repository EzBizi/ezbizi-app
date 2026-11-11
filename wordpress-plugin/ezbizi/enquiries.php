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

/** This site's Cloudflare Turnstile widget, `{site, secret}`, or null when EzBizi has none for its domain. */
function ezbizi_turnstile() {
    $payload = ezbizi_payload();
    $t = isset($payload['turnstile']) && is_array($payload['turnstile']) ? $payload['turnstile'] : array();

    return isset($t['site'], $t['secret']) && is_string($t['site']) && $t['site'] !== '' ? $t : null;
}

/** Cloudflare's script, once per page. */
function ezbizi_turnstile_script() {
    static $done = false;

    if ($done) {
        return '';
    }

    $done = true;

    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

/**
 * The "verify you are human" box (Jose, 2026-10-08: "an actual captcha").
 * Turnstile puts the visitor's token in a hidden `cf-turnstile-response`
 * inside it; ezTs() reads that, the server checks it (Turnstile.php).
 */
function ezbizi_turnstile_box() {
    $t = ezbizi_turnstile();

    return $t === null ? '' : '<div class="cf-turnstile" data-sitekey="' . esc_attr($t['site']) . '" data-size="flexible" style="margin:0 0 12px"></div>'
        . ezbizi_turnstile_script();
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

    // Every form asks for the ZIP (Jose, 2026-10-08), so a lead can be checked against availability.
    $asks_zip = false;

    foreach ($fields as $field) {
        $asks_zip = $asks_zip || (is_array($field) && preg_match('/zip|postal/i', ezbizi_widget_text($field, 'label')));
    }

    if (! $asks_zip) {
        $fields[] = array('label' => __('ZIP code', 'ezbizi'), 'type' => 'text', 'required' => true, 'width' => 100);
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

    $out .= ezbizi_turnstile_box();
    $out .= '<p><button type="submit" class="ezbizi-form-submit">' . esc_html(ezbizi_widget_text($config, 'button', 'Send')) . '</button></p>';
    $out .= '<p class="ezbizi-form-note" role="status" aria-live="polite"></p>';
    $out .= '</form>';

    $redirect = ezbizi_widget_text($config, 'redirect');
    $redirect = preg_match('~^https?://~i', $redirect) ? esc_url_raw($redirect) : '';

    $out .= ezbizi_late_script("<script>(function(){
var f=document.getElementById(" . wp_json_encode($id) . ");
if(!f)return;
var note=f.querySelector('.ezbizi-form-note');
// Always on since 2026-10-06 (Jose: \"Activate captcha on all websites\"); the switch is gone.
var api=" . wp_json_encode(ezbizi_api_base()) . ",captcha=true;
function answer(n){var out=[];Array.prototype.forEach.call(f.querySelectorAll('[name]'),function(i){
  if(i.name!==n||i.disabled)return;if((i.type==='checkbox'||i.type==='radio')&&!i.checked)return;if(i.value)out.push(i.value);});return out.join(', ');}
function sync(){Array.prototype.forEach.call(f.querySelectorAll('[data-if-field]'),function(w){
  var a=answer(w.getAttribute('data-if-field')).toLowerCase(),want=w.getAttribute('data-if-value').toLowerCase();
  var on=want===''?a!=='':a.split(', ').indexOf(want)>=0;w.style.display=on?'':'none';
  Array.prototype.forEach.call(w.querySelectorAll('input,textarea,select'),function(i){i.disabled=!on;});});}
f.addEventListener('change',sync);f.addEventListener('input',sync);sync();
" . ezbizi_solver_js() . "
f.addEventListener('submit',function(e){
  e.preventDefault();
  var bot=f.querySelector('.ezbizi-antibot-box');
  if(bot&&!bot.checked){note.textContent=" . wp_json_encode(__('Please tick the box to show you are not a robot.', 'ezbizi')) . ";return;}
  if(!f.checkValidity()){f.reportValidity();return;}
  var ts=ezTs(f);if(ts===''){note.textContent=" . wp_json_encode(__('Please complete the check above the button.', 'ezbizi')) . ";return;}
  var b=f.querySelector('button');b.disabled=true;
  var fields={},files={},v={},first='',last='';
  Array.prototype.forEach.call(f.querySelectorAll('input,textarea,select'),function(i){
    if(i.disabled||i.classList.contains('ezbizi-consent-box')||i.classList.contains('ezbizi-antibot-box')||i.closest('.cf-turnstile'))return;
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
    if(/zip|postal/.test(low)){var z=val.match(/[0-9]{5}/);if(z)v.zip=z[0];}
    fields[n]=val;
  });
  if(!v.name&&(first||last))v.name=(first+' '+last).trim();
  v.key=" . wp_json_encode($key) . ";v.fields=fields;v.page_url=location.href;
  var c=f.querySelector('.ezbizi-consent-box');if(c&&c.checked){v.marketing_consent=true;v.marketing_consent_text=c.parentNode.textContent.trim();}
  if(ts)v.turnstile=ts;
  (captcha?ezSolve(api).catch(function(){return null;}):Promise.resolve(null)).then(function(sol){
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
    ezTsReset(f);
    if(!r.ok)throw new Error(r.status);
    f.reset();sync();note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'thanks', 'Thank you.')) . ";
    " . ($redirect !== '' ? 'location.href=' . wp_json_encode($redirect) . ';' : '') . "
  }).catch(function(){
    note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'That did not send.')) . ";
  }).then(function(){b.disabled=false;});
});
})();</script>");

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
    $message_label = ezbizi_widget_text($config, 'message_label', 'How can we help?');

    /*
     * Design A, "Team card" (Jose chose it 2026-10-01 from three): the visitor
     * sees WHO answers — the team's faces until somebody replies, then that
     * person — and "Lenin is typing…", in a window that opens at its full
     * size and never changes it.
     */
    $team = isset($config['team']) && is_array($config['team']) ? array_slice($config['team'], 0, 3) : array();
    $company = ezbizi_widget_text($config, 'company');
    $who = $company !== '' ? $company : $title;
    $online = ezbizi_widget_text($config, 'online_text', 'Our team is online');
    $faces = '';

    foreach ($team as $person) {
        $faces .= ezbizi_chat_face(is_array($person) ? $person : array());
    }

    /*
     * Toula's own "agent name" and photo, when somebody set them, lead the
     * header and the button in place of the team; the company moves under.
     */
    if ($agent !== '') {
        $agent_face = array('initials' => ezbizi_chat_initials($agent), 'avatar' => preg_match('~^https?://~i', $avatar) ? $avatar : '');
        $faces = ezbizi_chat_face($agent_face);
        $team = array($agent_face);
        $online = ($company !== '' ? $company . ' · ' : '') . $online;
        $who = $agent;
    }

    if ($faces === '') {
        $faces = ezbizi_chat_face(array('initials' => ezbizi_chat_initials($who)));
    }

    $first = $team !== array() ? ezbizi_chat_face($team[0], 'ezbizi-face-launch') : '';

    $out = ezbizi_widget_style($id, $config['style']);
    $out .= ezbizi_chat_style($id, $config['style'], $position, $config);

    $out .= '<div class="ezbizi-chat" id="' . esc_attr($id) . '" style="' . esc_attr($anchor) . '">'

        . '<div class="ezbizi-chat-panel" hidden role="dialog" aria-label="' . esc_attr($title) . '">'

        . '<div class="ezbizi-chat-head">'
        . '<div class="ezbizi-chat-faces">' . $faces . '</div>'
        . '<div class="ezbizi-chat-head-text">'
        . '<strong class="ezbizi-chat-who">' . esc_html($who) . '</strong>'
        . '<span class="ezbizi-chat-sub"><i class="ezbizi-chat-dot"></i><span class="ezbizi-chat-sub-text">' . esc_html($online) . '</span></span>'
        . '</div>'
        . '<button type="button" class="ezbizi-chat-close" aria-label="Close">&times;</button>'
        . '</div>'

        . '<div class="ezbizi-chat-body">'

        /*
         * Asked before the conversation, not after it: a chat that opens
         * straight into a message box produces a Lead nobody can call back.
         */
        . '<form class="ezbizi-chat-pre">'
        . '<p class="ezbizi-chat-leadin">' . esc_html($lead_in !== '' ? $lead_in : ($intro !== '' ? $intro : 'Hi! Leave your details so we can reach you if the chat drops.')) . '</p>'
        . '<label>' . esc_html($name_label) . ' <span class="ezbizi-chat-req">*</span>'
        . '<input type="text" name="name" autocomplete="name" required /></label>'
        /*
         * Phone, email and ZIP, each its own box (Jose, 2026-10-01): a chat
         * lead arrives in Leads ready to call, email and place in a service
         * area, the way a Google lead does. One "phone or email" box gave one
         * of the two and never the ZIP.
         */
        . '<div class="ezbizi-chat-pair">'
        . '<label>Phone <span class="ezbizi-chat-req">*</span>'
        . '<input type="tel" name="phone" autocomplete="tel" required /></label>'
        . '<label>ZIP code <span class="ezbizi-chat-req">*</span>'
        . '<input type="text" name="zip" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" required /></label>'
        . '</div>'
        . '<label>Email <span class="ezbizi-chat-req">*</span>'
        . '<input type="email" name="email" autocomplete="email" required /></label>'
        . '<label>' . esc_html($message_label) . ' <span class="ezbizi-chat-req">*</span>'
        . '<textarea name="message" rows="3" required placeholder="'
        . esc_attr(ezbizi_widget_text($config, 'placeholder', 'Type a message')) . '"></textarea></label>'
        . '<p class="ezbizi-consent"><label><input type="checkbox" class="ezbizi-consent-box" /> <span>'
        . esc_html(ezbizi_widget_text($config, 'consent', 'Yes, email me news and offers. I can unsubscribe at any time.'))
        . '</span></label></p>'
        . ezbizi_turnstile_box()
        . '<button type="submit" class="ezbizi-chat-start">'
        . esc_html(ezbizi_widget_text($config, 'start', 'Start chat')) . '</button>'
        . '<p class="ezbizi-chat-error" hidden role="alert"></p>'
        . '</form>'

        . '<div class="ezbizi-chat-log" hidden role="log" aria-live="polite"></div>'
        . '</div>'

        . '<form class="ezbizi-chat-form" hidden>'
        . '<label class="ezbizi-chat-attach" title="Send a photo"><input type="file" accept="image/*" hidden />'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.5 6v11.5a4 4 0 1 1-8 0V5a2.5 2.5 0 0 1 5 0v10.5a1 1 0 1 1-2 0V6H10v9.5a2.5 2.5 0 0 0 5 0V5a4 4 0 1 0-8 0v12.5a5.5 5.5 0 0 0 11 0V6h-1.5z"/></svg>'
        . '<span class="ezbizi-sr">Send a photo</span></label>'
        . '<input type="text" class="ezbizi-chat-text" aria-label="' . esc_attr($message_label) . '" placeholder="'
        . esc_attr(ezbizi_widget_text($config, 'placeholder', 'Type a message')) . '" />'
        . '<button type="submit" class="ezbizi-chat-send" aria-label="' . esc_attr(ezbizi_widget_text($config, 'send', 'Send')) . '">'
        . '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>'
        . '</form>'

        // Jose, 2026-10-01: "a Powered by EzBizi you know to start promoting the app".
        . '<a class="ezbizi-chat-powered" href="https://www.ezbizi.com/?utm_source=chat&amp;utm_medium=widget&amp;utm_campaign=powered_by" target="_blank" rel="noopener">Powered by <b>EzBizi</b></a>'
        . '</div>'

        . '<button type="button" class="ezbizi-chat-open" aria-expanded="false">'
        . ($first !== '' ? $first : ezbizi_chat_icon(ezbizi_widget_text($config, 'bubble_icon', 'chat')))
        . '<span class="ezbizi-chat-launch-text"><b>' . esc_html(ezbizi_widget_text($config, 'button', 'Chat with us')) . '</b>'
        . '<small>We reply in minutes</small></span>'
        . '<span class="ezbizi-chat-launch-x" aria-hidden="true">&times;</span>'
        . '</button>'

        . '</div>';

    /*
     * ── The opening-hours gate, and why it is JAVASCRIPT ──
     *
     * Jose, 2026-09-17: the bubble shows during working hours and not outside
     * them. PHP cannot decide it: every page sits in a page cache, so the
     * answer would be baked into the HTML for hours. The browser decides, in
     * the BUSINESS's zone, and fails open (any doubt and the bubble shows).
     */
    $out .= ezbizi_late_script("<script>(function(){
var root=document.getElementById(" . wp_json_encode($id) . ");
if(!root)return;
var openHours=" . wp_json_encode(isset($config['hours']) && is_array($config['hours']) ? $config['hours'] : array()) . ";
var openZone=" . wp_json_encode(ezbizi_widget_text($config, 'timezone')) . ";
function mins(hhmm){var m=/^([0-9]{2}):([0-9]{2})$/.exec(String(hhmm||''));return m?parseInt(m[1],10)*60+parseInt(m[2],10):null;}
function openNow(){
  if(!openHours||openHours.length!==7||!openZone)return true;
  try{
    var parts=new Intl.DateTimeFormat('en-GB',{timeZone:openZone,weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false}).formatToParts(new Date());
    var got={};for(var i=0;i<parts.length;i++)got[parts[i].type]=parts[i].value;
    var day=openHours[{Mon:0,Tue:1,Wed:2,Thu:3,Fri:4,Sat:5,Sun:6}[got.weekday]];
    if(!day)return true;if(day.closed)return false;
    var now=(parseInt(got.hour,10)%24)*60+parseInt(got.minute,10),from=mins(day.open),till=mins(day.close);
    if(from===null||till===null||isNaN(now))return true;
    return till>from?(now>=from&&now<till):(now>=from||now<till);
  }catch(e){return true;}
}
if(!openNow()){if(root.parentNode)root.parentNode.removeChild(root);return;}
var delay=" . (int) $delay . ";
if(delay)setTimeout(function(){root.style.display='';},delay*1000);
var tell=" . (empty($config['analytics']) ? 'function(){}' : "function(e){try{(window.dataLayer=window.dataLayer||[]).push({event:'ezbizi_chat_'+e});if(window.gtag)gtag('event','ezbizi_chat_'+e);}catch(x){}}") . ";
var api=" . wp_json_encode(ezbizi_api_base()) . ",key=" . wp_json_encode($key) . ",company=" . wp_json_encode($company !== '' ? $company : $title) . ";
var failed=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'Sorry — that did not send. Please try again.')) . ";
var q=function(s){return root.querySelector(s);};
var panel=q('.ezbizi-chat-panel'),log=q('.ezbizi-chat-log'),pre=q('.ezbizi-chat-pre'),err=q('.ezbizi-chat-error');
var form=q('.ezbizi-chat-form'),box=q('.ezbizi-chat-text'),photo=q('.ezbizi-chat-attach input'),opener=q('.ezbizi-chat-open');
var faces=q('.ezbizi-chat-faces'),whoEl=q('.ezbizi-chat-who'),subEl=q('.ezbizi-chat-sub-text');
" . ezbizi_solver_js() . "
var store=window.sessionStorage,chat=null,token=null,timer=null,last='',typedAt=0;
try{chat=store.getItem('ezbizi_chat');token=store.getItem('ezbizi_chat_token');}catch(e){}
function started(){pre.hidden=true;log.hidden=false;form.hidden=false;}
if(chat&&token)started();
function initials(s){return String(s||'?').split(/\s+/).slice(0,2).map(function(w){return w.charAt(0);}).join('').toUpperCase()||'?';}
function face(p){
  var f=document.createElement('span');f.className='ezbizi-face';
  f.textContent=(p&&p.initials)||initials(p&&p.name);
  if(p&&p.avatar){var i=document.createElement('img');i.src=p.avatar;i.alt='';i.onerror=function(){i.remove();};f.appendChild(i);}
  return f;
}
function clock(iso){try{return new Date(iso).toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}catch(e){return '';}}
function bubble(cls,text){var b=document.createElement('div');b.className='ezbizi-msg '+cls;if(text)b.appendChild(document.createTextNode(text));return b;}
function draw(d){
  var sig=JSON.stringify([d.messages,d.typing&&d.typing.name,d.agent&&d.agent.name]);
  if(sig===last)return;
  // The body scrolls, not the list. Follow new messages unless they scrolled up to read.
  var sc=q('.ezbizi-chat-body'),near=last===''||sc.scrollHeight-sc.scrollTop-sc.clientHeight<80;last=sig;
  log.innerHTML='';
  var seen={};
  (d.messages||[]).forEach(function(m){
    var mine=m.from==='you',by=m.by;
    if(by&&!seen[by.name]){seen[by.name]=1;var s=document.createElement('div');s.className='ezbizi-sys';s.textContent=by.name+' joined the chat';log.appendChild(s);}
    var row=document.createElement('div');row.className='ezbizi-row '+(mine?'ezbizi-row-you':'ezbizi-row-them');
    if(!mine)row.appendChild(face(by||{initials:initials(company)}));
    // textContent, never innerHTML: these are strangers' words.
    var b=bubble(mine?'ezbizi-msg-you':'ezbizi-msg-them',m.text||'');
    (m.attachments||[]).forEach(function(u){var a=document.createElement('a');a.href=u;a.target='_blank';a.rel='noopener';var im=document.createElement('img');im.src=u;im.alt='Photo';a.appendChild(im);b.appendChild(a);});
    row.appendChild(b);log.appendChild(row);
    var meta=document.createElement('div');meta.className='ezbizi-meta'+(mine?' ezbizi-meta-you':'');
    meta.textContent=(mine?'':((by&&by.name)||company)+' · ')+clock(m.at);log.appendChild(meta);
  });
  if(d.typing){
    var tr=document.createElement('div');tr.className='ezbizi-row ezbizi-row-them';tr.appendChild(face(d.typing));
    var dots=bubble('ezbizi-msg-them ezbizi-typing','');dots.innerHTML='<i></i><i></i><i></i>';tr.appendChild(dots);log.appendChild(tr);
    var tm=document.createElement('div');tm.className='ezbizi-meta';tm.textContent=d.typing.name+' is typing…';log.appendChild(tm);
  }
  // The header becomes the person once somebody answers.
  if(d.agent){faces.innerHTML='';faces.appendChild(face(d.agent));whoEl.textContent=d.agent.name;subEl.textContent=company+' · Online';}
  if(near)sc.scrollTop=sc.scrollHeight;
}
function poll(){
  if(!chat||!token)return;
  fetch(api+'/api/v1/web/chat/'+chat+'/messages',{headers:{'Authorization':'Bearer '+token}})
    .then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.messages)draw(d);}).catch(function(){});
}
// Only while open: a closed widget polling on every page is a request per visitor for nothing.
function ticking(on){if(on){if(!timer)timer=setInterval(poll,3000);}else if(timer){clearInterval(timer);timer=null;}}
function open(yes){
  if(yes&&panel.hidden)tell('open');
  panel.hidden=!yes;root.classList.toggle('ezbizi-chat-is-open',yes);
  opener.setAttribute('aria-expanded',yes?'true':'false');
  if(yes){poll();ticking(true);var f=pre.hidden?box:pre.querySelector('input');if(f)f.focus();}else ticking(false);
}
opener.addEventListener('click',function(){open(panel.hidden);});
q('.ezbizi-chat-close').addEventListener('click',function(){open(false);});
function keep(d){
  if(!d||!d.chat||!d.token)return false;chat=d.chat;token=d.token;
  try{store.setItem('ezbizi_chat',chat);store.setItem('ezbizi_chat_token',token);}catch(e){}
  return true;
}
pre.addEventListener('submit',function(e){
  e.preventDefault();
  if(!pre.checkValidity()){pre.reportValidity();return;}
  var text=pre.message.value.trim();
  err.hidden=true;
  var body={key:key,message:text,name:pre.name.value.trim(),phone:pre.phone.value.trim(),email:pre.email.value.trim(),zip:pre.zip.value.trim(),page_url:location.href};
  var c=pre.querySelector('.ezbizi-consent-box');if(c&&c.checked){body.marketing_consent=true;body.marketing_consent_text=c.parentNode.textContent.trim();}
  var ts=ezTs(pre);if(ts===''){err.textContent=" . wp_json_encode(__('Please complete the check above the button.', 'ezbizi')) . ";err.hidden=false;return;}if(ts)body.turnstile=ts;
  // The same robot check as the forms; unsolvable here, it sends anyway and the server answers.
  ezSolve(api).catch(function(){return null;}).then(function(sol){if(sol)body.captcha=sol;
  return fetch(api+'/api/v1/web/chat',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});})
    .then(function(r){ezTsReset(pre);return r.ok?r.json():null;})
    .then(function(d){
      if(!keep(d)){err.textContent=failed;err.hidden=false;return;}
      started();tell('start');draw(d);ticking(true);box.focus();
    }).catch(function(){err.textContent=failed;err.hidden=false;});
});
function send(body,json){
  var h={'Accept':'application/json','Authorization':'Bearer '+token};if(json)h['Content-Type']='application/json';
  return fetch(api+'/api/v1/web/chat/'+chat+'/messages',{method:'POST',headers:h,body:body})
    .then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.messages)draw(d);}).catch(function(){});
}
form.addEventListener('submit',function(e){
  e.preventDefault();var text=box.value.trim();if(!text||!chat||!token)return;
  box.value='';send(JSON.stringify({message:text}),true);
});
// \"Joseph is typing…\" in Leads: one signal every few seconds while keys go down.
box.addEventListener('input',function(){
  if(!chat||!token||Date.now()-typedAt<3000)return;typedAt=Date.now();
  fetch(api+'/api/v1/web/chat/'+chat+'/typing',{method:'POST',headers:{'Authorization':'Bearer '+token}}).catch(function(){});
});
photo.addEventListener('change',function(){
  if(!photo.files||!photo.files[0]||!chat||!token)return;
  var fd=new FormData();fd.append('photo',photo.files[0]);photo.value='';send(fd,false);
});
})();</script>");

    return $out;
}

/** A person's round face: their photo over their initials, so a photo that fails still shows something. */
function ezbizi_chat_face($person, $class = '') {
    $initials = isset($person['initials']) ? (string) $person['initials'] : '?';
    $avatar = isset($person['avatar']) ? esc_url((string) $person['avatar']) : '';

    return '<span class="ezbizi-face' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">' . esc_html($initials)
        . ($avatar !== '' ? '<img src="' . $avatar . '" alt="" onerror="this.remove()" />' : '') . '</span>';
}

function ezbizi_chat_initials($name) {
    $out = '';

    foreach (array_slice(preg_split('/\s+/', trim((string) $name)), 0, 2) as $word) {
        $out .= strtoupper(mb_substr($word, 0, 1));
    }

    return $out !== '' ? $out : '?';
}

/**
 * The robot check's browser half, for every EzBizi form on the page: the form,
 * the chat's start and the review faces' sad form. A proof of work EzBizi
 * signs and checks (FormChallenge): about a second of the visitor's browser,
 * nothing to click, and no Google or Cloudflare script on the page.
 *
 * Returns JS defining `ezSolve(api)`, a Promise of the solved answer.
 */
function ezbizi_solver_js() {
    return "function ezHex(b){return Array.prototype.map.call(new Uint8Array(b),function(x){return('0'+x.toString(16)).slice(-2);}).join('');}
function ezTs(f){var b=f.querySelector('.cf-turnstile');if(!b)return null;var i=b.querySelector('[name=cf-turnstile-response]');return i?i.value:'';}
function ezTsReset(f){var b=f.querySelector('.cf-turnstile');if(b&&window.turnstile)try{turnstile.reset(b);}catch(e){}}
function ezSolve(api){return fetch(api+'/api/v1/web/form/challenge').then(function(r){return r.json();}).then(function(c){
  var enc=new TextEncoder(),n=0;
  function step(){var batch=[];for(var k=0;k<500&&n+k<=c.max;k++)batch.push(n+k);
    return Promise.all(batch.map(function(x){return crypto.subtle.digest('SHA-256',enc.encode(c.salt+x)).then(function(h){return ezHex(h)===c.challenge?x:-1;});}))
    .then(function(rs){var hit=rs.filter(function(x){return x>=0;});
      if(hit.length)return JSON.stringify({salt:c.salt,number:hit[0],challenge:c.challenge,signature:c.signature});
      n+=500;if(n>c.max)throw new Error('captcha');return step();});}
  return step();});}";
}

/**
 * A widget's script, printed in the footer instead of inside the shortcode's
 * HTML. Jose, 2026-10-15: on a-1chimney.com/booking the page's content filters
 * turned the script's && into &#038;&#038;, a syntax error, so typing a ZIP
 * did nothing. The footer is outside the_content, so nothing rewrites it. Once
 * the footer has printed (an AJAX render, an editor preview) it stays inline.
 */
function ezbizi_late_script($script) {
    if (did_action('wp_footer')) {
        return $script;
    }
    $GLOBALS['ezbizi_late_js'][] = $script;
    return '';
}

add_action('wp_footer', function () {
    foreach ($GLOBALS['ezbizi_late_js'] ?? array() as $script) {
        echo $script; // phpcs:ignore -- built by this plugin, escaped where it is built
    }
    $GLOBALS['ezbizi_late_js'] = array();
}, 99);

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
    $radius = max(8, min(24, (int) ezbizi_widget_text($style, 'radius', '14')));
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
    $them_bg = $hex('business_bg', '#ffffff');
    // Toula, 2026-10-01: this widget's own Send, Start and focus colours.
    $send_bg = $hex('send_bg', $accent);
    $start_bg = $hex('start_bg', $accent);
    $focus = $hex('focus_color', $accent);
    $shadow = (! isset($config['shadow']) || ! empty($config['shadow']));
    $size = max(44, min(96, (int) ezbizi_widget_text($config, 'bubble_size', '56')));
    $devices = ezbizi_widget_text($config, 'devices', 'all');
    $motion = ezbizi_widget_text($config, 'animation', 'slide');
    $side = ($position === 'bottom-left') ? 'left:0' : 'right:0';

    /*
     * Every property stated, because the theme around it states them too:
     * on a-1chimney.com the theme's button styling once painted the launcher
     * and a near-black input nobody could read. `box-sizing`, `line-height`,
     * `margin` and `background` are the four a theme always sets.
     *
     * ONE size for the window (Jose, 2026-10-01: "it should already be at the
     * full size"): 360 by 540, never taller than the screen allows, and the
     * whole screen on a phone. The conversation scrolls inside it.
     */
    return '<style>'
        . $s . ',' . $s . ' *{box-sizing:border-box}'
        . $s . '{line-height:1.45;text-align:left;font-size:14px}'
        . $s . ' .ezbizi-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'

        // Faces
        . $s . ' .ezbizi-face{position:relative;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;'
        . 'width:34px;height:34px;border-radius:50%;background:#2d6a8f;color:#fff;font-weight:800;font-size:12px;'
        . 'line-height:1;overflow:hidden;letter-spacing:0}'
        . $s . ' .ezbizi-face img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;margin:0;border:0;border-radius:50%}'

        // Launcher: a pill with a face and an online dot; a round close once open.
        . $s . ' .ezbizi-chat-open{display:inline-flex;align-items:center;gap:10px;position:relative;'
        . 'background:' . $bubble_bg . ';color:' . $bubble_color . ';border:0;border-radius:999px;'
        . 'padding:6px 20px 6px 6px;font:inherit;line-height:1.15;cursor:pointer;margin:0;width:auto;text-transform:none;'
        . 'letter-spacing:normal;min-height:' . $size . 'px;box-shadow:' . ($shadow ? '0 10px 28px rgba(0,0,0,.25)' : 'none') . '}'
        . $s . ' .ezbizi-chat-open:hover{filter:brightness(.95)}'
        . $s . ' .ezbizi-chat-open:focus-visible{outline:3px solid ' . $bubble_bg . ';outline-offset:3px}'
        . $s . ' .ezbizi-chat-open .ezbizi-face{width:' . ($size - 12) . 'px;height:' . ($size - 12) . 'px;box-shadow:0 0 0 2px ' . $bubble_color . ';overflow:visible}'
        . $s . ' .ezbizi-chat-open .ezbizi-face::after{content:"";position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;'
        . 'border-radius:50%;background:#2fd36b;border:2px solid ' . $bubble_bg . '}'
        . $s . ' .ezbizi-chat-open .ezbizi-face img{border-radius:50%}'
        . $s . ' .ezbizi-chat-open .ezbizi-chat-icon{width:22px;height:22px;margin-left:12px}'
        . $s . ' .ezbizi-chat-launch-text{display:flex;flex-direction:column;text-align:left}'
        . $s . ' .ezbizi-chat-launch-text b{font-size:15px;font-weight:800;color:' . $bubble_color . '}'
        . $s . ' .ezbizi-chat-launch-text small{font-size:12px;opacity:.92;color:' . $bubble_color . '}'
        . $s . ' .ezbizi-chat-launch-x{display:none;font-size:26px;line-height:1}'
        . $s . '.ezbizi-chat-is-open .ezbizi-chat-open{width:' . $size . 'px;height:' . $size . 'px;padding:0;justify-content:center}'
        . $s . '.ezbizi-chat-is-open .ezbizi-chat-open > :not(.ezbizi-chat-launch-x){display:none}'
        . $s . '.ezbizi-chat-is-open .ezbizi-chat-launch-x{display:block}'

        // The window
        . $s . ' .ezbizi-chat-panel{position:absolute;bottom:calc(100% + 14px);' . $side . ';display:flex;flex-direction:column;'
        . 'width:360px;height:540px;max-width:calc(100vw - 32px);max-height:calc(100vh - 140px);'
        . 'background:' . $background . ';color:' . $text . ';border-radius:' . $radius . 'px;overflow:hidden;'
        . 'box-shadow:' . ($shadow ? '0 22px 60px rgba(0,0,0,.28)' : 'none') . ';border:1px solid rgba(0,0,0,.08)}'
        . $s . ' .ezbizi-chat-panel[hidden]{display:none}'

        . $s . ' .ezbizi-chat-head{flex:0 0 auto;display:flex;align-items:center;gap:12px;'
        . 'background:' . $head_bg . ';color:' . $head_color . ';padding:14px 14px 14px 16px}'
        . $s . ' .ezbizi-chat-faces{display:flex;flex:0 0 auto}'
        . $s . ' .ezbizi-chat-faces .ezbizi-face{width:38px;height:38px;box-shadow:0 0 0 2px ' . $head_bg . '}'
        . $s . ' .ezbizi-chat-faces .ezbizi-face + .ezbizi-face{margin-left:-10px}'
        . $s . ' .ezbizi-chat-head-text{flex:1 1 auto;min-width:0}'
        . $s . ' .ezbizi-chat-who{display:block;font-size:16px;font-weight:800;line-height:1.25;color:' . $head_color . ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . $s . ' .ezbizi-chat-sub{display:flex;align-items:center;gap:6px;font-size:12.5px;opacity:.95;color:' . $head_color . '}'
        . $s . ' .ezbizi-chat-dot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;background:#2fd36b;box-shadow:0 0 0 2px rgba(255,255,255,.55)}'
        . $s . ' .ezbizi-chat-close{flex:0 0 auto;background:rgba(255,255,255,.2);color:' . $head_color . ';'
        . 'border:0;border-radius:50%;width:32px;height:32px;min-height:0;padding:0;margin:0;'
        . 'font:inherit;font-size:20px;line-height:32px;cursor:pointer;box-shadow:none;text-align:center}'

        . $s . ' .ezbizi-chat-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px;background:#f6f5f3}'
        . $s . ' .ezbizi-chat-leadin{margin:0 0 12px;font-size:13.5px;color:' . $text . ';opacity:.85}'
        . $s . ' .ezbizi-chat-pre label{display:block;margin:0 0 10px;font-size:13px;font-weight:700;color:' . $text . '}'
        . $s . ' .ezbizi-chat-req{color:' . $accent . '}'
        . $s . ' .ezbizi-chat-pair{display:grid;grid-template-columns:1fr 110px;gap:10px}'
        . $s . ' .ezbizi-chat-pre input,' . $s . ' .ezbizi-chat-pre textarea,' . $s . ' .ezbizi-chat-text{'
        . 'display:block;width:100%;margin:4px 0 0;padding:10px 12px;background:#fff;color:#1b1b1b;'
        . 'border:1px solid #d3d0cb;border-radius:10px;font:inherit;font-size:14px;'
        . 'font-weight:400;line-height:1.4;box-shadow:none;min-height:0;height:auto}'
        . $s . ' .ezbizi-chat-pre textarea{resize:none}'
        . $s . ' .ezbizi-chat-pre .ezbizi-consent{margin:0 0 12px;font-size:12.5px}'
        . $s . ' .ezbizi-chat-pre .ezbizi-consent label{font-weight:400;display:flex;gap:8px;align-items:flex-start}'
        . $s . ' .ezbizi-chat-pre .ezbizi-consent-box{display:inline;width:auto;margin:2px 0 0;padding:0;flex:0 0 auto}'
        . $s . ' .ezbizi-chat-pre input:focus,' . $s . ' .ezbizi-chat-pre textarea:focus,'
        . $s . ' .ezbizi-chat-text:focus{outline:2px solid ' . $focus . ';outline-offset:0;border-color:' . $focus . '}'
        . $s . ' .ezbizi-chat-start{display:block;width:100%;margin:0;background:' . $start_bg . ';'
        . 'color:' . $on_accent . ';border:0;border-radius:10px;padding:12px 14px;'
        . 'font:inherit;font-weight:800;line-height:1.2;cursor:pointer;text-transform:none;min-height:0}'
        . $s . ' .ezbizi-chat-error{margin:10px 0 0;font-size:13px;color:#b3261e}'

        // The conversation
        . $s . ' .ezbizi-chat-log{display:flex;flex-direction:column;gap:4px}'
        . $s . ' .ezbizi-sys{align-self:center;margin:4px 0 8px;font-size:11.5px;color:#6b645c;background:#ebe7e1;padding:3px 10px;border-radius:999px}'
        . $s . ' .ezbizi-row{display:flex;gap:8px;align-items:flex-end;margin-top:6px}'
        . $s . ' .ezbizi-row-you{justify-content:flex-end}'
        . $s . ' .ezbizi-row .ezbizi-face{width:28px;height:28px;font-size:10.5px}'
        . $s . ' .ezbizi-msg{margin:0;padding:9px 12px;border-radius:16px;max-width:78%;font-size:14px;line-height:1.42;word-wrap:break-word;white-space:pre-wrap}'
        . $s . ' .ezbizi-msg-you{background:' . $you_bg . ';color:' . $on_accent . ';border-bottom-right-radius:4px}'
        . $s . ' .ezbizi-msg-them{background:' . $them_bg . ';color:#1b1b1b;border:1px solid #e6e2dc;border-bottom-left-radius:4px}'
        . $s . ' .ezbizi-msg img{display:block;max-width:100%;border-radius:10px;margin:6px 0 0}'
        . $s . ' .ezbizi-meta{font-size:11px;color:#8a8178;margin:2px 0 0 36px}'
        . $s . ' .ezbizi-meta-you{text-align:right;margin:2px 0 0}'
        . $s . ' .ezbizi-typing{display:inline-flex;gap:4px;padding:12px 14px}'
        . $s . ' .ezbizi-typing i{width:6px;height:6px;border-radius:50%;background:#9b938a;animation:ezbizi-dot 1.2s infinite}'
        . $s . ' .ezbizi-typing i:nth-child(2){animation-delay:.15s}' . $s . ' .ezbizi-typing i:nth-child(3){animation-delay:.3s}'
        . '@keyframes ezbizi-dot{0%,60%,100%{opacity:.35;transform:none}30%{opacity:1;transform:translateY(-3px)}}'

        . $s . ' .ezbizi-chat-form{flex:0 0 auto;display:flex;gap:8px;align-items:center;padding:10px 12px;background:#fff;border-top:1px solid #ece8e2;margin:0}'
        . $s . ' .ezbizi-chat-form[hidden]{display:none}'
        . $s . ' .ezbizi-chat-text{border-radius:999px;margin:0;padding:10px 14px}'
        . $s . ' .ezbizi-chat-attach{flex:0 0 auto;display:inline-flex;color:#7d756c;cursor:pointer;margin:0;padding:4px}'
        . $s . ' .ezbizi-chat-send{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;'
        . 'background:' . $send_bg . ';color:' . $on_accent . ';border:0;border-radius:50%;padding:0;margin:0;cursor:pointer;min-height:0;box-shadow:none}'
        . $s . ' .ezbizi-chat-powered{flex:0 0 auto;display:block;text-align:center;font-size:11px;color:#9a9187;background:#fff;'
        . 'padding:0 0 8px;text-decoration:none;letter-spacing:.01em}'
        . $s . ' .ezbizi-chat-powered b{color:#5f5850;font-weight:800}'
        . $s . ' .ezbizi-chat-powered:hover b{text-decoration:underline}'

        // A phone: the whole screen, so nothing is cramped and nothing resizes.
        . '@media (max-width:480px){' . $s . ' .ezbizi-chat-panel{position:fixed;inset:0;width:100%;height:100%;max-width:none;max-height:none;border-radius:0;border:0}'
        . $s . '.ezbizi-chat-is-open .ezbizi-chat-open{display:none}}'

        . ($motion === 'none' ? '' : '@keyframes ezbizi-chat-' . $motion . '{from{opacity:0' . ($motion === 'slide' ? ';transform:translateY(12px)' : '') . '}to{opacity:1;transform:none}}'
            . $s . ' .ezbizi-chat-panel:not([hidden]),' . $s . ' .ezbizi-chat-open{animation:ezbizi-chat-' . $motion . ' .25s ease-out}')
        . '@media (prefers-reduced-motion:reduce){' . $s . ' .ezbizi-chat-panel,' . $s . ' .ezbizi-chat-open,' . $s . ' .ezbizi-typing i{animation:none}}'
        . ($devices === 'desktop' ? '@media (max-width:767px){' . $s . '{display:none!important}}' : '')
        . ($devices === 'mobile' ? '@media (min-width:768px){' . $s . '{display:none!important}}' : '')
        . '</style>';
}

/**
 * [ezbizi_booking title="Book Garage Door Service"] — the appointment widget
 * (Jose, 2026-10-08, after ax.alphaaxisgaragegates.com's): ZIP, then a day,
 * then an arrival window, then who and where. The days and windows are the
 * service area's real openings (Scheduling); the request waits in Leads ▸
 * Online Booking for a dispatcher, holding its window meanwhile. Behind the
 * same robot check and Turnstile box as every EzBizi form.
 */
/**
 * This site's own privacy and terms pages, for the booking SMS box. Found by
 * slug, since WordPress's privacy setting is often unset or wrong (Cleveland's
 * pointed at its gallery). No dedicated SMS terms page exists on any site
 * (2026-11-11), so the general terms page stands in. '' when there is none.
 */
function ezbizi_legal_url($kind) {
    $cached = get_transient('ezbizi_legal_' . $kind);

    if (is_string($cached)) {
        return $cached;
    }

    global $wpdb;
    $url = '';

    if (is_object($wpdb)) {
        $patterns = $kind === 'privacy' ? array('%privacy%') : array('%sms%', 'terms%');

        foreach ($patterns as $like) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_name LIKE %s ORDER BY ID LIMIT 1",
                $like
            ));

            if ($id) {
                $url = (string) get_permalink((int) $id);
                break;
            }
        }
    }

    set_transient('ezbizi_legal_' . $kind, $url, 86400);

    return $url;
}

function ezbizi_booking_shortcode($atts) {
    // No chat bubble on a booking page (Jose, 2026-10-09): it sat on the card.
    // chat="keep" leaves it, for a site with the widget on every page
    // (suggestion 01a1889b, 2026-10-29: Everdoors lost chat site-wide).
    // A site can also default it with the ezbizi_booking_hides_chat filter.
    $keep = is_array($atts) && isset($atts['chat']) && strtolower((string) $atts['chat']) === 'keep';
    if (! $keep && apply_filters('ezbizi_booking_hides_chat', true)) {
        $GLOBALS['ezbizi_chat_hidden'] = true;
    }
    $key = ezbizi_public_key();
    $config = ezbizi_widget_config('form');

    if ($key === '' || empty($config)) {
        return ezbizi_widget_silent(__('EzBizi: this site has no browser key or widget settings yet. Click EzBizi in the sidebar.', 'ezbizi'));
    }

    $atts = shortcode_atts(array('title' => __('Book Service', 'ezbizi'), 'chat' => 'hide'), $atts);
    $style = isset($config['style']) && is_array($config['style']) ? $config['style'] : array();
    $accent = esc_attr(ezbizi_widget_text($style, 'accent', '#0b6e9f'));
    $on = esc_attr(ezbizi_widget_text($style, 'on_accent', '#ffffff'));
    $id = ezbizi_widget_id('ezbizi-booking');
    $s = '#' . $id;
    $line = 'color-mix(in srgb,' . $accent . ' 45%,#fff)';
    $soft = 'color-mix(in srgb,' . $accent . ' 10%,#fff)';
    // A link to the site's own page, or the words alone when it has none.
    $legal = function ($kind, $text) {
        $url = ezbizi_legal_url($kind);

        return $url === '' ? esc_html($text)
            : '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text) . '</a>';
    };
    $head = function ($n, $text) {
        return '<div class="ezb-head"><b>' . esc_html($text) . '</b></div>';
    };

    return '<style>'
        . $s . '{max-width:660px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.12);overflow:hidden;font-family:inherit;color:#183a33}'
        // The page already says what this is, so the card names itself only
        // to a screen reader; its top is where you are in the steps (UI queue
        // 75: dots and words, no numbers - Jose, 2026-10-09, "no 01/02").
        . $s . ' .ezb-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;margin:0;padding:0}'
        . $s . ' .ezb-track{display:flex;list-style:none;margin:0;padding:20px 24px 6px}'
        . $s . ' .ezb-track li{flex:1;position:relative;text-align:center;font-size:12px;font-weight:700;color:#9aa6ac;margin:0;padding:0}'
        . $s . ' .ezb-track li:before{content:"";display:block;position:relative;z-index:1;width:10px;height:10px;margin:0 auto 6px;border-radius:50%;border:2px solid #d6dde1;background:#fff}'
        . $s . ' .ezb-track li+li:after{content:"";position:absolute;top:6px;right:50%;width:100%;height:2px;background:#e3e8eb}'
        . $s . ' .ezb-track li.on,' . $s . ' .ezb-track li.done{color:#183a33}'
        . $s . ' .ezb-track li.on:before{border-color:' . $accent . ';background:' . $accent . '}'
        . $s . ' .ezb-track li.done:before{border-color:' . $accent . '}'
        . $s . ' .ezb-track li.on:after,' . $s . ' .ezb-track li.done:after{background:' . $accent . '}'
        // One fixed size for every step (Jose, 2026-10-08: "it needs to have a
        // fixed size"); a step taller than the card scrolls inside it.
        . $s . ' .ezb-body{padding:16px 40px 24px;min-height:540px;box-sizing:border-box;display:flex;flex-direction:column}'
        // A short step sits in the middle of the fixed card, not at its top.
        . $s . ' .ezb-body>[data-step]{flex:0 0 auto}'
        // The first step is the shortest: what happens next fills its foot.
        . $s . ' .ezb-body>[data-step=zip]{flex:1 0 auto;display:flex;flex-direction:column}'
        // A step's own display must not outrank hidden, or two steps show.
        . $s . ' [data-step][hidden]{display:none!important}'
        . $s . ' .ezb-zipbox{position:relative;display:block}'
        . $s . ' .ezb-zipbox svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);margin-top:3px;width:20px;height:20px;fill:' . $accent . '}'
        . $s . ' .ezb-zipbox input{font-size:20px;font-weight:700;letter-spacing:.12em;padding:14px 14px 14px 44px}'
        . $s . ' .ezb-how{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:auto;padding-top:18px;border-top:1px solid #eef1f3}'
        . $s . ' .ezb-how div{text-align:center;font-size:12.5px;line-height:1.35;color:#4a5761}'
        . $s . ' .ezb-how b{display:block;color:#183a33;font-size:13px;margin:6px 0 2px}'
        . $s . ' .ezb-how svg{width:36px;height:36px;padding:8px;box-sizing:border-box;border-radius:50%;background:' . $soft . ';fill:' . $accent . '}'
        . $s . ' .ezb-foot{background:#f7f8f9;border-top:1px solid #eef1f3;padding:12px 16px;text-align:center;font-size:13px;color:#4a5761}'
        . $s . ' .ezb-back{background:none;border:0;color:' . $accent . ';cursor:pointer;padding:0;margin:0 0 8px;font-size:14px}'
        . $s . ' .ezb-head{text-align:center;padding:10px 0 12px;margin:0 0 14px;border-bottom:1px solid ' . $line . '}'
        . $s . ' .ezb-head small{display:block;font-size:14px;margin-top:4px;opacity:.8}'
        . $s . ' .ezb-head b{font-size:22px}'
        . $s . ' .ezb-grid{display:grid;gap:10px;grid-template-columns:repeat(4,1fr)}'
        . $s . ' .ezb-times{grid-template-columns:repeat(3,1fr)}'
        . $s . ' .ezb-card{background:#fff;border:1px solid ' . $line . ';border-radius:12px;padding:16px 4px;cursor:pointer;color:inherit;font:inherit;text-align:center;line-height:1.3}'
        . $s . ' .ezb-card:hover,' . $s . ' .ezb-card:focus,' . $s . ' .ezb-card.on{border-color:' . $accent . ';background:' . $soft . ';outline:none}'
        . $s . ' .ezb-card small{display:block;font-size:11px;text-transform:uppercase}'
        . $s . ' .ezb-card strong{display:block;font-size:22px;margin:4px 0}'
        . $s . ' .ezb-times .ezb-card{padding:12px 4px;font-weight:700;font-size:14px}'
        . $s . ' .ezb-pill{background:' . $soft . ';border-radius:10px;padding:10px;text-align:center;font-weight:700;font-size:14px;margin:0 0 14px}'
        . $s . ' label{display:block;font-weight:700;font-size:13px;margin:0 0 12px}'
        . $s . ' input{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:12px 14px;border:1px solid #c9d3dc;border-radius:10px;font:inherit;font-weight:400}'
        . $s . ' .ezb-sms{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;font-weight:400;line-height:1.45;text-align:left}'
        . $s . ' .ezb-sms input{width:auto;min-height:0;margin:2px 0 0;flex:0 0 auto}'
        . $s . ' .ezb-sms a{color:inherit;text-decoration:underline}'
        . $s . ' .ezb-go{display:block;width:100%;background:' . $accent . ';color:' . $on . ';border:0;border-radius:10px;padding:14px;font:inherit;font-weight:700;font-size:16px;cursor:pointer}'
        . $s . ' .ezb-go[disabled]{opacity:.6}'
        . $s . ' .ezb-note{text-align:center;font-size:13px;margin:10px 0 0;min-height:1em}'
        // A theme's own input and button styles must not reach in (a-1chimney.com's are dark and uppercase).
        . $s . ' input{background:#fff!important;color:#183a33!important}'
        . $s . ' button{text-transform:none!important;letter-spacing:normal!important;box-shadow:none!important;font-family:inherit}'
        . $s . ' .ezb-back{border:0!important;background:none!important}'
        . $s . ' .ezb-done{text-align:center;padding:20px 0}'
        . '@media (max-width:600px){' . $s . ' .ezb-body{padding:12px 16px 20px;min-height:500px}' . $s . ' .ezb-grid{grid-template-columns:repeat(2,1fr)}' . $s . ' .ezb-times{grid-template-columns:1fr 1fr}'
        . $s . ' .ezb-track{padding:16px 8px 4px}' . $s . ' .ezb-track li{font-size:11px}'
        . $s . ' .ezb-how{grid-template-columns:1fr;gap:10px}' . $s . ' .ezb-how div{display:flex;align-items:center;gap:10px;text-align:left}' . $s . ' .ezb-how b{display:inline;margin:0}}'
        . '</style>'
        . '<div class="ezbizi-booking" id="' . esc_attr($id) . '">'
        . '<h2 class="ezb-sr">' . esc_html($atts['title']) . '</h2>'
        . '<ol class="ezb-track">'
        . '<li class="on" data-for="zip">' . esc_html__('ZIP code', 'ezbizi') . '</li>'
        . '<li data-for="day">' . esc_html__('Day', 'ezbizi') . '</li>'
        . '<li data-for="time">' . esc_html__('Time', 'ezbizi') . '</li>'
        . '<li data-for="details">' . esc_html__('Your details', 'ezbizi') . '</li></ol>'
        . '<div class="ezb-body">'
        . '<form data-step="zip" novalidate>' . $head('', __('Please enter your ZIP code', 'ezbizi'))
        . '<label>' . esc_html__('So we can show the days we come to your area.', 'ezbizi')
        . '<span class="ezb-zipbox"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>'
        . '<input name="zip" inputmode="numeric" autocomplete="postal-code" pattern="[0-9]{5}" maxlength="5" required placeholder="' . esc_attr__('5-digit ZIP', 'ezbizi') . '" /></span></label>'
        . '<button class="ezb-go" type="submit">' . esc_html__('See available days', 'ezbizi') . '</button>'
        // What happens next, where the first step would otherwise be empty.
        . '<div class="ezb-how">'
        . '<div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2zm-2 8h14v10H5z"/></svg><span><b>' . esc_html__('Pick a time', 'ezbizi') . '</b> ' . esc_html__('A day and an arrival window.', 'ezbizi') . '</span></div>'
        . '<div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg><span><b>' . esc_html__('We call to confirm', 'ezbizi') . '</b> ' . esc_html__('Before anyone comes out.', 'ezbizi') . '</span></div>'
        . '<div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg><span><b>' . esc_html__('We arrive', 'ezbizi') . '</b> ' . esc_html__('In the window you chose.', 'ezbizi') . '</span></div>'
        . '</div></form>'
        . '<div data-step="day" hidden><button type="button" class="ezb-back" data-to="zip">&lsaquo; ' . esc_html__('Back', 'ezbizi') . '</button>'
        . $head('', __('Please pick a day that works for you', 'ezbizi')) . '<div class="ezb-grid ezb-days"></div></div>'
        . '<div data-step="time" hidden><button type="button" class="ezb-back" data-to="day">&lsaquo; ' . esc_html__('Back', 'ezbizi') . '</button>'
        . '<div class="ezb-head"><b>' . esc_html__('Please choose an arrival window', 'ezbizi') . '</b><small class="ezb-dayname"></small></div><div class="ezb-grid ezb-times"></div></div>'
        . '<form data-step="details" hidden novalidate><button type="button" class="ezb-back" data-to="time">&lsaquo; ' . esc_html__('Back', 'ezbizi') . '</button>'
        . $head('', __('Please tell us how to reach you', 'ezbizi')) . '<div class="ezb-pill"></div>'
        . '<label>' . esc_html__('Your name', 'ezbizi') . '<input name="name" autocomplete="name" required placeholder="' . esc_attr__('Your full name', 'ezbizi') . '" /></label>'
        . '<label>' . esc_html__('Phone number', 'ezbizi') . '<input name="phone" type="tel" autocomplete="tel" required placeholder="' . esc_attr__('Best number to call or text', 'ezbizi') . '" /></label>'
        . '<label>' . esc_html__('Full service address', 'ezbizi') . '<input name="address" autocomplete="street-address" required placeholder="' . esc_attr__('Street, city, state', 'ezbizi') . '" /></label>'
        // Jose, 2026-11-11, his words. Unticked by default and never required:
        // consent to texts cannot be a condition of booking (TCPA, A2P 10DLC).
        . '<label class="ezb-sms"><input type="checkbox" name="sms_consent" /><span>'
        . esc_html__('By checking this box, I consent to receive text messages related to orders, service appointments, or other relevant information. You can reply STOP at any time to opt out. Message and data rates may apply. Message frequency may vary. For more information, please refer to our', 'ezbizi') . ' '
        . $legal('privacy', __('Privacy Policy', 'ezbizi')) . ' ' . esc_html__('and', 'ezbizi') . ' '
        . $legal('sms', __('SMS Terms and Conditions', 'ezbizi')) . '.</span></label>'
        . ezbizi_turnstile_box()
        . '<button class="ezb-go" type="submit">' . esc_html__('Book appointment', 'ezbizi') . '</button></form>'
        . '<div class="ezb-done" data-step="done" hidden><b style="font-size:22px">' . esc_html__('Request received', 'ezbizi') . '</b>'
        . '<p class="ezb-when"></p><p>' . esc_html__('We will call you shortly to confirm your appointment.', 'ezbizi') . '</p></div>'
        . '<p class="ezb-note" role="status" aria-live="polite"></p>'
        . '</div><div class="ezb-foot">' . esc_html__('No payment to book · We call to confirm', 'ezbizi') . '</div></div>'
        . ezbizi_late_script("<script>(function(){
var root=document.getElementById(" . wp_json_encode($id) . ");if(!root)return;
var api=" . wp_json_encode(ezbizi_api_base()) . ",key=" . wp_json_encode($key) . ",st={},note=root.querySelector('.ezb-note');
" . ezbizi_solver_js() . "
function q(s){return root.querySelector(s);}
var order=['zip','day','time','details'];
function show(n){note.textContent='';Array.prototype.forEach.call(root.querySelectorAll('[data-step]'),function(e){e.hidden=e.getAttribute('data-step')!==n;});
  var at=n==='done'?order.length:order.indexOf(n);
  Array.prototype.forEach.call(root.querySelectorAll('.ezb-track li'),function(li,i){li.className=i<at?'done':(i===at?'on':'');});}
function day(d,o){return new Date(d+'T12:00:00').toLocaleDateString('en-US',o);}
function hm(t){var h=+t.slice(0,2),m=t.slice(3,5);return(h%12||12)+':'+m+' '+(h<12?'AM':'PM');}
function win(w){return hm(w.start_time)+' - '+hm(w.end_time);}
function card(parent,html,go){var b=document.createElement('button');b.type='button';b.className='ezb-card';b.innerHTML=html;b.addEventListener('click',function(){Array.prototype.forEach.call(parent.children,function(c){c.classList.toggle('on',c===b);});go();});parent.appendChild(b);}
Array.prototype.forEach.call(root.querySelectorAll('.ezb-back'),function(b){b.addEventListener('click',function(){show(b.getAttribute('data-to'));});});
function load(){
  var z=q('[name=zip]').value.trim();note.textContent='...';
  return fetch(api+'/api/v1/web/booking/days?key='+encodeURIComponent(key)+'&zip='+encodeURIComponent(z),{headers:{'Accept':'application/json'}})
  .then(function(r){return r.json().then(function(d){return{ok:r.ok,status:r.status,d:d};});}).then(function(x){
    // No online times for that ZIP (or no service areas yet): still take the request,
    // as a lead the office calls back to schedule (Jose, 2026-10-20).
    if(x.status===404){st.zip=z;st.req=1;q('.ezb-pill').textContent=" . wp_json_encode(__("We don't have online times there yet. Send your details and we'll call you to schedule.", 'ezbizi')) . ";show('details');return;}
    st.req=0;
    if(!x.ok){note.textContent=x.d.message||" . wp_json_encode(__('That did not work. Please call us.', 'ezbizi')) . ";return;}
    if(!x.d.days.length){note.textContent=" . wp_json_encode(__('We have no open times online in the next few weeks. Please call us.', 'ezbizi')) . ";return;}
    st.zip=z;var g=q('.ezb-days');g.innerHTML='';
    // The window Scheduling routes best goes first in its day, unlabelled (Jose, 2026-10-13).
    var pf=x.d.preferred;
    x.d.days.forEach(function(d){if(pf&&pf.date===d.date)d.windows.sort(function(a,b){return(b.start_time===pf.start_time)-(a.start_time===pf.start_time);});var n=d.windows.length;card(g,'<small>'+day(d.date,{weekday:'short'})+'</small><strong>'+day(d.date,{day:'numeric'})+'</strong><small>'+day(d.date,{month:'short'})+'</small><small style=\"text-transform:none;opacity:.75\">'+n+' '+(n===1?" . wp_json_encode(__('window', 'ezbizi')) . ":" . wp_json_encode(__('windows', 'ezbizi')) . ")+'</small>',function(){
      st.date=d.date;q('.ezb-dayname').textContent=day(d.date,{weekday:'long',month:'long',day:'numeric'});var t=q('.ezb-times');t.innerHTML='';
      d.windows.forEach(function(w){card(t,win(w),function(){st.win=w;q('.ezb-pill').textContent=day(d.date,{weekday:'long',month:'long',day:'numeric'})+' · '+win(w);show('details');});});
      show('time');});});
    show('day');
  }).catch(function(){note.textContent=" . wp_json_encode(__('That did not work. Please call us.', 'ezbizi')) . ";});
}
q('[data-step=zip]').addEventListener('submit',function(e){e.preventDefault();var f=e.target;if(!f.checkValidity()){f.reportValidity();return;}load();});
// A lead's own link (?l=, Jose 2026-10-13): the booking lands on that lead, and
// what it already holds (ZIP, name, phone) is not asked again.
var lt='';try{lt=new URLSearchParams(location.search).get('l')||'';}catch(x){}
if(lt){fetch(api+'/api/v1/web/booking/lead?key='+encodeURIComponent(key)+'&l='+encodeURIComponent(lt),{headers:{'Accept':'application/json'}})
  .then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});}).then(function(x){
    if(!x.ok){lt='';note.textContent=x.d.message||'';return;}
    var f=q('[data-step=details]');
    ['name','phone'].forEach(function(n){if(x.d['has_'+n]){var i=f.elements[n];i.required=false;i.closest('label').style.display='none';}});
    if(x.d.zip){q('[name=zip]').value=x.d.zip;load();}
  }).catch(function(){lt='';});}
q('[data-step=details]').addEventListener('submit',function(e){
  e.preventDefault();var f=e.target;if(!f.checkValidity()){f.reportValidity();return;}
  var ts=ezTs(f);if(ts===''){note.textContent=" . wp_json_encode(__('Please complete the check above the button.', 'ezbizi')) . ";return;}
  var b=f.querySelector('.ezb-go');b.disabled=true;note.textContent='';
  var v={key:key,zip:st.zip,date:st.date,start_time:st.win&&st.win.start_time,name:f.elements['name'].value.trim(),phone:f.elements.phone.value.trim(),address:f.elements.address.value.trim(),page_url:location.href};
  var sc=f.elements.sms_consent;if(sc&&sc.checked){v.sms_consent=true;v.sms_consent_text=sc.parentNode.textContent.replace(/\s+/g,' ').trim();}
  var to='/api/v1/web/booking';
  if(st.req){to='/api/v1/web/form';v={key:key,zip:st.zip,name:v.name,phone:v.phone,page_url:v.page_url,fields:{'Booking request':'Call to schedule','Name':v.name,'Phone':v.phone,'Address':v.address,'ZIP code':st.zip},sms_consent:v.sms_consent,sms_consent_text:v.sms_consent_text};}
  if(ts)v.turnstile=ts;if(lt)v.l=lt;
  ezSolve(api).catch(function(){return null;}).then(function(sol){if(sol)v.captcha=sol;
    return fetch(api+to,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(v)});})
  .then(function(r){ezTsReset(f);b.disabled=false;
    if(r.ok){q('.ezb-when').textContent=q('.ezb-pill').textContent;show('done');return;}
    return r.json().catch(function(){return{};}).then(function(d){
      // Taken while they typed: back to the days, freshly loaded.
      if(r.status===409){load().then(function(){note.textContent=d.message||'';});return;}
      note.textContent=d.message||" . wp_json_encode(__('That did not send. Please try again or call us.', 'ezbizi')) . ";});
  }).catch(function(){b.disabled=false;note.textContent=" . wp_json_encode(__('That did not send. Please try again or call us.', 'ezbizi')) . ";});
});
})();</script>");
}

add_action('init', function () {
    add_shortcode('ezbizi_form', 'ezbizi_form_shortcode');
    add_shortcode('ezbizi_booking', 'ezbizi_booking_shortcode');
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
    // Drawn already, or a page that wants none: the booking and review-faces
    // widgets set ezbizi_chat_hidden as they render (Jose, 2026-10-09).
    if (! empty($GLOBALS['ezbizi_chat_drawn']) || ! empty($GLOBALS['ezbizi_chat_hidden'])) {
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
