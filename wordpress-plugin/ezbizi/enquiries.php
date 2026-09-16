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
        . '</style>';
}

/** Nothing on a visitor's page; a sentence for somebody who can fix it. */
function ezbizi_widget_silent($why) {
    return current_user_can('manage_options') ? '<p>' . esc_html($why) . '</p>' : '';
}

/** [ezbizi_form] — the contact form EzBizi describes, landing as a Lead. */
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

    $id = 'ezbizi-form-' . wp_rand(1000, 9999);
    $out = ezbizi_widget_style($id, $config['style']);
    $out .= '<form class="ezbizi-form" id="' . esc_attr($id) . '" novalidate>';

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

        $out .= '<p><label for="' . esc_attr($field_id) . '">' . esc_html($label);

        if ($required) {
            $out .= ' <span aria-hidden="true">*</span>';
        }

        $out .= '</label><br />';

        $common = 'id="' . esc_attr($field_id) . '" name="' . esc_attr($label) . '"'
            . ($required ? ' required' : '');

        if ($type === 'textarea') {
            $out .= '<textarea ' . $common . ' rows="4"></textarea>';
        } elseif ($type === 'select') {
            $out .= '<select ' . $common . '><option value=""></option>';

            foreach (isset($field['options']) && is_array($field['options']) ? $field['options'] : array() as $option) {
                $option = (string) $option;
                $out .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
            }

            $out .= '</select>';
        } else {
            $html_type = in_array($type, array('email', 'tel'), true) ? $type : 'text';
            $out .= '<input type="' . esc_attr($html_type) . '" ' . $common . ' />';
        }

        $out .= '</p>';
    }

    $out .= '<p><button type="submit">' . esc_html(ezbizi_widget_text($config, 'button', 'Send')) . '</button></p>';
    $out .= '<p class="ezbizi-form-note" role="status" aria-live="polite"></p>';
    $out .= '</form>';

    $out .= "<script>(function(){
var f=document.getElementById(" . wp_json_encode($id) . ");
if(!f)return;
var note=f.querySelector('.ezbizi-form-note');
f.addEventListener('submit',function(e){
  e.preventDefault();
  var b=f.querySelector('button');b.disabled=true;
  var fields={},v={};
  Array.prototype.forEach.call(f.querySelectorAll('input,textarea,select'),function(i){
    var n=i.name,low=n.toLowerCase();
    if(low==='name'){v.name=i.value;}
    else if(low==='email'){v.email=i.value;}
    else if(low==='phone'){v.phone=i.value;}
    fields[n]=i.value;
  });
  v.key=" . wp_json_encode($key) . ";v.fields=fields;v.page_url=location.href;
  fetch(" . wp_json_encode(ezbizi_api_base() . '/api/v1/web/form') . ",{
    method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},
    body:JSON.stringify(v)
  }).then(function(r){
    if(!r.ok)throw new Error(r.status);
    f.reset();note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'thanks', 'Thank you.')) . ";
  }).catch(function(){
    note.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'That did not send.')) . ";
  }).then(function(){b.disabled=false;});
});
})();</script>";

    return $out;
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

    $id = 'ezbizi-chat-' . wp_rand(1000, 9999);
    $position = ezbizi_widget_text($config, 'position', 'bottom-right');

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
        $anchor = 'position:fixed;right:20px;bottom:88px;z-index:2147483000';
    } elseif ($position === 'bottom-left') {
        $anchor = 'position:fixed;left:20px;bottom:88px;z-index:2147483000';
    }

    $title = ezbizi_widget_text($config, 'title', 'Chat with us');
    $intro = ezbizi_widget_text($config, 'intro');
    $lead_in = ezbizi_widget_text($config, 'lead_in');
    $name_label = ezbizi_widget_text($config, 'name_label', 'Your name');
    $contact_label = ezbizi_widget_text($config, 'contact_label', 'Phone or email');
    $message_label = ezbizi_widget_text($config, 'message_label', 'How can we help?');

    $out = ezbizi_widget_style($id, $config['style']);
    $out .= ezbizi_chat_style($id, $config['style'], $position);

    $out .= '<div class="ezbizi-chat" id="' . esc_attr($id) . '" style="' . esc_attr($anchor) . '">'
        . '<button type="button" class="ezbizi-chat-open" aria-expanded="false">'
        . ezbizi_chat_icon()
        . '<span>' . esc_html(ezbizi_widget_text($config, 'button', 'Chat')) . '</span>'
        . '</button>'

        . '<div class="ezbizi-chat-panel" hidden role="dialog" aria-label="' . esc_attr($title) . '">'

        . '<div class="ezbizi-chat-head">'
        . '<div class="ezbizi-chat-head-text">'
        . '<strong>' . esc_html($title) . '</strong>'
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

    $out .= "<script>(function(){
var root=document.getElementById(" . wp_json_encode($id) . ");
if(!root)return;
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
  // One field, because asking for both is asking twice. An address has an @
  // in it and a phone number does not.
  if(contact.indexOf('@')>-1)body.email=contact;else body.phone=contact;
  fetch(api+'/api/v1/web/chat',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){
      if(!keep(d)){err.textContent=" . wp_json_encode(ezbizi_widget_text($config, 'error', 'Sorry — that did not send. Please try again.')) . ";err.hidden=false;return;}
      started();
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

/** A speech bubble, inline so the widget never waits on an icon font. */
function ezbizi_chat_icon() {
    return '<svg class="ezbizi-chat-icon" width="18" height="18" viewBox="0 0 24 24" '
        . 'fill="currentColor" aria-hidden="true" focusable="false">'
        . '<path d="M12 3C6.98 3 3 6.36 3 10.5c0 2.3 1.24 4.35 3.2 5.71-.13 1.2-.6 2.3-1.36 3.2'
        . '-.22.26-.03.66.31.63 1.94-.15 3.5-.87 4.6-1.66.72.14 1.47.22 2.25.22 5.02 0 9-3.36 '
        . '9-7.6S17.02 3 12 3z"/></svg>';
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
function ezbizi_chat_style($id, $style, $position) {
    $accent = esc_attr(ezbizi_widget_text($style, 'accent', '#0b5cad'));
    $on_accent = esc_attr(ezbizi_widget_text($style, 'on_accent', '#ffffff'));
    $text = esc_attr(ezbizi_widget_text($style, 'text', '#1b1b1b'));
    $background = esc_attr(ezbizi_widget_text($style, 'background', '#ffffff'));
    $radius = (int) ezbizi_widget_text($style, 'radius', '12');
    $s = '#' . esc_attr($id);

    // Opens from the corner it sits in, so the panel never runs off the screen.
    $side = ($position === 'bottom-left') ? 'left:0' : 'right:0';

    return '<style>'
        . $s . ',' . $s . ' *{box-sizing:border-box}'
        . $s . '{line-height:1.45;text-align:left}'

        . $s . ' .ezbizi-chat-open{display:inline-flex;align-items:center;gap:8px;'
        . 'background:' . $accent . ';color:' . $on_accent . ';border:0;border-radius:999px;'
        . 'padding:12px 18px;font:inherit;font-weight:600;line-height:1;cursor:pointer;'
        . 'box-shadow:0 6px 20px rgba(0,0,0,.18);margin:0;width:auto;text-transform:none;'
        . 'letter-spacing:normal;min-height:0;height:auto}'
        . $s . ' .ezbizi-chat-open:hover{filter:brightness(.94)}'
        . $s . ' .ezbizi-chat-icon{flex:0 0 auto}'

        . $s . ' .ezbizi-chat-panel{position:absolute;bottom:calc(100% + 10px);' . $side . ';'
        . 'width:340px;max-width:calc(100vw - 40px);background:' . $background . ';color:' . $text . ';'
        . 'border-radius:' . max(8, $radius) . 'px;overflow:hidden;'
        . 'box-shadow:0 18px 50px rgba(0,0,0,.24);border:1px solid rgba(0,0,0,.08)}'

        . $s . ' .ezbizi-chat-head{display:flex;align-items:flex-start;gap:10px;'
        . 'background:' . $accent . ';color:' . $on_accent . ';padding:14px 16px}'
        . $s . ' .ezbizi-chat-head-text{flex:1 1 auto;min-width:0}'
        . $s . ' .ezbizi-chat-head strong{display:block;font-size:15px;line-height:1.3;color:' . $on_accent . '}'
        . $s . ' .ezbizi-chat-head span{display:block;font-size:13px;opacity:.9;color:' . $on_accent . '}'
        . $s . ' .ezbizi-chat-close{flex:0 0 auto;background:rgba(255,255,255,.22);color:' . $on_accent . ';'
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
        . $s . ' .ezbizi-chat-pre input:focus,' . $s . ' .ezbizi-chat-pre textarea:focus,'
        . $s . ' .ezbizi-chat-text:focus{outline:2px solid ' . $accent . ';outline-offset:1px}'
        . $s . ' .ezbizi-chat-start{display:block;width:100%;margin:4px 0 0;background:' . $accent . ';'
        . 'color:' . $on_accent . ';border:0;border-radius:' . max(4, $radius - 4) . 'px;padding:11px 14px;'
        . 'font:inherit;font-weight:700;line-height:1.2;cursor:pointer;text-transform:none;min-height:0}'
        . $s . ' .ezbizi-chat-error{margin:10px 0 0;font-size:13px;color:#b3261e}'

        . $s . ' .ezbizi-chat-log{max-height:280px;overflow:auto;margin:0 0 10px}'
        . $s . ' .ezbizi-msg{margin:0 0 6px;padding:8px 11px;border-radius:14px;max-width:85%;'
        . 'font-size:14px;word-wrap:break-word}'
        . $s . ' .ezbizi-msg-you{margin-left:auto;background:' . $accent . ';color:' . $on_accent . '}'
        . $s . ' .ezbizi-msg-them{margin-right:auto;background:#f1f3f5;color:#1b1b1b}'
        . $s . ' .ezbizi-chat-form{display:flex;gap:8px;align-items:center}'
        . $s . ' .ezbizi-chat-form button{flex:0 0 auto;background:' . $accent . ';color:' . $on_accent . ';'
        . 'border:0;border-radius:' . max(4, $radius - 4) . 'px;padding:10px 14px;font:inherit;'
        . 'font-weight:600;line-height:1.2;cursor:pointer;margin:0;text-transform:none;min-height:0}'
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
