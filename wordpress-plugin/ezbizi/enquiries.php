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
function ezbizi_widget_config($kind) {
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

    $id = 'ezbizi-chat-' . wp_rand(1000, 9999);
    $position = ezbizi_widget_text($config, 'position', 'bottom-right');

    $anchor = '';

    if ($position === 'bottom-right') {
        $anchor = 'position:fixed;right:20px;bottom:20px;z-index:9999';
    } elseif ($position === 'bottom-left') {
        $anchor = 'position:fixed;left:20px;bottom:20px;z-index:9999';
    }

    $out = ezbizi_widget_style($id, $config['style']);
    $out .= '<div class="ezbizi-chat" id="' . esc_attr($id) . '" style="' . esc_attr($anchor) . '">'
        . '<button type="button" class="ezbizi-chat-open">'
        . esc_html(ezbizi_widget_text($config, 'button', 'Chat')) . '</button>'
        . '<div class="ezbizi-chat-panel" hidden style="background:var(--ezbizi-bg);'
        . 'border:1px solid #ddd;border-radius:12px;padding:14px;width:320px;max-width:90vw;margin-top:8px">'
        . '<strong>' . esc_html(ezbizi_widget_text($config, 'title', 'Chat with us')) . '</strong>'
        . '<p class="ezbizi-chat-intro">' . esc_html(ezbizi_widget_text($config, 'intro')) . '</p>'
        . '<div class="ezbizi-chat-log" role="log" aria-live="polite" '
        . 'style="max-height:260px;overflow:auto;margin:8px 0"></div>'
        . '<form class="ezbizi-chat-form">'
        . '<input type="text" class="ezbizi-chat-text" placeholder="'
        . esc_attr(ezbizi_widget_text($config, 'placeholder', 'Type a message')) . '" />'
        . '<p><button type="submit">' . esc_html(ezbizi_widget_text($config, 'send', 'Send')) . '</button></p>'
        . '</form></div></div>';

    $out .= "<script>(function(){
var root=document.getElementById(" . wp_json_encode($id) . ");
if(!root)return;
var api=" . wp_json_encode(ezbizi_api_base()) . ",key=" . wp_json_encode($key) . ";
var panel=root.querySelector('.ezbizi-chat-panel'),log=root.querySelector('.ezbizi-chat-log');
var form=root.querySelector('.ezbizi-chat-form'),box=root.querySelector('.ezbizi-chat-text');
var store=window.sessionStorage,chat=null,token=null,timer=null;
try{chat=store.getItem('ezbizi_chat');token=store.getItem('ezbizi_chat_token');}catch(e){}
function draw(ms){
  log.innerHTML='';
  ms.forEach(function(m){
    var p=document.createElement('p');
    p.style.margin='4px 0';
    p.style.textAlign=(m.from==='you')?'right':'left';
    // textContent, never innerHTML: this is a stranger's typing coming back.
    p.textContent=m.text||'';
    log.appendChild(p);
  });
  log.scrollTop=log.scrollHeight;
}
function poll(){
  if(!chat||!token)return;
  fetch(api+'/api/v1/web/chat/'+chat+'/messages',{headers:{'Authorization':'Bearer '+token}})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){if(d&&d.messages)draw(d.messages);}).catch(function(){});
}
root.querySelector('.ezbizi-chat-open').addEventListener('click',function(){
  panel.hidden=!panel.hidden;
  if(!panel.hidden){
    poll();
    // Only while the panel is open. A closed widget polling on every page of
    // every visitor's session is a request per visitor every few seconds, for
    // something nobody is looking at.
    if(!timer)timer=setInterval(poll,5000);
  } else if(timer){clearInterval(timer);timer=null;}
});
form.addEventListener('submit',function(e){
  e.preventDefault();
  var text=box.value.trim();
  if(!text)return;
  box.value='';
  var url=chat?api+'/api/v1/web/chat/'+chat+'/messages':api+'/api/v1/web/chat';
  var headers={'Content-Type':'application/json','Accept':'application/json'};
  if(chat&&token)headers['Authorization']='Bearer '+token;
  var body=chat?{message:text}:{key:key,message:text,page_url:location.href};
  fetch(url,{method:'POST',headers:headers,body:JSON.stringify(body)})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){
      if(!d)return;
      if(d.chat&&d.token){
        chat=d.chat;token=d.token;
        try{store.setItem('ezbizi_chat',chat);store.setItem('ezbizi_chat_token',token);}catch(e){}
        if(!timer)timer=setInterval(poll,5000);
      }
      if(d.messages)draw(d.messages);
    }).catch(function(){});
});
})();</script>";

    return $out;
}

add_action('init', function () {
    add_shortcode('ezbizi_form', 'ezbizi_form_shortcode');
    add_shortcode('ezbizi_chat', 'ezbizi_chat_shortcode');
});
