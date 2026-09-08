<?php
/**
 * The two things a visitor can start on a customer's website: a chat and a form.
 *
 * ── Why these post to EzBizi and not to WordPress ──
 *
 * An enquiry is a Lead, and Leads live in EzBizi beside the ones from Google
 * and Thumbtack. Storing them in WordPress first would put half a company's
 * enquiries on a server we do not run, in a table nobody reads, waiting for a
 * sync to go wrong. The visitor's browser talks to EzBizi directly.
 *
 * ── Why the browser and not this plugin ──
 *
 * A form that posted to PHP here would need this plugin to hold the site's
 * credential and forward it, and every submission would be a request the
 * customer's server makes and can fail at. The browser posts with the PUBLIC
 * key — the same `pk_` that is already in page source for the values snippet —
 * so the plugin is only ever rendering markup.
 *
 * ── What the public key can and cannot do ──
 *
 * It can start an enquiry on this site, and that is all. It cannot read
 * anybody's conversation: EzBizi mints a per-conversation token when a chat
 * begins, and only that token reads that chat back. The token lives in this
 * browser's sessionStorage and nowhere else.
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
 * [ezbizi_form] — a contact form that lands in EzBizi as a Lead.
 *
 * Fields are named in the shortcode so a site owner can ask for what they
 * actually need without a form designer:
 *
 *   [ezbizi_form fields="Name,Email,Phone,What do you need?"]
 *
 * Name, Email and Phone are recognised and sent as the enquirer's details;
 * everything else is a question, and its answer becomes a line of the message.
 */
function ezbizi_form_shortcode($atts) {
    $atts = shortcode_atts(array(
        'fields' => 'Name,Email,Phone,How can we help?',
        'button' => 'Send',
        'thanks' => 'Thank you — we will be in touch shortly.',
    ), $atts, 'ezbizi_form');

    $key = ezbizi_public_key();

    if ($key === '') {
        // Never a raw error on somebody's public page.
        return current_user_can('manage_options')
            ? '<p>' . esc_html__('EzBizi: this site has no browser key yet. Open Settings › EzBizi.', 'ezbizi') . '</p>'
            : '';
    }

    $labels = array_filter(array_map('trim', explode(',', $atts['fields'])));

    if (empty($labels)) {
        return '';
    }

    $id = 'ezbizi-form-' . wp_rand(1000, 9999);
    $out = '<form class="ezbizi-form" id="' . esc_attr($id) . '" novalidate>';

    foreach ($labels as $label) {
        $slug = sanitize_title($label);
        $type = 'text';
        $lower = strtolower($label);

        if ($lower === 'email') {
            $type = 'email';
        } elseif ($lower === 'phone') {
            $type = 'tel';
        }

        $out .= '<p><label for="' . esc_attr($id . '-' . $slug) . '">' . esc_html($label) . '</label><br />';

        // A long question gets a box to answer it in.
        if (strlen($label) > 20) {
            $out .= '<textarea id="' . esc_attr($id . '-' . $slug) . '" name="' . esc_attr($label)
                . '" rows="4" style="width:100%"></textarea>';
        } else {
            $out .= '<input type="' . esc_attr($type) . '" id="' . esc_attr($id . '-' . $slug) . '" name="'
                . esc_attr($label) . '" style="width:100%" />';
        }

        $out .= '</p>';
    }

    $out .= '<p><button type="submit">' . esc_html($atts['button']) . '</button></p>';
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
  Array.prototype.forEach.call(f.querySelectorAll('input,textarea'),function(i){
    var n=i.name,low=n.toLowerCase();
    if(low==='name'){v.name=i.value;}
    else if(low==='email'){v.email=i.value;}
    else if(low==='phone'){v.phone=i.value;}
    else {fields[n]=i.value;}
    if(low==='name'||low==='email'||low==='phone'){fields[n]=i.value;}
  });
  v.key=" . wp_json_encode($key) . ";v.fields=fields;v.page_url=location.href;
  fetch(" . wp_json_encode(ezbizi_api_base() . '/api/v1/web/form') . ",{
    method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},
    body:JSON.stringify(v)
  }).then(function(r){
    if(!r.ok)throw new Error(r.status);
    f.reset();note.textContent=" . wp_json_encode($atts['thanks']) . ";
  }).catch(function(){
    note.textContent='Sorry — that did not send. Please try again.';
  }).then(function(){b.disabled=false;});
});
})();</script>";

    return $out;
}

/**
 * [ezbizi_chat] — a chat bubble that opens a conversation in EzBizi.
 *
 * ── Why it polls ──
 *
 * A reply reaches the visitor because the widget asks again, not because
 * anything is pushed. Real-time would mean a websocket server: a daemon, a
 * port, and a scaling story, for a feature nobody is using yet.
 */
function ezbizi_chat_shortcode($atts) {
    $atts = shortcode_atts(array(
        'title'  => 'Chat with us',
        'intro'  => 'Ask us anything — we will reply here.',
        'button' => 'Chat',
    ), $atts, 'ezbizi_chat');

    $key = ezbizi_public_key();

    if ($key === '') {
        return current_user_can('manage_options')
            ? '<p>' . esc_html__('EzBizi: this site has no browser key yet. Open Settings › EzBizi.', 'ezbizi') . '</p>'
            : '';
    }

    $id = 'ezbizi-chat-' . wp_rand(1000, 9999);

    $out = '<div class="ezbizi-chat" id="' . esc_attr($id) . '">'
        . '<button type="button" class="ezbizi-chat-open">' . esc_html($atts['button']) . '</button>'
        . '<div class="ezbizi-chat-panel" hidden>'
        . '<strong>' . esc_html($atts['title']) . '</strong>'
        . '<p class="ezbizi-chat-intro">' . esc_html($atts['intro']) . '</p>'
        . '<div class="ezbizi-chat-log" role="log" aria-live="polite"></div>'
        . '<form class="ezbizi-chat-form">'
        . '<input type="text" class="ezbizi-chat-text" placeholder="Type a message" style="width:100%" />'
        . '<button type="submit">Send</button>'
        . '</form>'
        . '</div></div>';

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
    p.className='ezbizi-chat-'+(m.from==='you'?'you':'them');
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
    // every visitor's session is a request per visitor per few seconds, for
    // nothing anybody is looking at.
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
