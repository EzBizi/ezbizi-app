<?php
/**
 * The website's own Elementor forms, passed on to EzBizi as leads.
 *
 * Jose, 2026-09-28: the sites' contact forms should arrive in Leads, like the
 * EzBizi form does. They are Elementor Pro forms that email the business; that
 * email still goes, and a copy of the enquiry now also becomes a lead.
 *
 * ── Why from the server, and after the visitor has their answer ──
 *
 * Elementor has already validated the submission (required fields, the spam
 * trap) when `new_record` fires, and the visitor is waiting for its thank-you.
 * The call to EzBizi waits for `shutdown`, after the response has been flushed,
 * so a slow EzBizi never slows a customer's form down.
 *
 * It authenticates with this site's token, not the public key: every one of
 * these comes from the web host's one address, and the public form endpoint
 * limits per address.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('elementor_pro/forms/new_record', 'ezbizi_elementor_form_record', 20, 2);

/*
 * ── A form whose email fails still thanks the visitor ──
 *
 * The hosting refused these sites' mail for weeks (2026-09), so Elementor's
 * Email step failed on every enquiry and told each visitor "Your submission
 * failed because of a server error" — while the enquiry reached EzBizi Leads
 * all along. People sent again, and some surely gave up. On a site connected
 * to EzBizi, Leads is where the enquiry lands, so a failed email is not a
 * failed submission: the email is still tried, and the answer is "sent".
 */
add_filter('pre_wp_mail', 'ezbizi_form_mail', 10, 2);

function ezbizi_form_mail($short, $atts) {
    // phpcs:ignore WordPress.Security.NonceVerification -- only reads which action this request is
    if ($short !== null || ! wp_doing_ajax() || (isset($_POST['action']) ? $_POST['action'] : '') !== 'elementor_pro_forms_send_form'
        || ezbizi_settings()['token'] === '') {
        return $short;
    }

    remove_filter('pre_wp_mail', 'ezbizi_form_mail', 10);
    wp_mail($atts['to'], $atts['subject'], $atts['message'], $atts['headers'], $atts['attachments']);
    add_filter('pre_wp_mail', 'ezbizi_form_mail', 10, 2);

    return true;
}

/*
 * ── The robot check on every Elementor form ──
 *
 * Jose, 2026-09-30, after A-1's forms took a night of Russian spam straight
 * into Leads: "not a robot verification on the forms". A proof of work: the
 * visitor's browser fetches a signed puzzle from this site and finds the
 * number behind a hash (about a second, while they type), and a submission
 * without the answer is refused before Elementor emails anybody or hands the
 * enquiry to EzBizi. Nothing to click, no Google or Cloudflare account, and a
 * bot that posts straight at admin-ajax — the kind that sent these — never
 * gets through. The same scheme as the EzBizi form's CAPTCHA (FormChallenge).
 *
 * A person typing spam by hand still gets through; no CAPTCHA stops that.
 */
const EZBIZI_POW_MAX = 50000;

add_action('rest_api_init', function () {
    // POST, so the page cache in front of the site never serves one puzzle twice.
    register_rest_route('ezbizi/v1', '/challenge', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'ezbizi_challenge',
    ));
});

function ezbizi_challenge() {
    $salt = bin2hex(random_bytes(12)) . '?expires=' . (time() + 1800);
    $challenge = hash('sha256', $salt . random_int(0, EZBIZI_POW_MAX));

    return array('salt' => $salt, 'challenge' => $challenge, 'max' => EZBIZI_POW_MAX,
        'signature' => hash_hmac('sha256', $challenge, wp_salt('auth')));
}

/** Signed by this site, not expired, solved, and not used before. */
function ezbizi_challenge_passes($raw) {
    $s = json_decode((string) $raw, true);

    if (! is_array($s) || ! isset($s['salt'], $s['number'], $s['challenge'], $s['signature'])) {
        return false;
    }

    $salt = (string) $s['salt'];
    $number = (string) $s['number'];
    $challenge = (string) $s['challenge'];

    if (! hash_equals(hash_hmac('sha256', $challenge, wp_salt('auth')), (string) $s['signature'])
        || ! preg_match('/\?expires=(\d+)$/', $salt, $m) || (int) $m[1] < time()
        || ! ctype_digit($number) || ! hash_equals($challenge, hash('sha256', $salt . $number))) {
        return false;
    }

    // ponytail: a transient, not an atomic claim; two posts in the same instant could share one.
    $spent = 'ezbizi_pow_' . substr($challenge, 0, 32);

    if (get_transient($spent)) {
        return false;
    }

    set_transient($spent, 1, 1800);

    return true;
}

add_action('elementor_pro/forms/validation', function ($record, $handler) {
    if (! is_object($handler) || ! method_exists($handler, 'add_error')) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification -- Elementor verified the form
    if (! ezbizi_challenge_passes(isset($_POST['ezbizi_pow']) ? wp_unslash($_POST['ezbizi_pow']) : '')) {
        $message = __('We could not confirm you are a person. Please send the form again.', 'ezbizi');
        $handler->add_error('ezbizi_pow', $message);
        $handler->add_error_message($message);
    }
}, 5, 2);

/*
 * The browser half: solve on the first touch of a form, and hold a submit
 * until the answer is in. Only where Elementor Pro can draw a form.
 */
add_action('wp_footer', function () {
    if (! defined('ELEMENTOR_PRO_VERSION')) {
        return;
    }

    echo "<script>(function(){
var api=" . wp_json_encode(esc_url_raw(rest_url('ezbizi/v1/challenge'))) . ";
function hex(b){return Array.prototype.map.call(new Uint8Array(b),function(x){return('0'+x.toString(16)).slice(-2);}).join('');}
function solve(c){var enc=new TextEncoder(),n=0;
  function step(){var batch=[];for(var k=0;k<500&&n+k<=c.max;k++)batch.push(n+k);
    return Promise.all(batch.map(function(x){return crypto.subtle.digest('SHA-256',enc.encode(c.salt+x)).then(function(h){return hex(h)===c.challenge?x:-1;});}))
    .then(function(rs){var hit=rs.filter(function(x){return x>=0;});
      if(hit.length)return JSON.stringify({salt:c.salt,number:hit[0],challenge:c.challenge,signature:c.signature});
      n+=500;if(n>c.max)throw new Error('pow');return step();});}
  return step();}
function prep(f){if(f._ezPow)return f._ezPow;
  f._ezPow=fetch(api,{method:'POST',credentials:'same-origin'}).then(function(r){return r.json();}).then(solve).then(function(a){
    var i=f.querySelector('input[name=ezbizi_pow]');if(!i){i=document.createElement('input');i.type='hidden';i.name='ezbizi_pow';f.appendChild(i);}i.value=a;});
  f._ezPow.catch(function(){f._ezPow=null;});return f._ezPow;}
function form(e){return e.target&&e.target.closest?e.target.closest('form.elementor-form'):null;}
document.addEventListener('focusin',function(e){var f=form(e);if(f)prep(f);},true);
document.addEventListener('submit',function(e){var f=form(e);if(!f)return;
  var i=f.querySelector('input[name=ezbizi_pow]');
  // An answer is spent by one submission; the next one solves again.
  if((i&&i.value)||f._ezGo){f._ezGo=0;setTimeout(function(){if(i)i.value='';f._ezPow=null;prep(f);},0);return;}
  e.preventDefault();e.stopImmediatePropagation();
  // One send per wait: a second click while it solves must not queue a second enquiry.
  if(f._ezWait)return;f._ezWait=1;
  function go(){f._ezWait=0;f._ezGo=1;f.requestSubmit?f.requestSubmit():f.dispatchEvent(new Event('submit',{cancelable:true,bubbles:true}));}
  // Unsolvable here (no network, an old browser): send anyway, so the visitor sees the site's answer, never silence.
  prep(f).then(go,go);
},true);
})();</script>";
}, 20);

/**
 * @param object $record  Elementor's form record: `get('fields')`, `get('form_settings')`
 */
function ezbizi_elementor_form_record($record, $handler = null) {
    $settings = ezbizi_settings();

    if ($settings['token'] === '' || ! is_object($record) || ! method_exists($record, 'get')) {
        return;
    }

    $lead = ezbizi_elementor_form_lead((array) $record->get('fields'));

    if ($lead === null) {
        return;
    }

    $lead['host'] = ezbizi_host();
    $lead['page_url'] = isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) // phpcs:ignore WordPress.Security.NonceVerification -- Elementor verified the form
        : (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '');
    $url = rtrim($settings['api'], '/') . '/api/v1/web/plugin/form';
    $token = $settings['token'];

    add_action('shutdown', function () use ($url, $token, $lead) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort flush
        }

        wp_remote_post($url, array(
            'timeout' => 10,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
            'body' => $lead,
        ));
    });
}

/**
 * An Elementor form's fields as EzBizi's form body, or null when nothing was
 * filled in. The question labels are kept as asked, and the name, email and
 * phone are picked out the way the EzBizi form picks them.
 *
 * @param array<string, array{type?: string, title?: string, value?: mixed, id?: string}> $fields
 * @return array<string, mixed>|null
 */
function ezbizi_elementor_form_lead(array $fields) {
    $out = array('fields' => array());
    $first = '';
    $last = '';

    foreach ($fields as $id => $field) {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';

        // Machinery, not answers.
        if (in_array($type, array('recaptcha', 'recaptcha_v3', 'honeypot', 'step', 'html', 'acceptance'), true)) {
            continue;
        }

        $value = isset($field['value']) ? $field['value'] : '';
        $value = trim(is_array($value) ? implode(', ', $value) : (string) $value);
        $label = isset($field['title']) && trim((string) $field['title']) !== '' ? trim((string) $field['title']) : (string) $id;
        $low = strtolower($label . ' ' . $id);

        if (count($out['fields']) < 40) {
            $out['fields'][mb_substr($label, 0, 120)] = mb_substr($value, 0, 4000);
        }

        if ($value === '') {
            continue;
        }

        if ($type === 'email' && ! isset($out['email']) && is_email($value)) {
            $out['email'] = $value;
        } elseif (($type === 'tel' || strpos($low, 'phone') !== false) && ! isset($out['phone'])) {
            $out['phone'] = mb_substr($value, 0, 40);
        } elseif (strpos($low, 'first') !== false && strpos($low, 'name') !== false) {
            $first = $value;
        } elseif (strpos($low, 'last') !== false && strpos($low, 'name') !== false) {
            $last = $value;
        } elseif (strpos($low, 'name') !== false && ! isset($out['name'])) {
            $out['name'] = mb_substr($value, 0, 191);
        }
    }

    if (! isset($out['name']) && ($first !== '' || $last !== '')) {
        $out['name'] = mb_substr(trim($first . ' ' . $last), 0, 191);
    }

    return array_filter($out['fields'], 'strlen') === array() ? null : $out;
}
