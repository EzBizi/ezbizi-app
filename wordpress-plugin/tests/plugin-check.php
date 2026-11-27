<?php
/**
 * Runs the real plugin against a stub WordPress, and asserts what it does.
 *
 * ── Why this exists rather than a WordPress install ──
 *
 * Until this file, nothing had ever EXECUTED ezbizi.php. The server-side suite
 * covers EzBizi's end thoroughly — the token check, the zip, the payload shape —
 * and none of it loads one line of the plugin. A wordpress.org reviewer would
 * have been the first person to run it, which is the wrong person to find a
 * fatal.
 *
 * A LOCAL WordPress is the wrong answer here: it needs ext-mysqli, which this
 * PHP does not have, and adding it means editing the single php.ini shared by
 * every session working this tree — a typo there stops PHP for all of them.
 *
 * ── There IS a real WordPress, and it is not this file's job ──
 *
 * https://playground.wordpress.net runs WordPress in the browser on WASM, with
 * no database server and nothing installed. wordpress.org hands you a Playground
 * link that boots your submitted zip alongside the Plugin Check plugin. On
 * 2026-08-28 that was used to confirm what this file cannot: the plugin
 * ACTIVATES on WordPress 7.1 with no fatal, its settings screen renders, and
 * ezbizi_host() correctly reported `playground.wordpress.net` from a live
 * home_url(). Plugin Check itself passed with every category enabled — and on
 * 2026-08-31 the directory approved it and 1.0.0 then 1.0.1 shipped from that
 * same package.
 *
 * Use it whenever this plugin changes materially. But it is a manual check: it
 * proves the plugin worked once, in one browser, and proves nothing about the
 * next commit. That is the division of labour — Playground for "does WordPress
 * accept it", this file for "does it still behave", on every run of the gate.
 *
 * ── What it does and does not cover ──
 *
 * COVERS: the file loads without fatal, shortcodes register from the SERVER's
 * field list, per-page overrides beat site-wide values, a failed API call never
 * blanks a live page, failures are cached so a revoked token cannot turn a busy
 * site into a load generator, the stale-while-revalidate path does not block,
 * the hour ceiling does block, and a blank token field keeps the saved token.
 *
 * COVERS, since 1.12.0: that a chat whose config block is ABSENT reaches a
 * page by neither route — the entire mechanism behind the chat switch on the
 * customer's screen, and a behaviour nothing had ever asserted — and the five
 * presentation widgets: a preset name that does not exist rendering the default
 * rather than nothing, the hours table falling back to the hours line, the map
 * degrading twice, and each of them escaping what a customer stored.
 *
 * DOES NOT COVER: that WordPress itself loads the plugin, admin screen markup,
 * or real HTTP. Those need a real install; the failures they would catch are
 * shallower than the ones above.
 *
 * Run:  php wordpress-plugin/tests/plugin-check.php
 * Exits non-zero on the first failure, so CI and the gate can read it.
 */

define('ABSPATH', __DIR__);

// ── The stub WordPress ───────────────────────────────────────────

$GLOBALS['opt'] = [];
$GLOBALS['tr'] = [];
$GLOBALS['sc'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['scheduled'] = [];
$GLOBALS['http_calls'] = 0;
$GLOBALS['http_next'] = null;

// As WordPress does, a stored option passes through its option_{name} filters.
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opt']) ? apply_filters("option_$k", $GLOBALS['opt'][$k]) : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opt'][$k]); return true; }

/** Transients honour expiry, because the whole caching design turns on it. */
function get_transient($k) {
    if (! isset($GLOBALS['tr'][$k])) { return false; }
    [$value, $expires] = $GLOBALS['tr'][$k];
    if ($expires !== 0 && $expires < time()) { unset($GLOBALS['tr'][$k]); return false; }
    return $value;
}
function set_transient($k, $v, $ttl = 0) { $GLOBALS['tr'][$k] = [$v, $ttl ? time() + $ttl : 0]; return true; }
function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }

function add_shortcode($tag, $cb) { $GLOBALS['sc'][$tag] = $cb; }
function do_shortcode($s) { return preg_replace_callback('/\[([a-z0-9_]+)\]/', fn ($m) => isset($GLOBALS['sc'][$m[1]]) ? $GLOBALS['sc'][$m[1]]([]) : $m[0], (string) $s); }
function shortcode_atts($pairs, $atts, $sc = '') {
    $atts = (array) $atts;
    $out = [];
    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $out;
}
function add_action($hook, $cb, $p = 10, $a = 1) { $GLOBALS['actions'][$hook][] = $cb; }
function do_action($hook, ...$args) {
    foreach ($GLOBALS['actions'][$hook] ?? [] as $cb) { $cb(...$args); }
}
// The checks below read each widget's script from its shortcode's own output,
// which is what a render after the footer gives (ezbizi_late_script).
function did_action($hook) { return $GLOBALS['did_action'][$hook] ?? 1; }
function wp_schedule_single_event($ts, $hook) { $GLOBALS['scheduled'][] = $hook; return true; }
function wp_clear_scheduled_hook($hook) { return true; }

function wp_remote_get($url, $args = []) {
    $GLOBALS['http_calls']++;
    $GLOBALS['last_url'] = $url;
    $GLOBALS['last_args'] = $args;
    return $GLOBALS['http_next'];
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['code'] ?? 0) : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
class WP_Error {}

/*
 * Takes a path, because the city list builds a town's address from the page
 * path the server sent. A call with no argument answers exactly as it did, so
 * ezbizi_host() and everything else that asks for the site itself is unmoved.
 */
function home_url($path = '') { return ($GLOBALS['home'] ?? 'https://a-1chimney.com').$path; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
// WordPress lets tel:, sms: and mailto: through esc_url; so does this.
function esc_url($s) { return filter_var((string) $s, FILTER_VALIDATE_URL) || preg_match('/^(tel|sms|mailto):[^\s"<>]+$/i', (string) $s) ? (string) $s : ''; }
function esc_url_raw($s) { return (string) $s; }
function is_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? $s : false; }
function register_setting($g, $n, $a = []) { $GLOBALS['settings'][$n] = $a; }
function add_filter($hook, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$hook][] = $cb; }
function remove_filter($hook, $cb, $p = 10) { $GLOBALS['filters'][$hook] = array_values(array_filter($GLOBALS['filters'][$hook] ?? [], fn ($x) => $x !== $cb)); return true; }
function wp_doing_ajax() { return ! empty($GLOBALS['doing_ajax']); }
function wp_mail($to, $subject, $message, $headers = '', $attachments = []) { $GLOBALS['mail_calls'] = ($GLOBALS['mail_calls'] ?? 0) + 1; return $GLOBALS['mail_ok'] ?? true; }
function apply_filters($hook, $value) {
    foreach ($GLOBALS['filters'][$hook] ?? [] as $cb) { $value = $cb($value); }
    return $value;
}
function plugin_basename($f) { return 'ezbizi/ezbizi.php'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/'.$p; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
function human_time_diff($from, $to = 0) {
    $d = abs(($to ?: time()) - $from);

    return $d < 3600 ? intdiv($d, 60).' mins' : ($d < 86400 ? intdiv($d, 3600).' hours' : intdiv($d, 86400).' days');
}
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
if (! function_exists('date_i18n')) { function date_i18n($f, $ts) { return date($f, $ts); } }
if (! function_exists('wpautop')) { function wpautop($s) { return '<p>' . $s . '</p>'; } }
if (! function_exists('_n')) { function _n($one, $many, $n, $d = null) { return $n == 1 ? $one : $many; } }
/*
 * Records instead of discarding, because the menu is the thing being asserted.
 * `add_options_page` is gone with the page that used it: a stub for a function
 * the plugin no longer calls is a check that can never fail.
 */
function add_menu_page($page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null) {
    $GLOBALS['menu'][] = compact('page_title', 'menu_title', 'cap', 'slug', 'cb', 'icon', 'pos');
}
function current_user_can() { return $GLOBALS['is_admin'] ?? true; }

/*
 * The loop, or the absence of one. `post_id` false is a footer, an archive or
 * a 404 — every place [ezbizi_post_title] must answer with nothing rather than
 * with the title of whatever WordPress set up last.
 */
function get_the_ID() { return $GLOBALS['post_id'] ?? false; }
function get_the_title($id = 0) { return $GLOBALS['post_title'] ?? ''; }

/** WordPress's own zone, which the hours table falls back to. */
function wp_timezone() { return new DateTimeZone($GLOBALS['wp_tz'] ?? 'UTC'); }
function settings_fields() {}
function submit_button() {}
function wp_nonce_field() {}
function check_admin_referer() { return true; }

// ── Enough WordPress for the service account ────────────────────

function register_deactivation_hook($f, $cb) { $GLOBALS['deactivate'][] = $cb; }
function wp_rand($a = 0, $b = 0) { return 1234; }
function sanitize_title($t) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $t)); }
function wp_json_encode($v) { return json_encode($v); }
function get_rest_url() { return 'https://a-1chimney.com/wp-json/'; }
function wp_generate_password($len = 12, $special = true, $extra = false) {
    return substr(str_repeat('x7Kq', 32), 0, $len);
}

class WP_User {
    public $ID;
    public $user_login;
    public function __construct($id, $login) { $this->ID = $id; $this->user_login = $login; }
}

function get_user_by($field, $value) {
    foreach ($GLOBALS['users'] as $user) {
        if ($field === 'login' && $user->user_login === $value) { return $user; }
        if ($field === 'id' && $user->ID === $value) { return $user; }
    }
    return false;
}

function wp_insert_user($args) {
    if (! empty($GLOBALS['insert_user_fails'])) { return new WP_Error(); }
    $id = count($GLOBALS['users']) + 2;
    $user = new WP_User($id, $args['user_login']);
    $GLOBALS['users'][] = $user;
    $GLOBALS['roles_assigned'][$id] = $args['role'];
    return $id;
}

class EzbiziStubRole {
    public $caps = [];
    public function add_cap($c) { $this->caps[$c] = true; }
    public function remove_cap($c) { unset($this->caps[$c]); }
}

function add_role($slug, $label, $caps) {
    $role = new EzbiziStubRole();
    foreach ($caps as $cap => $granted) { if ($granted) { $role->caps[$cap] = true; } }
    $GLOBALS['roles'][$slug] = $role;
}
function get_role($slug) { return $GLOBALS['roles'][$slug] ?? null; }

/*
 * Both halves of the real gate, so the harness can tell "this request is not
 * SSL" from "the site owner switched application passwords off" -- which is the
 * distinction the plugin now draws and the reason the pairing was silently
 * never happening on ordinary HTTPS sites.
 */
function is_ssl() { return $GLOBALS['is_ssl'] ?? false; }
function wp_get_environment_type() { return $GLOBALS['env_type'] ?? 'production'; }

function wp_is_application_passwords_available() {
    return (bool) apply_filters(
        'wp_is_application_passwords_available',
        is_ssl() || 'local' === wp_get_environment_type(),
    );
}

class WP_Application_Passwords {
    public static function create_new_application_password($user_id, $args) {
        $GLOBALS['app_passwords'][] = ['user' => $user_id, 'uuid' => 'uuid-1', 'name' => $args['name']];
        return ['plain-text-secret', ['uuid' => 'uuid-1']];
    }
    public static function delete_application_password($user_id, $uuid) {
        foreach ($GLOBALS['app_passwords'] as $i => $p) {
            if ($p['uuid'] === $uuid) { unset($GLOBALS['app_passwords'][$i]); }
        }
        $GLOBALS['app_passwords'] = array_values($GLOBALS['app_passwords']);
        return true;
    }
}

function wp_remote_post($url, $args = []) {
    $GLOBALS['post_calls']++;
    $GLOBALS['last_post_url'] = $url;
    $GLOBALS['last_post_args'] = $args;
    return $GLOBALS['post_next'];
}

$GLOBALS['users'] = [];
$GLOBALS['roles'] = [];
$GLOBALS['roles_assigned'] = [];
$GLOBALS['app_passwords'] = [];
$GLOBALS['deactivate'] = [];
$GLOBALS['post_calls'] = 0;
$GLOBALS['post_next'] = ['code' => 200, 'body' => '{}'];

require_once __DIR__.'/../ezbizi/ezbizi.php';

// ── Harness ──────────────────────────────────────────────────────

$failures = 0;
$checks = 0;

function check($what, $got, $want) {
    global $failures, $checks;
    $checks++;
    if ($got === $want) { echo "  ok    {$what}\n"; return; }
    $failures++;
    echo "  FAIL  {$what}\n        want: ".var_export($want, true)."\n        got:  ".var_export($got, true)."\n";
}

/**
 * A payload whose field list this build of the plugin has never seen.
 *
 * `tagline` is a field added to EzBizi after this plugin shipped; `template_1`
 * is text a customer wrote once for the whole site; `about_us_field_1` is a
 * paragraph they invented for one page. None of the three exists anywhere in
 * the plugin's source, which is the point.
 */
function invented_payload() {
    return json_encode([
        'site' => ['host' => 'a-1chimney.com', 'label' => null, 'company' => 'A-1'],
        'fields' => [
            ['key' => 'phone', 'label' => 'Phone number', 'hint' => '', 'shortcode' => '[ezbizi_phone]'],
            ['key' => 'tagline', 'label' => 'Tagline', 'hint' => '', 'shortcode' => '[ezbizi_tagline]'],
            ['key' => 'template_1', 'label' => 'Template 1', 'hint' => '', 'shortcode' => '[ezbizi_template_1]'],
            ['key' => 'about_us_field_1', 'label' => 'About us - field 1', 'hint' => '', 'shortcode' => '[ezbizi_about_us_field_1]'],
        ],
        'values' => [
            'phone' => '555-0100',
            'tagline' => 'Not just our name.',
            'template_1' => 'Licensed and insured since 1974.',
        ],
        'pages' => [
            '/about-us' => ['about_us_field_1' => 'We have swept chimneys since 1974.'],
        ],
    ]);
}

function payload_json(array $values, array $pages = []) {
    return json_encode([
        'site' => ['host' => 'a-1chimney.com', 'label' => null, 'company' => 'A-1'],
        'fields' => [
            ['key' => 'phone', 'label' => 'Phone number', 'hint' => '', 'shortcode' => '[ezbizi_phone]'],
            ['key' => 'booking_url', 'label' => 'Booking link', 'hint' => '', 'shortcode' => '[ezbizi_booking_url]'],
        ],
        'values' => $values,
        'pages' => $pages,
    ]);
}

function reset_site() {
    $GLOBALS['opt'] = ['ezbizi_token' => 'ezb_test', 'ezbizi_api_base' => 'https://app.ezbizi.com'];
    $GLOBALS['tr'] = [];
    $GLOBALS['scheduled'] = [];
    $GLOBALS['http_calls'] = 0;
    $GLOBALS['post_calls'] = 0;
    $GLOBALS['post_next'] = ['code' => 200, 'body' => '{}'];
    $GLOBALS['users'] = [];
    $GLOBALS['roles'] = [];
    $GLOBALS['roles_assigned'] = [];
    $GLOBALS['app_passwords'] = [];
    // A cron or CLI request, which is where the pairing actually runs and where
    // is_ssl() is false on a perfectly ordinary HTTPS site.
    $GLOBALS['is_ssl'] = false;
    $GLOBALS['env_type'] = 'production';
    // A refusal one case dates must not decide the next one.
    delete_option(EZBIZI_REFUSED_SINCE);

    /*
     * And nor must who was looking. `is_admin` was the one piece of state
     * reset_site() did not clear, so a section that set it false leaked into
     * the next section's notice check — which is a test failing because of a
     * test rather than because of the plugin.
     */
    $GLOBALS['is_admin'] = true;
    // A filter one case registers must not decide the next one.
    unset($GLOBALS['filters']['wp_is_application_passwords_available']);
    $GLOBALS['home'] = 'https://a-1chimney.com';
    $_SERVER['REQUEST_URI'] = '/';
    // No post in scope and no site zone, so a case that wants either says so.
    $GLOBALS['post_id'] = false;
    $GLOBALS['post_title'] = '';
    $GLOBALS['wp_tz'] = 'UTC';
}

/** The same payload with the chat placed inline rather than floating. */
function widget_payload_at($position) {
    $p = json_decode(widget_payload(), true);
    $p['widgets']['chat']['position'] = $position;

    return json_encode($p);
}

function widget_payload($enabled = true) {
    $base = json_decode(payload_json(['phone' => '555-0100']), true);
    $base['public_key'] = 'pk_testkey';
    $base['turnstile'] = ['site' => '0xTESTSITE', 'secret' => '0xTESTSECRET'];
    $base['widgets'] = [
        'chat' => [
            'enabled' => $enabled,
            'title' => 'Talk to A-1',
            'intro' => 'We answer fast.',
            'button' => 'Chat',
            'placeholder' => 'Type here',
            'send' => 'Send',
            'position' => 'bottom-right',
        ],
        'form' => [
            'enabled' => $enabled,
            'button' => 'Send it',
            'thanks' => 'Got it.',
            'error' => 'Did not send.',
            'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => true],
                ['label' => 'What needs doing?', 'type' => 'textarea', 'required' => true],
                ['label' => 'Service', 'type' => 'select', 'required' => false,
                    'options' => ['Sweep', 'Repair']],
                ['label' => 'What is broken?', 'type' => 'text',
                    'show_if' => ['field' => 'Service', 'value' => 'Repair']],
                ['label' => 'Photo', 'type' => 'file'],
            ],
        ],
        'style' => [
            'accent' => '#aa0000', 'on_accent' => '#ffffff', 'text' => '#111111',
            'background' => '#ffffff', 'font' => 'inherit', 'radius' => 12,
        ],
    ];

    return json_encode($base);
}

function ok_response(array $values, array $pages = []) {
    $GLOBALS['http_next'] = ['code' => 200, 'body' => payload_json($values, $pages)];
}

echo "\nThe host must be spelled the way the server spells it\n";
$GLOBALS['home'] = 'https://WWW.A-1Chimney.com/';
// Bar, 2026-11-18: a re-signed avatar link alone is not a change; a new photo is.
$sig = fn ($v, $e, $s) => ['fetched_at' => 1, 'widgets' => ['chat' => ['team' => [['avatar' => "https://app.ezbizi.com/api/v1/web/chat/avatar/u1?expires=$e&signature=$s&v=$v"]]]]];
check('a re-signed link is not a change', ezbizi_payload_differs($sig('a', 1, 'x'), $sig('a', 2, 'y')), false);
check('a new photo is a change', ezbizi_payload_differs($sig('a', 1, 'x'), $sig('b', 1, 'x')), true);
// 2026-11-19: a site filter that patches the stored payload (a1g-town-phones.php) is not a change.
$raw = ['fetched_at' => 1, 'pages' => ['/a' => ['phone' => '1']]];
update_option(EZBIZI_LAST_GOOD, $raw);
update_option(EZBIZI_LAST_GOOD . '_fp', ezbizi_payload_fingerprint($raw));
$patch = function ($p) { $p['pages']['/b'] = ['phone' => '2']; return $p; };
add_filter('option_' . EZBIZI_LAST_GOOD, $patch);
check('a patched stored payload is not a change', ezbizi_payload_changed(['fetched_at' => 2] + $raw), true === false);
check('a real value change is still a change', ezbizi_payload_changed(['fetched_at' => 2, 'pages' => ['/a' => ['phone' => '9']]]), true);
remove_filter('option_' . EZBIZI_LAST_GOOD, $patch);
delete_option(EZBIZI_LAST_GOOD); delete_option(EZBIZI_LAST_GOOD . '_fp');
check('www stripped, lowercased', ezbizi_host(), 'a-1chimney.com');
$GLOBALS['home'] = 'https://nj.a-1chimney.com';
check('a subdomain is its own host', ezbizi_host(), 'nj.a-1chimney.com');
$GLOBALS['home'] = 'https://a-1chimney.com';

echo "\nShortcodes come from the server's field list\n";
reset_site();
ok_response(['phone' => '555-0100', 'booking_url' => 'https://book.example.com']);
do_action('init');
check('[ezbizi_phone] registered', isset($GLOBALS['sc']['ezbizi_phone']), true);
check('[ezbizi_rating] registered', isset($GLOBALS['sc']['ezbizi_rating']), true);
$GLOBALS['opt']['ezbizi_last_good'] = ['review_summary' => ['count' => 27377, 'average' => 4.9]];
set_transient(EZBIZI_CACHE_KEY, ['review_summary' => ['count' => 27377, 'average' => 4.9]], 300);
check('[ezbizi_rating] prints the real numbers', strpos($GLOBALS['sc']['ezbizi_rating']([]), '<span itemprop="reviewCount">27,377</span>') !== false, true);
check('JSON-LD rating follows the payload', ezbizi_fix_rating_jsonld('<script type="application/ld+json">{"aggregateRating":{"ratingValue":"4.1","reviewCount":"3218"}}</script><p>"reviewCount":5</p>'), '<script type="application/ld+json">{"aggregateRating":{"ratingValue":"4.9","reviewCount":"27377"}}</script><p>"reviewCount":5</p>');
$ld = ezbizi_fix_rating_jsonld('<script type="application/ld+json">{"aggregateRating":{"reviewCount": 6763, "bestRating": "5", "ratingValue": 4.8},"n":["6,763"]}</script>');
check('JSON-LD bare reviewCount keeps its separator and stays valid JSON', json_decode(substr($ld, 35, -9), true)['aggregateRating'] ?? null, ['reviewCount' => 27377, 'bestRating' => '5', 'ratingValue' => 4.9]);
set_transient('ezbizi_ba_12', array());

set_transient('ezbizi_ba_12', array(array('label' => 'Crown rebuild', 'city' => 'Hartford', 'state' => 'CT', 'before' => array('url' => 'https://x/b.jpg', 'alt' => ''), 'after' => array('url' => 'https://x/a.jpg', 'alt' => 'New crown'))));
$ba = $GLOBALS['sc']['ezbizi_before_after']([]);
check('[ezbizi_before_after] shows an approved inspection pair', strpos($ba, 'src="https://x/b.jpg"') !== false && strpos($ba, 'src="https://x/a.jpg"') !== false && strpos($ba, 'Crown rebuild') !== false, true);
set_transient('ezbizi_ba_12', array());
delete_transient(EZBIZI_CACHE_KEY); unset($GLOBALS['opt']['ezbizi_last_good']);
check('[ezbizi_booking_url] registered', isset($GLOBALS['sc']['ezbizi_booking_url']), true);
check('generic [ezbizi] registered', isset($GLOBALS['sc']['ezbizi']), true);
check('phone renders', $GLOBALS['sc']['ezbizi_phone']([]), '555-0100');
check('a url key is escaped as a url', $GLOBALS['sc']['ezbizi_booking_url']([]), 'https://book.example.com');
check('generic form reads a key', $GLOBALS['sc']['ezbizi'](['key' => 'phone']), '555-0100');
check('default fills an unset key', $GLOBALS['sc']['ezbizi'](['key' => 'phone', 'default' => 'x']), '555-0100');

echo "\nA page override beats the site value\n";
reset_site();
ok_response(['phone' => '555-0100'], ['/contact' => ['phone' => '555-0999']]);
ezbizi_payload(true);
$_SERVER['REQUEST_URI'] = '/contact';
check('override wins on its page', ezbizi_value('phone'), '555-0999');
$_SERVER['REQUEST_URI'] = '/contact/?utm_source=x';
check('trailing slash and query still match', ezbizi_value('phone'), '555-0999');
$_SERVER['REQUEST_URI'] = '/about';
check('another page gets the site value', ezbizi_value('phone'), '555-0100');

echo PHP_EOL . "A link is escaped as a link" . PHP_EOL;
/*
 * City link and State link end in `_link`, not `_url`, because that is what
 * they are called on the screen they were copied from. The escaping rule keyed
 * on `_url` alone, so a customer's civic links would have gone through
 * esc_html — which turns the `&` in a query string into `&amp;` and does
 * nothing about a `javascript:` scheme somebody stored.
 */
check('a _url key is a link', ezbizi_is_link_key('booking_url'), true);
check('a _link key is a link too', ezbizi_is_link_key('city_link'), true);
check('and so is the other one', ezbizi_is_link_key('state_link'), true);

// The control: if this returned true for everything, the three above would
// pass while every value on every site went through the wrong escaper.
check('a phone number is not a link', ezbizi_is_link_key('phone'), false);
check('nor is a tagline', ezbizi_is_link_key('tagline'), false);

echo PHP_EOL . "Two forms on one page are two forms" . PHP_EOL;
/*
 * Jose's reason for wanting the form as an Elementor widget: it goes in
 * several places on a site, and sometimes more than once on a page. The id was
 * a random number, so two of them could draw the same one — and then the second
 * form's script binds to the first form's element and one silently stops
 * working. One in nine thousand per pair, which is small until it is multiplied
 * by every page view of every site.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;

$first = $GLOBALS['sc']['ezbizi_form']([]);
$second = $GLOBALS['sc']['ezbizi_form']([]);

preg_match('/id="(ezbizi-form-[0-9]+)"/', $first, $a);
preg_match('/id="(ezbizi-form-[0-9]+)"/', $second, $b);

check('the first form is identified', isset($a[1]), true);
check('and so is the second', isset($b[1]), true);
check('and they are not the same form', ($a[1] ?? 'x') === ($b[1] ?? 'x'), false);

// The control: each one's script addresses ITS own id, which is the whole
// reason the ids have to differ.
check('each script addresses its own', str_contains($second, wp_json_encode($b[1] ?? '')), true);

echo PHP_EOL . "Keys this plugin has never heard of" . PHP_EOL;
/*
 * Jose asked whether the plugin had been updated for the new SEO fields, the
 * page text and the templates. It has not, and the answer to whether it needs
 * to be is here rather than in a sentence: the plugin registers a shortcode for
 * every key the server sends, so a field invented in EzBizi this morning is a
 * working shortcode this afternoon with nothing installed.
 *
 * None of the three keys below appears anywhere in this plugin's source.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => invented_payload()];
ezbizi_payload(true);

/*
 * Registration happens on `init`, from whatever payload exists at that moment
 * — which is a fact about the live plugin and not a quirk of this harness. A
 * field invented in EzBizi becomes a shortcode on the NEXT request after the
 * cache refreshes, not on the one that fetched it. Five minutes, not never,
 * and worth knowing before somebody reports that their new field "did nothing"
 * for a minute.
 */
do_action('init');

check('a field added to EzBizi after this release is registered', isset($GLOBALS['sc']['ezbizi_tagline']), true);
check('and renders', $GLOBALS['sc']['ezbizi_tagline']([]), 'Not just our name.');

// A template is site-wide: the same words wherever it is pasted.
$_SERVER['REQUEST_URI'] = '/anything-at-all';
check('a template renders on any page', $GLOBALS['sc']['ezbizi_template_1']([]), 'Licensed and insured since 1974.');

// A page's own text is the opposite: only where it belongs.
$_SERVER['REQUEST_URI'] = '/about-us';
check('a page field renders on its page', $GLOBALS['sc']['ezbizi_about_us_field_1']([]), 'We have swept chimneys since 1974.');

$_SERVER['REQUEST_URI'] = '/contact';
check('and nowhere else', $GLOBALS['sc']['ezbizi_about_us_field_1']([]), '');

// Text that names another value, as the texts imported from A1 do.
$named = json_decode(invented_payload(), true);
$named['fields'][] = ['key' => 'template_2', 'label' => 'Template 2', 'hint' => '', 'shortcode' => '[ezbizi_template_2]'];
$named['fields'][] = ['key' => 'template_3', 'label' => 'Template 3', 'hint' => '', 'shortcode' => '[ezbizi_template_3]'];
$named['values']['template_2'] = 'Call [ezbizi_phone] or see [gallery] & more';
$named['values']['template_3'] = 'Again [ezbizi_template_3]';
$named['values']['tagline'] = 'Call [ezbizi_phone]';
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($named)];
ezbizi_fetch();
do_action('init');
check('a template can name another value', $GLOBALS['sc']['ezbizi_template_2']([]), 'Call 555-0100 or see [gallery] &amp; more');
check('a template naming itself stops', substr_count($GLOBALS['sc']['ezbizi_template_3']([]), 'Again'), 4);
check('a plain field prints its words as written', $GLOBALS['sc']['ezbizi_tagline']([]), 'Call [ezbizi_phone]');
$GLOBALS['http_next'] = ['code' => 200, 'body' => invented_payload()];
ezbizi_fetch();
do_action('init');

/*
 * The control. Without it, "renders nothing elsewhere" would pass just as
 * happily on a plugin that had never registered the shortcode at all — which
 * is the failure being ruled out.
 */
check('the shortcode exists to be empty', isset($GLOBALS['sc']['ezbizi_about_us_field_1']), true);

$_SERVER['REQUEST_URI'] = '/';

echo "\nA failed API call must never blank a live page\n";
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_payload(true);
$GLOBALS['http_next'] = new WP_Error();
delete_transient(EZBIZI_CACHE_KEY);
$before = $GLOBALS['http_calls'];
check('last good value still renders', ezbizi_payload(true)['values']['phone'], '555-0100');
check('the failure was cached', get_transient(EZBIZI_CACHE_KEY) !== false, true);

reset_site();
$GLOBALS['http_next'] = ['code' => 401, 'body' => '{"message":"nope"}'];
check('a site that never connected renders nothing, not a crash', ezbizi_value('phone'), '');

echo "\nA visitor is not made to wait for us\n";
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_payload(true);
delete_transient(EZBIZI_CACHE_KEY);           // freshness window has passed
$GLOBALS['http_calls'] = 0;
$GLOBALS['scheduled'] = [];
$served = ezbizi_payload();
check('stale copy served', $served['values']['phone'], '555-0100');
check('no request on the visitor path', $GLOBALS['http_calls'], 0);
check('a refresh was scheduled instead', in_array('ezbizi_refresh', $GLOBALS['scheduled'], true), true);

$GLOBALS['scheduled'] = [];
ezbizi_payload();
check('it does not queue a second refresh', $GLOBALS['scheduled'], []);

echo "\nBut it does not serve something unbounded-stale\n";
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_payload(true);
delete_transient(EZBIZI_CACHE_KEY);
$last = get_option(EZBIZI_LAST_GOOD);
$last['fetched_at'] = time() - (EZBIZI_STALE_MAX + 60);   // a site with WP-Cron off
update_option(EZBIZI_LAST_GOOD, $last);
$GLOBALS['http_calls'] = 0;
ok_response(['phone' => '555-0200']);
check('past the ceiling it fetches', ezbizi_payload()['values']['phone'], '555-0200');
check('and that was a real request', $GLOBALS['http_calls'], 1);

echo "\nWhat we send, and what we do not\n";
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_payload(true);
check('the token goes in the header', $GLOBALS['last_args']['headers']['Authorization'], 'Bearer ezb_test');
check('the host is reported', str_contains($GLOBALS['last_url'], 'host=a-1chimney.com'), true);
check('the plugin version is reported', str_contains($GLOBALS['last_url'], 'plugin_version='.EZBIZI_VERSION), true);
$_SERVER['REQUEST_URI'] = '/a-visitor-page';
delete_transient(EZBIZI_CACHE_KEY);
ezbizi_payload(true);
check('the page being viewed is never sent', str_contains($GLOBALS['last_url'], 'a-visitor-page'), false);

echo "\nIt has its own place in the sidebar\n";
/*
 * Jose asked for this after installing the plugin and not finding it: A1 Tools
 * is a sidebar item, so the sidebar is where he looked. Nothing here fired
 * `admin_menu` before, which is how the page could sit in one menu while the
 * links and the notice assumed another, with every check still green.
 */
do_action('admin_menu');

// $item, not $menu: this file runs at global scope, where $menu IS
// $GLOBALS['menu'] — the obvious name silently overwrote the recording.
$item = $GLOBALS['menu'][0] ?? [];

check('one item, in the sidebar', count($GLOBALS['menu'] ?? []), 1);
check('under the slug everything links to', $item['slug'] ?? '', 'ezbizi');
check('only for somebody who could use it', $item['cap'] ?? '', 'manage_options');
check('drawing the settings form', $item['cb'] ?? '', 'ezbizi_settings_page');
check('with an icon, not a blank square', str_starts_with($item['icon'] ?? '', 'dashicons-'), true);

/*
 * WordPress names a screen after WHERE its menu item is, so the id the notice
 * stands down on is derived from the item registered above rather than typed
 * out again. Moving the page a second time now fails this file instead of
 * quietly nagging people who are standing on the form.
 */
$GLOBALS['own_screen'] = 'toplevel_page_' . ($item['slug'] ?? '');

echo "\nAn installed plugin that is doing nothing says so\n";
/*
 * Both halves of a real report: the plugin was installed on a-1chimney.com, no
 * menu appeared where the person looked, and EzBizi went on saying "waiting for
 * the plugin". Nothing was broken — the screen lives under Settings, and a
 * plugin with no token never calls home, so no connection was ever recorded.
 * But an inert plugin that says nothing is indistinguishable from a broken one,
 * and that is the defect these cover.
 */
reset_site();
$GLOBALS['opt']['ezbizi_token'] = '';
$GLOBALS['screen'] = null;

ob_start();
do_action('admin_notices');
$notice = ob_get_clean();

check('an unconnected site is told so', str_contains($notice, 'not connected yet'), true);
check('and given the way there', str_contains($notice, 'admin.php?page=ezbizi'), true);

reset_site();
$GLOBALS['screen'] = null;
ob_start();
do_action('admin_notices');
check('a connected site is not nagged', ob_get_clean(), '');

$GLOBALS['opt']['ezbizi_token'] = '';
// The screen this plugin's own menu item creates, taken from that item.
$GLOBALS['screen'] = (object) ['id' => $GLOBALS['own_screen']];
ob_start();
do_action('admin_notices');
check('nor is somebody already on the form', ob_get_clean(), '');
$GLOBALS['screen'] = null;

echo PHP_EOL . "A connected site that is being refused is told why" . PHP_EOL;
/*
 * The widgets vanish past the ceiling. Vanishing without a sentence reads as
 * the plugin breaking, and the person who could fix it is the one person
 * looking at wp-admin.
 */
reset_site();
$GLOBALS['screen'] = null;
$GLOBALS['is_admin'] = true;
update_option(EZBIZI_REFUSED_SINCE, time() - 86400, false);
ob_start();
do_action('admin_notices');
$refused_notice = ob_get_clean();

check('it says the token is being refused', str_contains($refused_notice, 'refusing'), true);
check('and that the published values are safe', str_contains($refused_notice, 'values are unaffected'), true);
check('and where to fix it', str_contains($refused_notice, 'admin.php?page=ezbizi'), true);

/*
 * Said from the first refusal, because the widgets have already gone by then.
 * A notice that waited would leave the one person who could fix it looking at
 * a site whose chat vanished for no stated reason.
 */
update_option(EZBIZI_REFUSED_SINCE, time() - 60, false);
ob_start();
do_action('admin_notices');
check('a fresh refusal is announced too', str_contains(ob_get_clean(), 'refusing'), true);

// And the control: a site nobody is refusing hears nothing at all.
delete_option(EZBIZI_REFUSED_SINCE);
ob_start();
do_action('admin_notices');
check('a connected site is still not nagged', ob_get_clean(), '');

echo "\nThe plugin's own row offers a way in\n";
$links = apply_filters(
    'plugin_action_links_ezbizi/ezbizi.php',
    ['<a href="#">Deactivate</a>']
);
check('a Settings link is added first', str_contains($links[0], 'admin.php?page=ezbizi'), true);
check('and Deactivate survives', count($links), 2);

echo "\nA blank token field keeps the saved token\n";
reset_site();
check('blank means unchanged', ezbizi_keep_saved_token(''), 'ezb_test');
check('a new one replaces it', ezbizi_keep_saved_token('ezb_other'), 'ezb_other');

echo PHP_EOL . "The service account is created only once the token is proved good" . PHP_EOL;
reset_site();
$GLOBALS['opt']['ezbizi_token'] = '';
ezbizi_fetch();
check('no token, no account', count($GLOBALS['users']), 0);
check('no token, no credential', count($GLOBALS['app_passwords']), 0);

reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('a good read creates the user', count($GLOBALS['users']), 1);
check('named so a site owner can find it', $GLOBALS['users'][0]->user_login, 'ezbizi-service');
check('on its own role', $GLOBALS['roles_assigned'][$GLOBALS['users'][0]->ID], 'ezbizi_service');
check('with one application password', count($GLOBALS['app_passwords']), 1);
check('handed over once', $GLOBALS['post_calls'], 1);
check(
    'to the pairing endpoint',
    $GLOBALS['last_post_url'],
    'https://app.ezbizi.com/api/v1/web/plugin/service-account'
);
check(
    'the secret travels in the body, never the query string',
    str_contains($GLOBALS['last_post_url'], 'plain-text-secret'),
    false
);
check(
    'and the body carries it',
    $GLOBALS['last_post_args']['body']['password'],
    'plain-text-secret'
);

echo PHP_EOL . "Pairing happens once, not on every page view" . PHP_EOL;
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
ezbizi_fetch();
check('still one credential', count($GLOBALS['app_passwords']), 1);
check('and no second handover', $GLOBALS['post_calls'], 1);

echo PHP_EOL . "A credential nobody received must not stay alive" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
$GLOBALS['post_next'] = ['code' => 500, 'body' => ''];
ezbizi_fetch();
check('the failed handover revokes it', count($GLOBALS['app_passwords']), 0);
check('and the site is not marked paired', get_option(EZBIZI_SERVICE_LINKED, false), false);

$GLOBALS['post_next'] = ['code' => 200, 'body' => '{}'];
$GLOBALS['tr'] = [];
ezbizi_fetch();
check('a later read retries from nothing', count($GLOBALS['app_passwords']), 1);

echo PHP_EOL . "A secret never crosses plain HTTP" . PHP_EOL;
reset_site();
$GLOBALS['opt']['ezbizi_api_base'] = 'http://app.ezbizi.com';
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('http is refused', count($GLOBALS['app_passwords']), 0);
check('and nothing is sent', $GLOBALS['post_calls'], 0);

echo PHP_EOL . "The account may publish and nothing else" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
$caps = $GLOBALS['roles']['ezbizi_service']->caps;
check('it can publish a page', isset($caps['publish_pages']), true);
check('it can upload an image', isset($caps['upload_files']), true);
check('it cannot change settings', isset($caps['manage_options']), false);
check('it cannot touch users', isset($caps['edit_users']), false);
check('it cannot install plugins', isset($caps['install_plugins']), false);
check('it cannot post unfiltered html', isset($caps['unfiltered_html']), false);
check('it cannot delete a page', isset($caps['delete_pages']), false);

echo PHP_EOL . "Changing the token ends the old pairing" . PHP_EOL;
do_action('update_option_ezbizi_token');
check('the credential is revoked', count($GLOBALS['app_passwords']), 0);
check('and the mark is cleared', get_option(EZBIZI_SERVICE_LINKED, false), false);

echo PHP_EOL . "Deactivating the plugin leaves no working key" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('paired first', count($GLOBALS['app_passwords']), 1);
foreach ($GLOBALS['deactivate'] as $cb) { $cb(); }
check('deactivation revokes it', count($GLOBALS['app_passwords']), 0);

echo PHP_EOL . "A site with application passwords switched off simply does not pair" . PHP_EOL;
reset_site();
// Switched off the way a site owner actually does it: a filter that refuses
// whatever it is handed. Setting the flag alone only simulates a non-SSL
// REQUEST, which is a different thing and must still pair.
add_filter('wp_is_application_passwords_available', fn () => false);
ok_response(['phone' => '555-0100']);
$payload = ezbizi_fetch();
check('no credential', count($GLOBALS['app_passwords']), 0);
check('and the values still render', $payload['values']['phone'], '555-0100');

echo PHP_EOL . "A cron read on an HTTPS site still pairs" . PHP_EOL;
reset_site();
// The state the pairing ACTUALLY runs in: WP-Cron or WP-CLI, where is_ssl() is
// false on a perfectly ordinary HTTPS site. This returned "no application
// passwords here" and skipped silently, forever, on a live install.
$GLOBALS['is_ssl'] = false;
$GLOBALS['home'] = 'https://a-1chimney.com';
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('a non-SSL request on an HTTPS site pairs', count($GLOBALS['app_passwords']), 1);

echo PHP_EOL . "A site that is genuinely plain HTTP does not" . PHP_EOL;
reset_site();
$GLOBALS['is_ssl'] = false;
$GLOBALS['home'] = 'http://a-1chimney.com';
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('no credential on a plain-HTTP site', count($GLOBALS['app_passwords']), 0);

echo PHP_EOL . "An admin page load pairs a site with no shortcodes on it" . PHP_EOL;
reset_site();
// The gap this closes: ezbizi_service_link() otherwise runs only from a
// content read, so a site with the plugin installed and the token pasted but
// no shortcode placed never called home and never paired.
$GLOBALS['is_ssl'] = true;
$GLOBALS['home'] = 'https://a-1chimney.com';
ok_response(['phone' => '555-0100']);
check('nothing paired before the admin loads', count($GLOBALS['app_passwords']), 0);
do_action('admin_init');
check('the admin load pairs it', count($GLOBALS['app_passwords']), 1);
check('and it is the service user', $GLOBALS['users'][0]->user_login, 'ezbizi-service');

echo PHP_EOL . "EzBizi letting go revokes the key on the site too" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
check('paired', count($GLOBALS['app_passwords']), 1);

// The server now says it no longer holds the pairing.
$body = json_decode($GLOBALS['http_next']['body'], true);
$body['service_account'] = ['linked' => false];
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($body)];
$GLOBALS['tr'] = [];
ezbizi_fetch();
check('the site revokes its own copy', count($GLOBALS['app_passwords']), 0);
check('and forgets the pairing', get_option(EZBIZI_SERVICE_LINKED, false), false);

echo PHP_EOL . "A server that still holds the pairing changes nothing" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
$body = json_decode($GLOBALS['http_next']['body'], true);
$body['service_account'] = ['linked' => true];
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($body)];
$GLOBALS['tr'] = [];
ezbizi_fetch();
check('the credential survives', count($GLOBALS['app_passwords']), 1);

// MCP/API, 2026-10-07: the SEO Helper got 403 on pages somebody else wrote.
// A site paired before the role grew must gain the new rights on its next read.
$role = get_role(EZBIZI_SERVICE_ROLE);
unset($role->caps['edit_others_pages'], $role->caps['edit_others_posts']);
delete_option('ezbizi_service_role_version');
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($body)];
$GLOBALS['tr'] = [];
ezbizi_fetch();
check('an already-paired site gains edit_others_pages', ! empty(get_role(EZBIZI_SERVICE_ROLE)->caps['edit_others_pages']), true);
check('and edit_others_posts', ! empty(get_role(EZBIZI_SERVICE_ROLE)->caps['edit_others_posts']), true);

echo PHP_EOL . "The widgets are dressed by EzBizi, not by the shortcode" . PHP_EOL;
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
do_action('init');
check('[ezbizi_form] registered', isset($GLOBALS['sc']['ezbizi_form']), true);
check('[ezbizi_chat] registered', isset($GLOBALS['sc']['ezbizi_chat']), true);

$form = $GLOBALS['sc']['ezbizi_form']([]);
check('the questions come from EzBizi', str_contains($form, 'What needs doing?'), true);
check('a textarea because EzBizi said so', str_contains($form, '<textarea'), true);
check('a select renders its options', str_contains($form, '<option value="Sweep">'), true);
check('required is marked', str_contains($form, 'required'), true);
check('the accent colour is applied', str_contains($form, '--ezbizi-accent:#aa0000'), true);
check('the button says what EzBizi says', str_contains($form, '>Send it<'), true);
check('the form posts to the form endpoint', str_contains($form, "'/api/v1/web/form'"), true);
check('a file question is a file picker', str_contains($form, '<input type="file"'), true);
check('a question can wait for another answer', str_contains($form, 'data-if-field="Service" data-if-value="Repair"'), true);
check('the CAPTCHA is always on (Jose, 2026-10-06)', str_contains($form, 'captcha=true') && str_contains($form, 'function ezSolve(api)'), true);
check('the Turnstile box sits in the form (Jose, 2026-10-08)', str_contains($form, 'class="cf-turnstile" data-sitekey="0xTESTSITE"'), true);
check('and its token goes with the form', str_contains($form, 'if(ts)v.turnstile=ts'), true);
check('a form with no ZIP question gets one (Jose, 2026-10-08)', str_contains($form, 'name="ZIP code" required'), true);
check('and the ZIP goes with the enquiry', str_contains($form, 'v.zip=z[0]'), true);
$booking = $GLOBALS['sc']['ezbizi_booking'](['title' => 'Book Garage Door Service']);
check('the booking widget draws its title', str_contains($booking, '>Book Garage Door Service<'), true);
// UI queue 75: where you are in the steps, in words and no numbers (Jose,
// 2026-10-09, "no 01/02"), and a step's own display never outranks hidden -
// the first build showed the ZIP step under the days.
check('it shows where you are in the steps', str_contains($booking, 'class="ezb-track"') && str_contains($booking, '>Your details</li>'), true);
check('a hidden step stays hidden', str_contains($booking, '[data-step][hidden]{display:none!important}'), true);
check('it asks the ZIP first and reads the days of that area', str_contains($booking, 'data-step="zip"') && str_contains($booking, "'/api/v1/web/booking/days?key='"), true);
check('it books behind the robot check and the Turnstile box', str_contains($booking, 'function ezSolve(api)') && str_contains($booking, 'class="cf-turnstile"'), true);
// Jose, 2026-10-13: a lead's own link (?l=) books onto that lead and skips what it holds.
check('a lead link is read and sent with the booking', str_contains($booking, "get('l')") && str_contains($booking, 'if(lt)v.l=lt'), true);
check('and asks what the lead already holds', str_contains($booking, "'/api/v1/web/booking/lead?key='"), true);
check('the preferred window goes first in its day', str_contains($booking, 'pf.date===d.date'), true);
check('a ZIP with no online times still takes a request as a lead', str_contains($booking, "to='/api/v1/web/form'") && str_contains($booking, 'st.req=1'), true);
check('the short booking link is forwarded', str_contains((string) file_get_contents(__DIR__.'/../ezbizi/ezbizi.php'), "home_url('/booking/') . '?l=' . $m[1]"), true);
unset($GLOBALS['ezbizi_chat_hidden']);
$GLOBALS['sc']['ezbizi_booking'](['chat' => 'keep']);
check('chat="keep" leaves the chat bubble (suggestion 01a1889b)', ! empty($GLOBALS['ezbizi_chat_hidden']), false);
$GLOBALS['sc']['ezbizi_booking']([]);
check('and by default the booking page hides it', ! empty($GLOBALS['ezbizi_chat_hidden']), true);
unset($GLOBALS['ezbizi_chat_hidden']);
check('the secret never reaches the page', str_contains($form, '0xTESTSECRET'), false);

$chat = $GLOBALS['sc']['ezbizi_chat']([]);
check('the chat title comes from EzBizi', str_contains($chat, 'Talk to A-1'), true);
check('and its position', str_contains($chat, 'right:20px'), true);
check('the chat posts to the chat endpoint', str_contains($chat, '/api/v1/web/chat'), true);

echo PHP_EOL . "The form carries A1's appointment form" . PHP_EOL;
reset_site();
$booking = json_decode(widget_payload(), true);
$booking['widgets']['form'] = array_merge($booking['widgets']['form'], [
    'title' => 'Book an Appointment Online',
    'subtitle' => 'Schedule directly with our scheduling system.',
    'antibot' => true, 'antibot_label' => "I'm not a robot",
    'consent_on' => false,
    'redirect' => 'https://a1.test/thanks/',
    'fields' => [
        ['label' => 'First Name', 'type' => 'text', 'required' => true, 'placeholder' => 'John', 'width' => 25],
        ['label' => 'Last Name', 'type' => 'text', 'required' => true, 'width' => 25],
        ['label' => 'Appointment Date', 'type' => 'date', 'required' => true, 'width' => 50],
        ['label' => 'Contact me by', 'type' => 'radio', 'options' => ['Phone', 'Email']],
        ['label' => 'Campaign', 'type' => 'hidden', 'value' => 'spring'],
    ],
]);
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($booking)];
ezbizi_fetch();
do_action('init');
$bform = $GLOBALS['sc']['ezbizi_form']([]);
check('its title', str_contains($bform, '>Book an Appointment Online</h3>'), true);
check('and the line under it', str_contains($bform, 'Schedule directly'), true);
check('a quarter-width field', str_contains($bform, 'calc(25% - '), true);
check('a placeholder', str_contains($bform, 'placeholder="John"'), true);
check('a date picker', str_contains($bform, 'type="date"'), true);
check('radio choices', substr_count($bform, 'type="radio"'), 2);
check('a hidden value sent with it', str_contains($bform, 'type="hidden" name="Campaign" value="spring"'), true);
check('the robot box', str_contains($bform, 'ezbizi-antibot-box'), true);
check('no consent box when switched off', str_contains($bform, '<input type="checkbox" class="ezbizi-consent-box"'), false);
check('where it goes after', str_contains($bform, 'location.href="https:\/\/a1.test\/thanks\/"'), true);
check('consent links are drawn as links', ezbizi_consent_html('See our [Privacy Policy](https://a1.test/privacy/).'), 'See our <a href="https://a1.test/privacy/" target="_blank" rel="noopener">Privacy Policy</a>.');
check('and nothing else is', ezbizi_consent_html('[x](javascript:alert(1)) <b>hi</b>'), '[x](javascript:alert(1)) &lt;b&gt;hi&lt;/b&gt;');
check('a link cannot break out of its href', str_contains(ezbizi_consent_html('[y](https://e.test/"onmouseover=x)'), '"onmouseover'), false);
check('first and last name make the lead\'s name', str_contains($bform, "low==='first name'"), true);

// Back to the standard widget payload for the sections that follow.
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
do_action('init');
check('a select with no placeholder prompts for a choice', str_contains($GLOBALS['sc']['ezbizi_form']([]), '<option value="">Choose…</option><option value="Sweep">'), true);

echo PHP_EOL . "A shortcode argument cannot override EzBizi" . PHP_EOL;
$forced = $GLOBALS['sc']['ezbizi_form'](['fields' => 'Something,Else', 'button' => 'Override me']);
check('the fields still come from EzBizi', str_contains($forced, 'What needs doing?'), true);
check('and the button is too', str_contains($forced, '>Send it<'), true);
check('the argument is ignored entirely', str_contains($forced, 'Override me'), false);

echo PHP_EOL . "There is no off switch any more" . PHP_EOL;
/*
 * The inverse of the check that used to be here, and the reason is Jose's,
 * 2026-09-15: "get rid of the switches, they should just be on". Putting the
 * widget on a page is the decision to show it; a second switch that could blank
 * something already placed did nothing but lose people's work silently.
 *
 * The payload below still SAYS enabled=false, because an older server sends
 * that and so does a row saved before today. Rendering anyway is the whole
 * point — this is what proves the gate is gone rather than merely untested.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload(false)];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('a visitor gets the form regardless', str_contains($GLOBALS['sc']['ezbizi_form']([]), 'What needs doing?'), true);
check('and the chat', str_contains($GLOBALS['sc']['ezbizi_chat']([]), 'Talk to A-1'), true);
check('and nothing mentions a switch', str_contains($GLOBALS['sc']['ezbizi_form']([]), 'switched off'), false);

echo PHP_EOL . "A site with no browser key shows a visitor nothing" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('the form renders empty', $GLOBALS['sc']['ezbizi_form']([]), '');
check('and so does the chat', $GLOBALS['sc']['ezbizi_chat']([]), '');


echo PHP_EOL . "A site EzBizi has stopped recognising stops asking for chats" . PHP_EOL;
/*
 * The deploy desk found this by looking at a site rather than at the code: the
 * guard asked whether a public key was ABSENT, and a site whose token the
 * server REJECTS still holds the last good payload, key and all. So a pairing
 * that had ended went on drawing a chat box on every page, and a visitor
 * typing into it is being promised a conversation nobody will receive.
 *
 * Refused and unreachable are different facts and only one of them is about
 * the site, so they are tested apart.
 */
reset_site();
delete_option(EZBIZI_REFUSED_SINCE);
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
check('a good read leaves no mark', get_option(EZBIZI_REFUSED_SINCE, 0), 0);

// Our bad day. It must change nothing about the site.
delete_transient(EZBIZI_CACHE_KEY);
$GLOBALS['http_next'] = ['code' => 500, 'body' => ''];
ezbizi_fetch();
check('a 500 is our problem, not theirs', get_option(EZBIZI_REFUSED_SINCE, 0), 0);

delete_transient(EZBIZI_CACHE_KEY);
$GLOBALS['http_next'] = ['code' => 401, 'body' => ''];
ezbizi_fetch();
$first = (int) get_option(EZBIZI_REFUSED_SINCE, 0);
check('a refusal is dated', $first > 0, true);

// The clock measures how long it has lasted, so it must not restart.
delete_transient(EZBIZI_CACHE_KEY);
$GLOBALS['http_next'] = ['code' => 401, 'body' => ''];
ezbizi_fetch();
check('and dated once', (int) get_option(EZBIZI_REFUSED_SINCE, 0), $first);

/*
 * Jose, on the day the chat went onto every page: it "should only show up if a
 * connection has been connected properly". So a refusal hides it AT ONCE, not
 * after a grace period — a site EzBizi will not recognise is not connected,
 * whatever it was an hour ago.
 *
 * This replaces a check that asserted the opposite, and the earlier reasoning
 * was not wrong: rotating a token leaves a gap of minutes during which the
 * public key often still works, so the chat now disappears for that gap. That
 * is a small fault that fixes itself. A chat on a site that is no longer ours
 * is not.
 */
$GLOBALS['is_admin'] = false;
check('a refusal draws no chat', $GLOBALS['sc']['ezbizi_chat']([]), '');
check('and no form', $GLOBALS['sc']['ezbizi_form']([]), '');

/*
 * The values are a DIFFERENT question and must survive it. A phone number a
 * week stale is still the right phone number; blanking a live page because we
 * fell out with the account is the one thing this plugin has always refused to
 * do.
 */
check('but the phone number still publishes', ezbizi_payload()['values']['phone'], '555-0100');

// And it is over the moment the site is recognised again.
delete_transient(EZBIZI_CACHE_KEY);
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
check('a good read forgives it', get_option(EZBIZI_REFUSED_SINCE, 0), 0);
check('and the chat comes back', str_contains($GLOBALS['sc']['ezbizi_chat']([]), 'Talk to A-1'), true);

echo PHP_EOL . "The chat asks who it is talking to" . PHP_EOL;
/*
 * Jose photographed the old one beside the chat on A1 Tools. Two differences
 * mattered. It looked wrong — the theme was styling our launcher and our input,
 * because we had set almost nothing and let somebody else's stylesheet decide.
 * And it opened straight into a message box, so a chat arrived in Leads with
 * nobody's name on it: the visitor types, closes the tab, and the only way to
 * answer is to hope they come back.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
$chat_html = $GLOBALS['sc']['ezbizi_chat']([]);
check('the chat start solves the robot check (Jose, 2026-10-06)', str_contains($chat_html, 'function ezSolve(api)') && str_contains($chat_html, 'if(sol)body.captcha=sol'), true);

check('it asks for a name', str_contains($chat_html, 'name="name"'), true);
check('and two ways to reply', str_contains($chat_html, 'name="phone"') && str_contains($chat_html, 'name="email"'), true);
check('and what they want', str_contains($chat_html, 'name="message"'), true);
check('all five required (name, phone, ZIP, email, message)', substr_count($chat_html, 'required'), 5);
check('there is a way out of the panel', str_contains($chat_html, 'ezbizi-chat-close'), true);

// The thread exists but is not what they meet first.
check('the message box waits its turn', str_contains($chat_html, '<div class="ezbizi-chat-log" hidden'), true);
// Jose, 2026-10-01: phone, email and ZIP, each its own required box, all sent.
check('it asks for a phone', str_contains($chat_html, 'type="tel" name="phone" autocomplete="tel" required'), true);
check('an email', str_contains($chat_html, 'type="email" name="email" autocomplete="email" required'), true);
check('and a five-digit ZIP', str_contains($chat_html, 'name="zip" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{5}"'), true);
check('and sends all three', str_contains($chat_html, 'phone:pre.phone.value.trim(),email:pre.email.value.trim(),zip:pre.zip.value.trim()'), true);

/*
 * The fix for what the photograph actually showed. A theme that sets
 * `background` on inputs turned ours near-black; stating it is what stops
 * that, and a widget living in somebody else's stylesheet has to state
 * everything it cares about.
 */
check('the input states its own colours', str_contains($chat_html, 'background:#fff;color:#1b1b1b'), true);
check('and the launcher its own shape', str_contains($chat_html, 'border-radius:999px'), true);

// It must not be readable as HTML when it comes back from a stranger.
check('replies are drawn as text', str_contains($chat_html, 'createTextNode(text)') && ! str_contains($chat_html, 'innerHTML=m.'), true);
check('and never as markup', str_contains($chat_html, 'row.innerHTML=m.text'), false);

echo PHP_EOL . "The chat takes Toula's options" . PHP_EOL;
reset_site();
$toula = json_decode(widget_payload(), true);
$toula['widgets']['chat'] += [
    'agent_name' => 'Maria', 'avatar_url' => 'https://example.com/m.jpg', 'show_status' => true,
    'online_text' => 'Here now', 'bubble_icon' => 'phone', 'bubble_size' => 60, 'offset_x' => 30,
    'offset_y' => 40, 'animation' => 'fade', 'delay' => 5, 'devices' => 'mobile', 'hide_on' => "/careers
/jobs/*",
    'bubble_bg' => '#ff0000', 'header_bg' => '#00ff00', 'send_bg' => '#111222', 'start_bg' => '#333444', 'focus_color' => '#555666', 'shadow' => false, 'analytics' => true,
];
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($toula)];
ezbizi_fetch();
$_SERVER['REQUEST_URI'] = '/about/';
$c = $GLOBALS['sc']['ezbizi_chat']([]);
check('the agent is named', str_contains($c, 'ezbizi-chat-who">Maria'), true);
check('with a face', str_contains($c, 'src="https://example.com/m.jpg"'), true);
check('and an online dot', str_contains($c, 'Here now'), true);
check('where it was put', str_contains($c, 'right:30px;bottom:40px'), true);
check('hidden until the delay', str_contains($c, 'display:none') && str_contains($c, 'delay=5'), true);
check('the bubble colour', str_contains($c, 'background:#ff0000'), true);
check('its own Send button colour', str_contains($c, 'background:#111222;color:'), true);
check('its own Start button colour', str_contains($c, 'margin:0;background:#333444;'), true);
check('its own focus border', str_contains($c, 'outline:2px solid #555666;'), true);
check('and size', str_contains($c, 'height:60px'), true);
check('no shadow when asked', str_contains($c, 'box-shadow:0 10px 28px'), false);
check('phones only', str_contains($c, '@media (min-width:768px)'), true);
check('fades rather than slides', str_contains($c, 'ezbizi-chat-fade'), true);
check('and tells analytics', str_contains($c, "event:'ezbizi_chat_'+e"), true);
check('the face leads the button when there is one', str_contains($c, 'ezbizi-face ezbizi-face-launch'), true);
$toula['widgets']['chat']['agent_name'] = '';
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode($toula)];
ezbizi_fetch();
check('the chosen icon when nobody has a face', str_contains($GLOBALS['sc']['ezbizi_chat']([]), 'M6.6 10.8'), true);
check('and Powered by EzBizi', str_contains($c, 'Powered by <b>EzBizi</b>'), true);
$_SERVER['REQUEST_URI'] = '/careers/';
check('never on a hidden page', $GLOBALS['sc']['ezbizi_chat']([]), '');
$_SERVER['REQUEST_URI'] = '/jobs/roofer/';
check('or under a hidden path', $GLOBALS['sc']['ezbizi_chat']([]), '');
$_SERVER['REQUEST_URI'] = '/careersx/';
check('but a longer name is not the page', $GLOBALS['sc']['ezbizi_chat']([]) !== '', true);
unset($_SERVER['REQUEST_URI'], $GLOBALS['ezbizi_chat_drawn']);

echo PHP_EOL . "The chat bubble is on every page, and only once" . PHP_EOL;
/*
 * Found by the deploy desk: after the switches came off it fetched
 * a-1chimney.com and there was no EzBizi markup on the page at all, because
 * nothing hooked wp_footer and a bubble had to be pasted onto each page by
 * hand. `position:fixed` and the separate `inline` option say what was
 * intended; this is the half of Jose's complaint the switch removal did not
 * reach.
 */
$footer = static function () {
    // A fresh page: nothing drawn, nothing asking for no bubble.
    unset($GLOBALS['ezbizi_chat_drawn'], $GLOBALS['ezbizi_chat_hidden']);
    ob_start();
    do_action('wp_footer');

    return ob_get_clean();
};

reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;

check('a visitor gets the bubble without anybody placing it', str_contains($footer(), 'Talk to A-1'), true);
check('floating, not in the flow', str_contains($footer(), 'position:fixed'), true);

// No bubble on a page with the booking widget or the review faces (Jose,
// 2026-10-09: "chat bubble should be disabled on that page as well as the
// review one"). The shortcode runs in the content, before the footer.
$footerAfter = static function (string $shortcode) {
    unset($GLOBALS['ezbizi_chat_drawn'], $GLOBALS['ezbizi_chat_hidden']);
    $GLOBALS['sc'][$shortcode]([]);
    ob_start();
    do_action('wp_footer');

    return ob_get_clean();
};
check('a booking page has no bubble', str_contains($footerAfter('ezbizi_booking'), 'Talk to A-1'), false);
check('nor has the reviews page', str_contains($footerAfter('ezbizi_review_faces'), 'Talk to A-1'), false);
check('and the next page has it again', str_contains($footer(), 'Talk to A-1'), true);

// Placed already: the shortcode ran, so the footer must keep out. Two bubbles
// on one page is two conversations, and the visitor picks one at random.
unset($GLOBALS['ezbizi_chat_drawn']);
// The shortcode RETURNS its markup; only the footer echoes. Counting the
// buffer alone said 0 and passed for the wrong reason — it would have passed
// just as well if the shortcode had drawn nothing at all.
$placed = $GLOBALS['sc']['ezbizi_chat']([]);
ob_start();
do_action('wp_footer');
$both = $placed . ob_get_clean();
check('the placed one drew', substr_count($placed, 'id="ezbizi-chat-') >= 1, true);
check('and the footer added no second', substr_count($both, 'id="ezbizi-chat-'), 1);

reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload_at('inline')];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('inline means the site says where, so the footer keeps out', $footer(), '');

// The control. Without it the two absences above would also pass on a plugin
// whose footer hook was never registered at all.
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('the hook exists to be silent', str_contains($footer(), 'ezbizi-chat-'), true);

echo PHP_EOL . "An unpaired site puts nothing in anybody's footer" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('no key, no bubble', $footer(), '');

echo PHP_EOL . "A chat EzBizi has switched off is not on the page at all" . PHP_EOL;
/*
 * How "off" is said, and why these three checks are the feature.
 *
 * `WidgetDefaults::published()` takes the whole `chat` block OUT of the
 * payload rather than sending `enabled: false`, because no shipped plugin
 * reads that flag and every plugin since 1.2.0 already draws nothing when the
 * block is MISSING. The switch on the customer's screen therefore rests
 * entirely on a behaviour nothing had ever asserted — the plugin could be
 * "improved" into rendering a chat from defaults and the switch would silently
 * stop working on 110 sites, with the server still perfectly correct.
 *
 * So it is pinned here, at the two places a bubble can reach a page: the
 * shortcode somebody placed, and the footer injector that needs nobody.
 */
function widget_payload_without_chat() {
    $p = json_decode(widget_payload(), true);
    unset($p['widgets']['chat']);

    return json_encode($p);
}

reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload_without_chat()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;

check('no chat block, so the shortcode draws nothing', $GLOBALS['sc']['ezbizi_chat']([]), '');
check('and the footer injects nothing', $footer(), '');

// The form is a different widget and a different decision. Switching the chat
// off must not take the contact form off the same site.
check(
    'the form is unaffected',
    str_contains($GLOBALS['sc']['ezbizi_form']([]), 'What needs doing?'),
    true
);

/*
 * The positive control, and it is not optional: without it both absences above
 * pass just as happily on a plugin that renders no chat under any
 * circumstances, which is the other way to have this feature look correct and
 * be broken.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;

check(
    'a chat block draws the bubble',
    str_contains($GLOBALS['sc']['ezbizi_chat']([]), 'Talk to A-1'),
    true
);
check('and the footer injects it', str_contains($footer(), 'ezbizi-chat-open'), true);

// ── The presentation widgets ─────────────────────────────────────

/**
 * The fifteen networks, in the shape the server's field catalogue sends them.
 *
 * `section` is what the row of icons reads — not a list of networks inside the
 * plugin — so a sixteenth added in EzBizi renders with nothing installed. A
 * fixture that left the section out would pass against a plugin with the list
 * hardcoded, which is the thing being ruled out.
 */
function social_fields() {
    $networks = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
        'twitter' => 'Twitter / X', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok',
        'yelp' => 'Yelp', 'google_business' => 'Google Business', 'pinterest' => 'Pinterest',
        'bbb' => 'BBB', 'nextdoor' => 'Nextdoor', 'houzz' => 'Houzz',
        'angi' => 'Angi', 'thumbtack' => 'Thumbtack', 'homeadvisor' => 'HomeAdvisor',
    ];

    $out = [];

    foreach ($networks as $key => $label) {
        $out[] = [
            'key' => $key, 'label' => $label, 'hint' => '',
            'requirement' => 'optional', 'section' => 'social',
            'shortcode' => '[ezbizi_'.$key.']',
        ];
    }

    return $out;
}

/** What `WidgetPresets::resolve()` sends: a default per widget, and named ones. */
function preset_bundles() {
    return [
        'social_links' => [
            'default' => ['style' => 'plain', 'shape' => 'circle', 'size' => 28, 'gap' => 10, 'labels' => false],
            'footer' => ['style' => 'solid', 'shape' => 'square', 'size' => 40, 'gap' => 4, 'labels' => true],
        ],
        'city_list' => [
            'default' => ['layout' => 'columns', 'columns' => 3, 'group' => true, 'link' => true],
            'plain' => ['layout' => 'list', 'columns' => 1, 'group' => false, 'link' => false],
            'folded' => ['layout' => 'accordion', 'columns' => 1, 'group' => true, 'link' => true],
        ],
        'hours_table' => [
            'default' => ['today' => true, 'compact' => false],
            'tight' => ['today' => false, 'compact' => true],
        ],
        'google_map' => [
            'default' => ['height' => 320, 'zoom' => 14],
            'tall' => ['height' => 600, 'zoom' => 17],
        ],
    ];
}

/** `hours_week`, as `HoursWeek::parse()` returns it — Monday first, seven long. */
function published_week() {
    return [
        ['day' => 'Mon', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'Tue', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'Wed', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'Thu', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'Fri', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'Sat', 'closed' => false, 'open' => '09:00', 'close' => '13:00'],
        ['day' => 'Sun', 'closed' => true, 'open' => '00:00', 'close' => '00:00'],
    ];
}

/** Four town pages and one that is not a town. */
function city_pages() {
    return [
        '/chimney-sweep-montclair' => ['city' => 'Montclair', 'state' => 'NJ', 'group' => 'North Jersey'],
        '/chimney-sweep-caldwell' => ['city' => 'Caldwell', 'state' => 'NJ', 'group' => 'North Jersey', 'phone' => '555-0111'],
        '/chimney-sweep-vineland' => ['city' => 'Vineland', 'state' => 'NJ', 'group' => 'South Jersey'],
        // A second page about the same town: one town in the list, not two.
        '/chimney-repair-vineland' => ['city' => 'Vineland', 'state' => 'NJ', 'group' => 'South Jersey'],
        // No group, so it has nowhere to be filed and goes last.
        '/chimney-sweep-dover' => ['city' => 'Dover', 'state' => 'DE'],
        // Not a town page at all: no city, so nothing may list it.
        '/about-us' => ['about_us_field_1' => 'We have swept chimneys since 1974.'],
    ];
}

/**
 * The 1.12.0 payload: presets beside the widgets, `hours_week` at top level.
 *
 * A key set to null in `$extra` is REMOVED, which is how the absent-`hours_week`
 * case is written — absence is the server's vocabulary for "this site's hours
 * are a sentence, not a grid", and a fixture that sent an empty array instead
 * would be testing something the server never sends.
 */
function preset_payload(array $values = [], array $pages = [], array $extra = []) {
    $general = ['company_name', 'phone', 'hours', 'timezone', 'latitude', 'longitude', 'maps_url', 'review_link'];
    $fields = [];

    foreach ($general as $key) {
        $fields[] = [
            'key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'hint' => '',
            'requirement' => 'optional', 'section' => 'general', 'shortcode' => '[ezbizi_'.$key.']',
        ];
    }

    $payload = array_merge([
        'site' => ['host' => 'a-1chimney.com', 'label' => null, 'company' => 'A-1'],
        'fields' => array_merge($fields, social_fields()),
        'values' => $values,
        'pages' => $pages,
        'widgets' => [
            'presets' => preset_bundles(),
            // As WidgetPresets::schema() sends it; WebWidgetsTest pins that side.
            'schema' => [
                'social_links' => [
                    'style' => ['type' => 'choice', 'choices' => ['plain', 'solid', 'outline']],
                    'shape' => ['type' => 'choice', 'choices' => ['circle', 'square', 'rounded']],
                    'size' => ['type' => 'range', 'min' => 16, 'max' => 64],
                    'gap' => ['type' => 'range', 'min' => 0, 'max' => 40],
                    'labels' => ['type' => 'bool'],
                    // Three of the generated style options, as the server describes them.
                    'icon_color' => ['type' => 'color', 'part' => 'icon', 'prop' => 'color'],
                    'label_font_family' => ['type' => 'text', 'max' => 60, 'font' => true, 'part' => 'label', 'prop' => 'font_family'],
                    'box_devices' => ['type' => 'choice', 'choices' => ['', 'desktop', 'mobile'], 'part' => 'box', 'prop' => 'devices'],
                    'networks' => ['type' => 'text', 'max' => 300],
                    'custom' => ['type' => 'text', 'max' => 1000],
                    'colors' => ['type' => 'choice', 'choices' => ['single', 'brand']],
                    'new_tab' => ['type' => 'bool'],
                ],
                'city_list' => [
                    'heading_tag' => ['type' => 'choice', 'choices' => ['none', 'h2', 'h3', 'h4', 'h5', 'h6']],
                    'other_label' => ['type' => 'text', 'max' => 40],
                    'search' => ['type' => 'bool'],
                    'count' => ['type' => 'bool'],
                    'show_state' => ['type' => 'bool'],
                ],
                'phone_box' => [
                    'which' => ['type' => 'choice', 'choices' => ['primary', 'secondary']],
                    'format' => ['type' => 'choice', 'choices' => ['as_entered', 'dashes', 'dots', 'parentheses']],
                    'link_type' => ['type' => 'choice', 'choices' => ['call', 'sms', 'whatsapp']],
                    'link' => ['type' => 'bool'],
                ],
                'google_map' => [
                    'map_type' => ['type' => 'choice', 'choices' => ['roadmap', 'satellite', 'hybrid', 'terrain']],
                    'directions' => ['type' => 'bool'],
                ],
                'post_title' => [
                    'tag' => ['type' => 'choice', 'choices' => ['none', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div']],
                    'format' => ['type' => 'choice', 'choices' => ['title', 'city', 'city_state', 'address']],
                ],
                'hours_table' => [
                    'layout' => ['type' => 'choice', 'choices' => ['table', 'stack', 'inline']],
                    'days' => ['type' => 'choice', 'choices' => ['short', 'long']],
                    'clock' => ['type' => 'choice', 'choices' => ['12', '24']],
                    'status' => ['type' => 'bool'],
                    'show_closed' => ['type' => 'bool'],
                ],
                'contact_info' => [
                    'extra_phones' => ['type' => 'text', 'max' => 500],
                    'labels' => ['type' => 'bool'],
                    'phone_icon' => ['type' => 'choice', 'choices' => ['auto', 'mobile', 'none']],
                    'hours' => ['type' => 'bool'],
                    'secondary' => ['type' => 'bool'],
                    'links' => ['type' => 'bool'],
                    'closed_text' => ['type' => 'text', 'max' => 30],
                    'order' => ['type' => 'text', 'max' => 60],
                ],
                'cta' => [
                    'action' => ['type' => 'choice', 'choices' => ['call', 'book', 'form', 'email', 'review', 'url', 'sms', 'whatsapp']],
                    'url' => ['type' => 'text', 'max' => 500],
                    'url_params' => ['type' => 'text', 'max' => 200],
                    'new_tab' => ['type' => 'bool'],
                    'label' => ['type' => 'choice', 'choices' => ['words', 'value']],
                    'icon' => ['type' => 'bool'],
                    'icon_position' => ['type' => 'choice', 'choices' => ['left', 'right']],
                    'animation' => ['type' => 'choice', 'choices' => ['none', 'pulse', 'shake', 'bounce', 'glow']],
                    'mobile' => ['type' => 'choice', 'choices' => ['same', 'bar', 'hide']],
                ],
            ],
            'style' => [
                'accent' => '#aa0000', 'on_accent' => '#ffffff', 'text' => '#111111',
                'background' => '#ffffff', 'font' => 'inherit', 'radius' => 12,
            ],
        ],
        'hours_week' => published_week(),
    ], $extra);

    return json_encode(array_filter($payload, static function ($value) {
        return $value !== null;
    }));
}

/** Fetch a 1.12.0 payload and register the shortcodes it names. */
function serve_preset_payload(array $values = [], array $pages = [], array $extra = []) {
    $GLOBALS['http_next'] = ['code' => 200, 'body' => preset_payload($values, $pages, $extra)];
    ezbizi_fetch();
    do_action('init');
}

echo PHP_EOL . "Five new shortcodes exist to be asked for" . PHP_EOL;
reset_site();
serve_preset_payload();

foreach (['social_links', 'google_map', 'post_title', 'hours_table', 'city_list'] as $tag) {
    check("[ezbizi_{$tag}] registered", isset($GLOBALS['sc']['ezbizi_'.$tag]), true);
}

echo PHP_EOL . "The social row draws the networks that have a link" . PHP_EOL;
reset_site();
serve_preset_payload([
    'facebook' => 'https://www.facebook.com/a1chimney',
    'instagram' => 'https://www.instagram.com/a1chimney',
    'tiktok' => 'https://www.tiktok.com/@a1chimney',
]);

$social = $GLOBALS['sc']['ezbizi_social_links']([]);

// Three of fifteen. The other twelve are not gaps in a row — they are networks
// this company is not on.
check('three links, because three are set', substr_count($social, '<a class="ezbizi-social-link'), 3);
check('facebook among them', str_contains($social, 'facebook.com/a1chimney'), true);
$custom = $GLOBALS['sc']['ezbizi_social_links'](['custom' => 'Angi | https://angi.com/a1 | https://x.test/angi.png; Porch | https://porch.com/a1']);
check('custom platforms join the row', substr_count($custom, '<a class="ezbizi-social-link'), 5);
check('with their own icon', str_contains($custom, '<img src="https://x.test/angi.png"'), true);
check('or their initial', str_contains($custom, '>P</text>'), true);
check('and nothing for a network with no value', str_contains($social, 'pinterest'), false);
check('each opens away from the site safely', substr_count($social, 'rel="noopener noreferrer"'), 3);
check('in a new tab', substr_count($social, 'target="_blank"'), 3);
check('the icons are inline markup, not a request', substr_count($social, '<svg'), 3);
check('and no icon font or stylesheet is pulled in', str_contains($social, '<link'), false);
check('named for a screen reader even with the labels off', str_contains($social, 'aria-label="Facebook"'), true);

check('the default preset is round', str_contains($social, 'border-radius:999px'), true);
check('28 pixels', str_contains($social, 'width:28px'), true);
check('with the default gap', str_contains($social, 'gap:10px'), true);
check('and no visible labels', str_contains($social, 'ezbizi-social-label'), false);

$footer_row = $GLOBALS['sc']['ezbizi_social_links'](['preset' => 'footer']);

check('a named preset squares the corners', str_contains($footer_row, 'border-radius:0'), true);
check('and grows them', str_contains($footer_row, 'height:40px'), true);
check('and names each one', str_contains($footer_row, 'ezbizi-social-label'), true);
// Solid takes the accent the customer already set for the chat. An icon colour
// of its own in every preset would be a second place to set one colour.
check('and fills them with the accent EzBizi holds', str_contains($footer_row, 'background:#aa0000'), true);

/*
 * The typo case, which is the reason presets guarantee a default at both ends.
 * `preset="foter"` is somebody's live footer, so it renders the default row —
 * not an empty div, and not a message about presets.
 */
$typo = $GLOBALS['sc']['ezbizi_social_links'](['preset' => 'foter']);

check('an unknown name renders something', $typo !== '', true);
check('and it is the default', str_contains($typo, 'border-radius:999px'), true);

// Jose, 2026-09-25: "company wide preset but allowing per widget override".
$own = $GLOBALS['sc']['ezbizi_social_links'](['size' => '40', 'labels' => 'yes']);
check('a widget can change one option of its preset', str_contains($own, 'width:40px'), true);
check('and another', str_contains($own, 'ezbizi-social-label'), true);
check('while the rest stay the preset\'s', str_contains($own, 'border-radius:999px'), true);
check('a number past its range is held to it', str_contains($GLOBALS['sc']['ezbizi_social_links'](['size' => '9999']), 'width:64px'), true);
check('a choice EzBizi does not offer is ignored', str_contains($GLOBALS['sc']['ezbizi_social_links'](['shape' => 'hexagon']), 'border-radius:999px'), true);
check('an override cannot write its own CSS', str_contains($GLOBALS['sc']['ezbizi_social_links'](['size' => '40;}body{display:none']), 'display:none'), false);
// Compared without the element id, which every call numbers afresh.
$strip = fn ($html) => preg_replace('/ezbizi-social-\d+/', '', $html);
check('an option nobody described is ignored', $strip($GLOBALS['sc']['ezbizi_social_links'](['onclick' => 'x'])) === $strip($GLOBALS['sc']['ezbizi_social_links']([])), true);

$picked = $GLOBALS['sc']['ezbizi_social_links'](['networks' => 'tiktok,facebook']);
check('only the networks asked for', substr_count($picked, '<a class="ezbizi-social-link'), 2);
check('in the order asked for', strpos($picked, 'tiktok.com') < strpos($picked, 'facebook.com'), true);
check('brand colours', str_contains($GLOBALS['sc']['ezbizi_social_links'](['colors' => 'brand']), '#1877F2'), true);
check('a new tab can be switched off', str_contains($GLOBALS['sc']['ezbizi_social_links'](['new_tab' => 'no']), 'target="_blank"'), false);

// Jose, 2026-09-25: "i want all the options, for all the widgets".
$styled = $GLOBALS['sc']['ezbizi_social_links'](['icon_color' => '#FF0000', 'label_font_family' => 'Oswald', 'box_devices' => 'mobile']);
check('a style option becomes a rule on its part', (bool) preg_match('/#ezbizi-social-\d+#ezbizi-social-\d+ \.ezbizi-social-link\{color:#ff0000\}/', $styled), true);
check('a font by name', str_contains($styled, 'font-family:Oswald'), true);
check('mobile only hides it on a wide screen', str_contains($styled, '@media (min-width:768px)'), true);
check('a colour that is not a colour prints nothing', str_contains($GLOBALS['sc']['ezbizi_social_links'](['icon_color' => 'red;}body{display:none']), 'display:none'), false);
check('a font name cannot close the rule', str_contains($GLOBALS['sc']['ezbizi_social_links'](['label_font_family' => 'x;}body{display:none']), '}body{'), false);
check('nothing set, no extra style at all', substr_count($GLOBALS['sc']['ezbizi_social_links']([]), '<style>'), 1);
check('not the named one', str_contains($typo, 'ezbizi-social-label'), false);

// A name typed the way somebody would type it still finds the preset: the
// plugin narrows it by the same rule the server stored it under.
check(
    'the name is read the way the server writes it',
    str_contains($GLOBALS['sc']['ezbizi_social_links'](['preset' => ' Footer ']), 'height:40px'),
    true
);

echo PHP_EOL . "The map needs no API key, and degrades twice" . PHP_EOL;
reset_site();
serve_preset_payload([
    'company_name' => 'A-1 Chimney',
    'latitude' => '40.8398',
    'longitude' => '-74.2765',
    'maps_url' => 'https://maps.app.goo.gl/a1chimney',
]);

$map = $GLOBALS['sc']['ezbizi_google_map']([]);

check('an iframe', str_contains($map, '<iframe'), true);
check('at the coordinates', str_contains($map, 'q=40.8398,-74.2765'), true);
/*
 * The keyless embed, and the check that matters most on this widget: we have
 * no Maps API key, and a keyed embed would put "This page can't load Google
 * Maps correctly" on every site the moment one was missing or over quota.
 */
check('the keyless embed form', str_contains($map, 'output=embed'), true);
check('and no key anywhere in it', str_contains($map, 'key='), false);
check('the default zoom', str_contains($map, 'z=14'), true);
check('the default height', str_contains($map, 'height:320px'), true);
check('lazy, so a map below the fold costs the page nothing', str_contains($map, 'loading="lazy"'), true);
check('and named, so it is not announced as "frame"', str_contains($map, 'title="A-1 Chimney on Google Maps"'), true);

$tall = $GLOBALS['sc']['ezbizi_google_map'](['preset' => 'tall']);
check('a named preset changes the height', str_contains($tall, 'height:600px'), true);
check('and the zoom', str_contains($tall, 'z=17'), true);
check(
    'an unknown name is the default, not nothing',
    str_contains($GLOBALS['sc']['ezbizi_google_map'](['preset' => 'taler']), 'height:320px'),
    true
);

// No coordinates. A Google "Share" link refuses to be framed, which is why the
// coordinates exist at all — but a link to the listing beats an empty box.
reset_site();
serve_preset_payload([
    'company_name' => 'A-1 Chimney',
    'maps_url' => 'https://maps.app.goo.gl/a1chimney',
]);
$link_only = $GLOBALS['sc']['ezbizi_google_map']([]);

check('with no coordinates there is no frame', str_contains($link_only, '<iframe'), false);
check('but there is a way to the listing', str_contains($link_only, 'maps.app.goo.gl/a1chimney'), true);
check('and it opens safely', str_contains($link_only, 'rel="noopener noreferrer"'), true);

// Neither. Mid-setup, and an empty space is better than a box explaining our
// data model to a visitor.
reset_site();
serve_preset_payload(['company_name' => 'A-1 Chimney']);
check('with neither, nothing at all', $GLOBALS['sc']['ezbizi_google_map']([]), '');

echo PHP_EOL . "The page title comes from WordPress, and only when there is one" . PHP_EOL;
reset_site();
serve_preset_payload();

$GLOBALS['post_id'] = 12;
$GLOBALS['post_title'] = 'Chimney Sweep in Caldwell, NJ';
check('the title of the page it is on', $GLOBALS['sc']['ezbizi_post_title']([]), 'Chimney Sweep in Caldwell, NJ');

// A title has already been through `the_title`, so it arrives as HTML.
// Escaping it straight would print the entity on the page.
$GLOBALS['post_title'] = 'Sweeps &amp; Repairs';
check('an entity is a character, not four', $GLOBALS['sc']['ezbizi_post_title']([]), 'Sweeps &amp; Repairs');

/*
 * A footer, an archive and a 404 all render with no post in scope, and
 * `get_the_title()` with nothing to read answers with whatever was set up last
 * — which is how a footer ends up captioned with an unrelated page.
 */
$GLOBALS['post_id'] = false;
$GLOBALS['post_title'] = 'Whatever WordPress set up last';
check('outside the loop, nothing', $GLOBALS['sc']['ezbizi_post_title']([]), '');

echo PHP_EOL . "The hours table, and the sentence it falls back to" . PHP_EOL;
reset_site();
serve_preset_payload([
    'hours' => 'Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed',
    'timezone' => 'America/New_York',
]);

$table = $GLOBALS['sc']['ezbizi_hours_table']([]);

check('a table', str_contains($table, '<table'), true);
check('seven days of it', substr_count($table, '<tr'), 7);
// The customer typed 8:00 AM into the grid and [ezbizi_hours] renders 8:00 AM.
// A table beside it saying 08:00 would read as different hours.
check('on the clock the line uses', str_contains($table, '8:00 AM – 5:00 PM'), true);
check('a closed day says so', str_contains($table, 'Closed'), true);
check('and exactly one day is today', substr_count($table, 'aria-current="true"'), 1);

$tight = $GLOBALS['sc']['ezbizi_hours_table'](['preset' => 'tight']);
check('a named preset tightens it', str_contains($tight, 'font-size:.92em'), true);
check('and can turn today off', str_contains($tight, 'aria-current'), false);
check(
    'an unknown name is the default',
    substr_count($GLOBALS['sc']['ezbizi_hours_table'](['preset' => 'tigt']), 'aria-current="true"'),
    1
);

/*
 * The case the whole fallback exists for. "By appointment, November to March"
 * is a real value real sites hold, `HoursWeek` refuses to guess a week out of
 * it, and `hours_week` is therefore absent — so a site that never touched the
 * grid must still render its hours.
 */
reset_site();
serve_preset_payload(['hours' => 'By appointment, November to March'], [], ['hours_week' => null]);

check(
    'no week, so the sentence as written',
    $GLOBALS['sc']['ezbizi_hours_table']([]),
    'By appointment, November to March'
);
check(
    'which is exactly what [ezbizi_hours] renders',
    $GLOBALS['sc']['ezbizi_hours_table']([]),
    $GLOBALS['sc']['ezbizi_hours']([])
);

// And with neither a week nor a line, nothing — not an empty table.
reset_site();
serve_preset_payload([], [], ['hours_week' => null]);
check('no week and no line is nothing', $GLOBALS['sc']['ezbizi_hours_table']([]), '');

echo PHP_EOL . "The city list is built from the pages themselves" . PHP_EOL;
reset_site();
serve_preset_payload(['phone' => '555-0100'], city_pages());

$cities = $GLOBALS['sc']['ezbizi_city_list']([]);

check('four towns', substr_count($cities, '<li>'), 4);
check('a page with no city is not a town', str_contains($cities, 'about-us'), false);
check('linked to its own page', str_contains($cities, 'href="https://a-1chimney.com/chimney-sweep-caldwell"'), true);
check('the state rides along, because two states have a Washington', str_contains($cities, 'Caldwell, NJ'), true);
check('grouped by the label the customer chose', str_contains($cities, 'North Jersey'), true);
check('every group of it', str_contains($cities, 'South Jersey'), true);
check('in three columns by default', str_contains($cities, 'columns:3'), true);

/*
 * Ungrouped last, and not interleaved. A town with no group has nowhere
 * honest to be filed, and putting it under the first heading would file it
 * somewhere wrong rather than nowhere.
 */
check(
    'a town with no group comes after the groups',
    strpos($cities, 'Dover') > strpos($cities, 'South Jersey'),
    true
);

// Sorted, both levels: the payload's order is database order, and a list of
// towns that reshuffles itself when somebody edits a page is a list nobody
// trusts.
check('towns in order inside a group', strpos($cities, 'Caldwell') < strpos($cities, 'Montclair'), true);
check('and the groups in order', strpos($cities, 'North Jersey') < strpos($cities, 'South Jersey'), true);

$plain = $GLOBALS['sc']['ezbizi_city_list'](['preset' => 'plain']);
check('a named preset can drop the grouping', str_contains($plain, 'North Jersey'), false);
check('and the links', str_contains($plain, '<a href'), false);
check('while still listing every town', substr_count($plain, '<li>'), 4);
check('and no columns', str_contains($plain, 'columns:'), false);

$folded = $GLOBALS['sc']['ezbizi_city_list'](['preset' => 'folded']);
// `<details>` because the accordion has to work with JavaScript off, and
// because the alternative is enqueuing a script for a fold.
check('the accordion folds with no script', str_contains($folded, '<details'), true);
$searchable = $GLOBALS['sc']['ezbizi_city_list'](['search' => 'yes', 'count' => 'yes']);
check('a search box over the towns', str_contains($searchable, 'class="ezbizi-cities-search"'), true);
check('a count beside each group', (bool) preg_match('/ezbizi-cities-count">\(\d+\)/', $searchable), true);
check('the state can be left off', str_contains($GLOBALS['sc']['ezbizi_city_list'](['show_state' => 'no']), ', NJ<'), false);
check('each group is a panel', str_contains($folded, '<summary class="ezbizi-cities-label">North Jersey</summary>'), true);
check('and nothing is enqueued to make it work', str_contains($folded, '<script'), false);
check('two pages about one town list it once', substr_count($folded, 'Vineland, NJ'), 1);
check('a town in no group gets its own panel when groups fold', str_contains($folded, '<summary class="ezbizi-cities-label">Other areas</summary>'), true);
$a1like = $GLOBALS['sc']['ezbizi_city_list'](['preset' => 'folded', 'heading_tag' => 'h3', 'other_label' => 'Ungrouped']);
check('a group name can be a heading, as A1 drew it', str_contains($a1like, '<summary class="ezbizi-cities-label"><h3 class="ezbizi-cities-heading">North Jersey</h3></summary>'), true);
check('and the panel keeps A1 its name', str_contains($a1like, '>Ungrouped</h3></summary>'), true);

check(
    'an unknown name renders the default',
    str_contains($GLOBALS['sc']['ezbizi_city_list'](['preset' => 'foldd']), 'columns:3'),
    true
);

// An accordion with no groups is a list behind a button, so it is a list.
reset_site();
serve_preset_payload([], ['/chimney-sweep-dover' => ['city' => 'Dover', 'state' => 'DE']]);
$unfoldable = $GLOBALS['sc']['ezbizi_city_list'](['preset' => 'folded']);
check('nothing to fold, so nothing folds', str_contains($unfoldable, '<details'), false);
check('and the town still renders', str_contains($unfoldable, 'Dover, DE'), true);

// A site with no town pages at all draws nothing rather than an empty list.
reset_site();
serve_preset_payload([], ['/about-us' => ['about_us_field_1' => 'Not a town.']]);
check('no towns, no list', $GLOBALS['sc']['ezbizi_city_list']([]), '');

echo PHP_EOL . "Nothing a customer stored can execute on their website" . PHP_EOL;
/*
 * These five widgets print values, page paths, field labels and a post title
 * into markup, which is more surfaces than every earlier shortcode put
 * together. The value below is the one somebody gets in through the API, an
 * imported spreadsheet or a franchise's own screen, and it renders on 110
 * websites we do not run.
 *
 * Two assertions per case on purpose. "No <script>" alone would pass on a
 * widget that quietly dropped the value, so the escaped form has to be there
 * too — the customer's text still has to appear, as text.
 */
$nasty = '"><script>alert(1)</script>';

reset_site();
serve_preset_payload([
    'company_name' => $nasty,
    'facebook' => 'https://www.facebook.com/a1chimney',
    'latitude' => '40.8398',
    'longitude' => '-74.2765',
    'hours' => $nasty,
    'timezone' => 'America/New_York',
], ['/chimney-sweep-caldwell' => ['city' => $nasty, 'group' => $nasty]]);

$GLOBALS['post_id'] = 12;
$GLOBALS['post_title'] = $nasty;

foreach (['social_links', 'google_map', 'post_title', 'hours_table', 'city_list'] as $tag) {
    $out = $GLOBALS['sc']['ezbizi_'.$tag]([]);
    check("[ezbizi_{$tag}] opens no script tag", str_contains($out, '<script'), false);
    check("[ezbizi_{$tag}] closes no attribute", str_contains($out, $nasty), false);
}

// And the escaped text is actually on the page, so none of the above passed by
// dropping the value.
check('the title is shown, escaped', str_contains($GLOBALS['sc']['ezbizi_post_title']([]), '&lt;script&gt;'), true);
check('the map is named, escaped', str_contains($GLOBALS['sc']['ezbizi_google_map']([]), '&lt;script&gt;'), true);
check('the town is listed, escaped', str_contains($GLOBALS['sc']['ezbizi_city_list']([]), '&lt;script&gt;'), true);

// The hours line is the one case where escaping is the entire output, so it is
// compared against what [ezbizi_hours] does with the same value.
reset_site();
serve_preset_payload(['hours' => $nasty], [], ['hours_week' => null]);
check(
    'the hours sentence is escaped like every other value',
    $GLOBALS['sc']['ezbizi_hours_table']([]),
    $GLOBALS['sc']['ezbizi_hours']([])
);
check('and carries no markup', str_contains($GLOBALS['sc']['ezbizi_hours_table']([]), '<script'), false);

/*
 * A social value that is not a web address is DROPPED rather than drawn with
 * an empty href. `esc_url` is both the gate and the filter here: a
 * `javascript:` scheme or something pasted into the wrong box comes back
 * empty, and a link that goes nowhere is worse than no link.
 */
reset_site();
serve_preset_payload([
    'facebook' => 'javascript:alert(1)',
    'instagram' => 'https://www.instagram.com/a1chimney',
]);
$guarded = $GLOBALS['sc']['ezbizi_social_links']([]);
check('one link, not two', substr_count($guarded, '<a class="ezbizi-social-link'), 1);
check('and it is the real one', str_contains($guarded, 'instagram.com/a1chimney'), true);
check('no javascript scheme survives', str_contains($guarded, 'javascript:'), false);

echo PHP_EOL . "The new value keys needed no plugin change" . PHP_EOL;
/*
 * `phone_secondary`, `email_secondary`, `review_link`, `latitude` and
 * `longitude` arrived in the catalogue with no plugin release, which is the
 * property the field loop exists for. `review_link` is the one worth asserting
 * rather than assuming: it ends in `_link`, so it goes through `esc_url` — and
 * a link through `esc_html` turns the `&` in a query string into `&amp;` and
 * does nothing about a `javascript:` scheme.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => json_encode([
    'site' => ['host' => 'a-1chimney.com', 'label' => null, 'company' => 'A-1'],
    'fields' => [
        ['key' => 'phone_secondary', 'label' => 'Second phone number', 'hint' => '', 'section' => 'general', 'shortcode' => '[ezbizi_phone_secondary]'],
        ['key' => 'email_secondary', 'label' => 'Second email address', 'hint' => '', 'section' => 'general', 'shortcode' => '[ezbizi_email_secondary]'],
        ['key' => 'review_link', 'label' => 'Leave-a-review link', 'hint' => '', 'section' => 'general', 'shortcode' => '[ezbizi_review_link]'],
        ['key' => 'latitude', 'label' => 'Latitude', 'hint' => '', 'section' => 'general', 'shortcode' => '[ezbizi_latitude]'],
        ['key' => 'longitude', 'label' => 'Longitude', 'hint' => '', 'section' => 'general', 'shortcode' => '[ezbizi_longitude]'],
    ],
    'values' => [
        'phone_secondary' => '555-0199',
        'email_secondary' => 'office@a-1chimney.com',
        'review_link' => 'https://g.page/r/a1chimney/review?utm_source=footer&utm_medium=link',
        'latitude' => '40.8398',
        'longitude' => '-74.2765',
    ],
    'pages' => [],
])];
ezbizi_fetch();
do_action('init');

check('a second phone renders', $GLOBALS['sc']['ezbizi_phone_secondary']([]), '555-0199');
check('a second email renders', $GLOBALS['sc']['ezbizi_email_secondary']([]), 'office@a-1chimney.com');
check('latitude renders', $GLOBALS['sc']['ezbizi_latitude']([]), '40.8398');
check('longitude renders', $GLOBALS['sc']['ezbizi_longitude']([]), '-74.2765');

check('a review link is a link', ezbizi_is_link_key('review_link'), true);
check(
    'so its query string survives intact',
    $GLOBALS['sc']['ezbizi_review_link']([]),
    'https://g.page/r/a1chimney/review?utm_source=footer&utm_medium=link'
);

echo PHP_EOL . "The review faces: happy to the review link, sad to Leads" . PHP_EOL;
reset_site();
// No review_faces preset in the payload, so this is the fallback a site
// renders with between a plugin rollout and the app release that knows it.
serve_preset_payload(
    ['review_link' => 'https://g.page/r/a1chimney/review'],
    [],
    ['public_key' => 'pk_testkey']
);

$faces = $GLOBALS['sc']['ezbizi_review_faces']([]);
check('it asks the question', str_contains($faces, 'How was your experience?'), true);
check('two faces', substr_count($faces, 'class="ezbizi-rf-face"'), 2);
check('happy goes to the review link', str_contains($faces, 'href="https://g.page/r/a1chimney/review"'), true);
check('in a new tab', str_contains($faces, 'target="_blank" rel="noopener noreferrer"'), true);
check('sad posts to the form endpoint', str_contains($faces, "api+'/api/v1/web/form'"), true);
check('with the robot check solved first', str_contains($faces, 'ezSolve(api)') && str_contains($faces, 'function ezSolve(api)'), true);
check('with the site key', str_contains($faces, 'pk_testkey'), true);
check('the button wears the accent', str_contains($faces, 'background:#aa0000'), true);
check('light by default', str_contains($faces, 'color:#fff}'), false);
// Jose, 2026-10-05: a review reaches the customer's record for the Membership offer.
check('the review is told to EzBizi', str_contains($faces, "/api/v1/web/review'"), true);
check('with the personal link when the page has one', str_contains($faces, "get('c')") && str_contains($faces, 'if(c)v.c=c'), true);
// Jose, 2026-10-13: the green face goes straight to Google, with no step between.
check('the green face goes straight to Google', str_contains($faces, "if(go==='happy'&&google){if(c)tell({rating:'happy'});track('google');location.href=google;return;}"), true);
check('to the review link', str_contains($faces, 'google="https:\/\/g.page\/r\/a1chimney\/review"'), true);
check('and says so, without naming Google', str_contains($faces, 'Share your experience with us<') && ! str_contains($faces, 'Tap to share it on Google'), true);
check('the review is told even as the page leaves', str_contains($faces, 'keepalive:true'), true);
// Jose, 2026-10-15: the link's activity, for the Customer Experience report.
check('the link reports what was done with it', str_contains($faces, "api+'/api/v1/web/review-link'"), true);
check('only when the page came by a link', str_contains($faces, 'function track(k){if(!c)return;'), true);
check('opening it', str_contains($faces, "track('opened');"), true);
check('which face', str_contains($faces, 'track(go);'), true);
check('going on to Google', str_contains($faces, "track('google');location.href=google"), true);
check('and a sent complaint', str_contains($faces, "track('submitted');show('sent')"), true);
check('and no membership offer on the page (Jose, 2026-10-08: it is a text of its own)', str_contains($faces, "'/api/v1/web/membership/join'"), false);
check('without one it asks, and can be skipped', str_contains($faces, 'data-step="ask"') && str_contains($faces, 'ezbizi-rf-skip'), true);
check('a sad face is told too, with what happened', str_contains($faces, "tell({rating:'sad'"), true);
// Jose, 2026-10-13: ask for detail, then apologise and stop; no Google offer after a complaint.
check('the sad form asks for detail', str_contains($faces, 'Please be as descriptive as possible.'), true);
check('after a complaint, no review button', (bool) preg_match('~data-step="sent".*?ezbizi-rf-btn~s', $faces), false);
check('and the thanks apologises', str_contains($faces, 'investigate the issue'), true);

reset_site();
serve_preset_payload([], [], ['public_key' => 'pk_testkey']);
$no_link = $GLOBALS['sc']['ezbizi_review_faces']([]);
check('no review link, no button to nowhere', str_contains($no_link, 'ezbizi-rf-btn" href'), false);
check('no review link: the green face keeps the old path', str_contains($no_link, 'google=""') && str_contains($no_link, 'Happy with the visit'), true);

// On the reviews page itself, a review link to that page is not a way out.
reset_site();
serve_preset_payload(['review_link' => 'https://a-1chimney.com/reviews/'], [], ['public_key' => 'pk_testkey']);
$_SERVER['REQUEST_URI'] = '/reviews/';
check('a review link to this very page is not drawn', str_contains($GLOBALS['sc']['ezbizi_review_faces']([]), 'ezbizi-rf-btn" href'), false);
reset_site();
serve_preset_payload(['review_link' => 'https://a-1chimney.com/reviews/', 'google_review_link' => 'https://g.page/r/a1/review'], [], ['public_key' => 'pk_testkey']);
$_SERVER['REQUEST_URI'] = '/reviews/';
check('the Google review link is used first', str_contains($GLOBALS['sc']['ezbizi_review_faces']([]), 'href="https://g.page/r/a1/review"'), true);
$_SERVER['REQUEST_URI'] = '/';
check('the sad form still works', str_contains($no_link, "api+'/api/v1/web/form'"), true);

reset_site();
serve_preset_payload(['review_link' => 'https://g.page/r/a1chimney/review']);
check('no browser key, no form a visitor cannot send', str_contains($GLOBALS['sc']['ezbizi_review_faces']([]), '<form'), false);

echo PHP_EOL . "Team, branches and the store locator draw EzBizi's lists" . PHP_EOL;
reset_site();
serve_preset_payload([], [], [
    'team' => [
        ['name' => 'Ana Ruiz', 'role' => 'Technician', 'bio' => 'Twelve years on roofs.', 'photo_url' => 'https://x.test/ana.jpg', 'category' => 'Field'],
        ['name' => 'Bo Lee', 'role' => 'Dispatcher', 'bio' => '', 'photo_url' => '', 'category' => 'Office'],
        ['name' => '<script>x</script>', 'role' => '', 'bio' => '', 'photo_url' => '', 'category' => 'Office'],
    ],
    'locations' => [
        'site' => [['name' => 'North Jersey', 'phone' => '(973) 555-0100', 'address_line1' => '1 Main St', 'city' => 'Newark', 'state' => 'NJ', 'postal_code' => '07101', 'latitude' => 40.73, 'longitude' => -74.17, 'maps_url' => '']],
        'company' => [
            ['name' => 'Atlanta', 'state' => 'GA', 'city' => 'Atlanta', 'phone' => '404-555-0100', 'image_url' => 'https://x.test/atl.jpg', 'website_url' => 'https://atl.a-1chimney.com/'],
            ['name' => 'Austin', 'state' => 'TX', 'city' => 'Austin'],
        ],
    ],
]);

$team = $GLOBALS['sc']['ezbizi_team']([]);
check('everybody on the list', substr_count($team, 'class="ezbizi-team-member"'), 3);
check('under their headings, since there are two', substr_count($team, 'class="ezbizi-team-heading"'), 2);
check('a name is text, never markup', str_contains($team, '<script>x'), false);
check('three columns by default', str_contains($team, 'repeat(3,'), true);

$branches = $GLOBALS['sc']['ezbizi_branches']([]);
check('the branch this site answers for', str_contains($branches, 'North Jersey'), true);
check('not the company\'s other stores', str_contains($branches, 'Atlanta'), false);
check('its phone number', str_contains($branches, '(973) 555-0100'), true);
check('a map at its coordinates', str_contains($branches, 'q=40.73%2C-74.17') || str_contains($branches, 'q=40.73,-74.17'), true);
check('and a way there, searched when there is no Maps link', str_contains($branches, 'google.com/maps/dir/'), true);

$stores = $GLOBALS['sc']['ezbizi_store_locator']([]);
check('every store', substr_count($stores, 'class="ezbizi-store"'), 2);
check('a state filter, since there are two states', str_contains($stores, '<option value="GA">'), true);
check('a store\'s picture', str_contains($stores, 'atl.jpg'), true);

reset_site();
serve_preset_payload();
check('no team, nothing drawn', $GLOBALS['sc']['ezbizi_team']([]), '');
check('no branches, nothing drawn', $GLOBALS['sc']['ezbizi_branches']([]), '');
check('no stores, nothing drawn', $GLOBALS['sc']['ezbizi_store_locator']([]), '');

echo PHP_EOL . "Services, reviews, before-and-after and FAQ draw EzBizi's lists" . PHP_EOL;
reset_site();
serve_preset_payload([], [], [
    'items' => [
        'service' => [
            ['title' => 'Chimney Sweep', 'body' => 'Every flue, cleaned.', 'image_url' => 'https://x.test/sweep.jpg', 'icon' => 'chimney', 'link_url' => 'https://x.test/sweep/', 'category' => 'Chimney'],
            ['title' => 'Door Repair', 'body' => 'Springs and rollers.', 'category' => 'Doors'],
        ],
        'review' => [
            ['title' => 'Pat L.', 'body' => 'Quick and tidy.', 'rating' => 5, 'dated' => '2026-09-01', 'subtitle' => 'Google'],
            ['title' => 'Sam K.', 'body' => 'Late but fine.', 'rating' => 3],
        ],
        'before_after' => [['title' => 'Crown rebuild', 'image_url' => 'https://x.test/b.jpg', 'image2_url' => 'https://x.test/a.jpg']],
        'faq' => [['title' => 'Do you sweep in winter?', 'body' => 'Yes, all year.', 'category' => 'General']],
    ],
    'reviews' => [['title' => 'Google Person', 'body' => 'From Google.', 'rating' => 5]],
]);
$services = $GLOBALS['sc']['ezbizi_services']([]);
check('every service', substr_count($services, 'class="ezbizi-service"'), 2);
check('its icon is inline markup', str_contains($services, '<svg'), true);
check('a service links to its page', str_contains($services, 'href="https://x.test/sweep/"'), true);
$reviews = $GLOBALS['sc']['ezbizi_reviews']([]);
check('featured reviews first, so not Google\'s', str_contains($reviews, 'From Google.'), false);
check('below the lowest rating asked for, left out', str_contains($reviews, 'Late but fine.'), false);
check('five stars as a label a screen reader says', str_contains($reviews, 'aria-label="5 out of 5"'), true);
$ba = $GLOBALS['sc']['ezbizi_before_after']([]);
check('a pair to compare', str_contains($ba, 'b.jpg') && str_contains($ba, 'a.jpg'), true);
check('by dragging, which a keyboard can do too', str_contains($ba, 'type="range"'), true);
$faq = $GLOBALS['sc']['ezbizi_faq']([]);
check('a question that opens', str_contains($faq, '<summary class="ezbizi-faq-question">Do you sweep in winter?</summary>'), true);
check('with the FAQ markup search engines read', str_contains($faq, '"@type":"FAQPage"'), true);
reset_site();
serve_preset_payload();
check('no services, nothing drawn', $GLOBALS['sc']['ezbizi_services']([]), '');
check('no FAQs, nothing drawn', $GLOBALS['sc']['ezbizi_faq']([]), '');

echo PHP_EOL . "Elementor can find the widgets" . PHP_EOL;
/*
 * Jose typed "EzBizi" into Elementor's Elements panel and got "No widget
 * found". The shortcodes had worked since 1.2.0 and that panel cannot see a
 * shortcode — it lists registered Elementor widgets and nothing else. So on an
 * Elementor site, which is most of them, we had no widget at all as far as
 * anybody could tell.
 *
 * This runs LAST, and the no-Elementor case runs before the stub is loaded:
 * once \Elementor\Widget_Base exists it cannot be made not to exist.
 */
$manager = null;
do_action('elementor/widgets/register', $manager);
check('a site without Elementor registers nothing, and does not fatal', $manager, null);

require_once __DIR__ . '/elementor-stub.php';
elementor_present();

$manager = new Elementor_Widgets_Manager;
do_action('elementor/widgets/register', $manager);

check('every widget is registered', count($manager->registered), 21);
check('the form, under a name Elementor can address', isset($manager->registered['ezbizi-form']), true);
check('the chat too', isset($manager->registered['ezbizi-chat']), true);

foreach (['social-links', 'google-map', 'post-title', 'hours-table', 'city-list'] as $name) {
    check("and ezbizi-{$name}", isset($manager->registered['ezbizi-'.$name]), true);
}

$chat = $manager->registered['ezbizi-chat'];
$form = $manager->registered['ezbizi-form'];

// The search that failed. Elementor matches the title and the keywords, so
// this is the check that stands between us and "No widget found" again.
check('the title says whose it is', str_contains($chat->get_title(), 'EzBizi'), true);
check('and searching ezbizi finds it', in_array('ezbizi', $chat->get_keywords(), true), true);
check('the form as well', in_array('ezbizi', $form->get_keywords(), true), true);

// Nothing configurable: one notice pointing at the one place that decides.
$chat->ezbizi_call_register_controls();
check('one control, and it is the notice', count($chat->ezbizi_controls), 1);
check(
    'which names where the settings live',
    str_contains($chat->ezbizi_controls['ezbizi_notice']['raw'] ?? '', 'Widgets'),
    true
);

echo PHP_EOL . "The editor does not open chats by being opened" . PHP_EOL;
/*
 * The chat widget starts a conversation and polls every five seconds. Rendered
 * live in the Elementor editor it would file real conversations against the
 * customer's account every time somebody opened the page to move a heading, and
 * those arrive in Leads looking like enquiries from nobody.
 */
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;

elementor_present(true);
ob_start();
$chat->ezbizi_call_render();
$editing = ob_get_clean();

check('a placeholder, named', str_contains($editing, 'EzBizi Live Chat'), true);
check('and no chat script', str_contains($editing, 'setInterval'), false);

elementor_present(false);
ob_start();
$chat->ezbizi_call_render();
$live = ob_get_clean();

// The control: if render() were broken rather than merely guarded, the check
// above would pass for the wrong reason.
check('on a real page it is the widget', str_contains($live, 'Talk to A-1'), true);

echo PHP_EOL . "The presentation widgets carry one control, and it is a name" . PHP_EOL;
/*
 * A size control here would be a second place to set an icon size, disagreeing
 * with EzBizi on the one page somebody edited. A preset NAME is not a second
 * place: it points at a bundle that is still edited once and still reaches
 * every site at once.
 */
reset_site();
serve_preset_payload([
    'facebook' => 'https://www.facebook.com/a1chimney',
    'instagram' => 'https://www.instagram.com/a1chimney',
], city_pages());
$GLOBALS['is_admin'] = false;

$social_widget = $manager->registered['ezbizi-social-links'];
$social_widget->ezbizi_call_register_controls();

check('the preset name comes first', array_key_first($social_widget->ezbizi_controls), 'ezbizi_preset');
check('then each option, for this widget only', isset($social_widget->ezbizi_controls['ezo_size'], $social_widget->ezbizi_controls['ezo_labels']), true);
check('a number as a number', $social_widget->ezbizi_controls['ezo_size']['type'] ?? '', \Elementor\Controls_Manager::NUMBER);
check('empty unless somebody sets it', $social_widget->ezbizi_controls['ezo_size']['default'] ?? null, '');
check(
    'a free-text field, not a copy of the preset',
    $social_widget->ezbizi_controls['ezbizi_preset']['type'] ?? '',
    \Elementor\Controls_Manager::TEXT
);
check(
    'which says where the presets are kept',
    str_contains($social_widget->ezbizi_controls['ezbizi_preset']['description'] ?? '', 'Widgets'),
    true
);

elementor_present(false);

// Empty is the normal case: dropped on the canvas and never touched, it draws
// the default rather than nothing.
$social_widget->ezbizi_settings = [];
ob_start();
$social_widget->ezbizi_call_render();
$drawn = ob_get_clean();
check('no name typed, so the default row', str_contains($drawn, 'border-radius:999px'), true);

$social_widget->ezbizi_settings = ['ezbizi_preset' => 'footer'];
ob_start();
$social_widget->ezbizi_call_render();
check('and the name reaches the shortcode', str_contains(ob_get_clean(), 'height:40px'), true);

$social_widget->ezbizi_settings = ['ezbizi_preset' => '', 'ezo_size' => '48', 'ezo_labels' => ''];
ob_start();
$social_widget->ezbizi_call_render();
$overridden = ob_get_clean();
check('an Elementor override reaches the shortcode', str_contains($overridden, 'width:48px'), true);
check('and an empty one leaves the preset alone', str_contains($overridden, 'ezbizi-social-label'), false);

// The editor gets a still picture of every one of them, for the reason the
// chat does: an editor that renders live widgets makes real requests.
elementor_present(true);

foreach (['social-links', 'google-map', 'post-title', 'hours-table', 'city-list'] as $name) {
    $widget = $manager->registered['ezbizi-'.$name];
    ob_start();
    $widget->ezbizi_call_render();
    $placeholder = ob_get_clean();

    check("ezbizi-{$name} shows a named placeholder in the editor", str_contains($placeholder, 'EzBizi'), true);
}

elementor_present(false);

// And the published page is the shortcode, so there is one implementation of
// each widget rather than two that drift.
$GLOBALS['post_id'] = 12;
$GLOBALS['post_title'] = 'Chimney Sweep in Caldwell, NJ';

ob_start();
$manager->registered['ezbizi-city-list']->ezbizi_call_render();
check('the city list draws on a real page', str_contains(ob_get_clean(), 'North Jersey'), true);

ob_start();
$manager->registered['ezbizi-post-title']->ezbizi_call_render();
check('and the page title', ob_get_clean(), 'Chimney Sweep in Caldwell, NJ');

echo PHP_EOL . "Contact details carry A1's sections" . PHP_EOL;
reset_site();
serve_preset_payload([
    'phone' => '(888) 984-4344', 'phone_secondary' => '(888) 111-2222', 'email' => 'office@a1.test',
    'hours' => "Mon\u{2013}Fri 8:00 AM \u{2013} 5:00 PM \u{00B7} Sat closed",
]);
$contact = fn ($atts) => $GLOBALS['sc']['ezbizi_contact_info']($atts);
$withHours = $contact(['hours' => 'yes', 'closed_text' => 'Shut']);
check('hours as a section', str_contains($withHours, 'class="ezbizi-contact-day">Mon'), true);
check('with the closed wording chosen', str_contains($withHours, '>Shut<'), true);
$ordered = $contact(['order' => 'email,phone']);
check('in the order asked for', strpos($ordered, 'office@a1.test') < strpos($ordered, '984-4344'), true);
check('the second number when asked', str_contains($contact(['secondary' => 'yes']), '111-2222'), true);
check('and not otherwise', str_contains($contact([]), '111-2222'), false);
check('links can be switched off', str_contains($contact(['links' => 'no']), 'mailto:'), false);
$more = $contact(['labels' => 'yes', 'extra_phones' => 'Fax | 555-0101; 555-0102', 'phone_icon' => 'none']);
check('more numbers than two', str_contains($more, 'tel:5550101') && str_contains($more, 'tel:5550102'), true);
check('each with its own label', str_contains($more, '>Fax<'), true);
check('a section can drop its icon', substr_count($more, 'ezbizi-contact-phone"><span class="ezbizi-contact-icon"'), 0);

echo PHP_EOL . "The call to action does what Toula's list asks" . PHP_EOL;
reset_site();
serve_preset_payload(['phone' => '(888) 984-4344', 'email' => 'office@a1.test', 'review_link' => 'https://g.page/r/a1/review']);
$cta = fn ($atts) => $GLOBALS['sc']['ezbizi_cta']($atts);
check('the number itself, as A1 showed it', str_contains($cta(['label' => 'value']), '>(888) 984-4344<'), true);
check('email opens a message', str_contains($cta(['action' => 'email']), 'href="mailto:office@a1.test"'), true);
check('review goes to the review link', str_contains($cta(['action' => 'review']), 'g.page/r/a1/review'), true);
check('WhatsApp takes the number with its country code', str_contains($cta(['action' => 'whatsapp']), 'https://wa.me/18889844344'), true);
check('a custom link, with tracking added', str_contains($cta(['action' => 'url', 'url' => 'https://x.test/deal', 'url_params' => 'utm_source=cta']), 'https://x.test/deal?utm_source=cta'), true);
check('a custom link that is not a web address draws nothing', $cta(['action' => 'url', 'url' => 'javascript:alert(1)']), '');
check('and it can open in a new tab', str_contains($cta(['action' => 'url', 'url' => 'https://x.test/', 'new_tab' => 'yes']), 'target="_blank"'), true);
check('the icon can sit after the words', (bool) preg_match('/ezbizi-cta-text.*ezbizi-cta-icon/s', $cta(['icon' => 'yes', 'icon_position' => 'right'])), true);
$moving = $cta(['animation' => 'pulse']);
check('an animation runs', str_contains($moving, '@keyframes ezbizi-pulse-'), true);
check('except for a visitor who asked for less motion', str_contains($moving, 'prefers-reduced-motion'), true);
check('a bar on phones', str_contains($cta(['mobile' => 'bar']), 'position:fixed'), true);

echo PHP_EOL . "Phone, hours, map and page title take Toula's options" . PHP_EOL;
reset_site();
serve_preset_payload(['phone' => '8889844344', 'phone_secondary' => '(888) 111-2222', 'latitude' => '40.7', 'longitude' => '-74.1', 'city' => 'Newark', 'state' => 'NJ', 'timezone' => 'America/New_York']);
$sc = $GLOBALS['sc'];
check('the second number when asked', str_contains($sc['ezbizi_phone_box'](['which' => 'secondary']), '111-2222'), true);
check('written with parentheses', str_contains($sc['ezbizi_phone_box'](['format' => 'parentheses']), '(888) 984-4344'), true);
check('a tap can text instead', str_contains($sc['ezbizi_phone_box'](['link' => 'yes', 'link_type' => 'sms']), 'href="sms:'), true);
check('a satellite map', str_contains($sc['ezbizi_google_map'](['map_type' => 'satellite']), '&amp;t=k') || str_contains($sc['ezbizi_google_map'](['map_type' => 'satellite']), '&t=k'), true);
check('a way there under the map', str_contains($sc['ezbizi_google_map'](['directions' => 'yes']), 'ezbizi-map-directions'), true);
check('the title as a heading', str_contains($sc['ezbizi_post_title'](['tag' => 'h2', 'format' => 'city_state']), '>Newark, NJ</h2>'), true);
check('and bare words by default', str_contains($sc['ezbizi_post_title'](['format' => 'city']), '<'), false);
$week = $sc['ezbizi_hours_table'](['layout' => 'stack', 'days' => 'long', 'clock' => '24', 'status' => 'yes', 'show_closed' => 'no']);
check('hours as a stack', str_contains($week, 'ezbizi-hours-list'), true);
check('days in full', str_contains($week, 'Monday'), true);
check('a 24-hour clock', (bool) preg_match('/\b\d{2}:\d{2} – \d{2}:\d{2}\b/u', $week), true);
check('an open-now badge worked out in the browser', str_contains($week, 'ezbizi-hours-badge'), true);

echo PHP_EOL . "A1's look survives the swap" . PHP_EOL;
check('hours have a clock, not a pin', str_contains(ezbizi_contact_icon('hours'), 'a10 10 0 100 20'), true);
check('a network with a mark draws the mark', str_contains(ezbizi_social_icon('tiktok', 'TikTok'), '<path fill="currentColor"'), true);
check('one without keeps its letters', str_contains(ezbizi_social_icon('bbb', 'BBB'), '>B</text>'), true);

echo PHP_EOL . "The site's own Elementor forms reach Leads" . PHP_EOL;
$record = new class {
    public function get($k) {
        return $k === 'fields' ? [
            'first_name' => ['type' => 'text', 'title' => 'First Name', 'value' => 'Dana'],
            'last_name' => ['type' => 'text', 'title' => 'Last Name', 'value' => 'Reyes'],
            'email' => ['type' => 'email', 'title' => 'Email', 'value' => 'dana@example.com'],
            'phone' => ['type' => 'tel', 'title' => 'Phone', 'value' => '(555) 010-0100'],
            'message' => ['type' => 'textarea', 'title' => 'Message', 'value' => 'Chimney leaking'],
            'trap' => ['type' => 'honeypot', 'title' => '', 'value' => ''],
        ] : [];
    }
};
$GLOBALS['opt']['ezbizi_token'] = 'tok_123';
$GLOBALS['post_calls'] = 0;
$GLOBALS['actions']['shutdown'] = [];
ezbizi_elementor_form_record($record);
check('nothing is sent while the visitor waits', $GLOBALS['post_calls'], 0);
do_action('shutdown');
$sent = $GLOBALS['last_post_args']['body'] ?? [];
check('it is sent after the page has answered', $GLOBALS['post_calls'], 1);
check('to the token-authenticated form endpoint', str_ends_with($GLOBALS['last_post_url'] ?? '', '/api/v1/web/plugin/form'), true);
check('with the site token', $GLOBALS['last_post_args']['headers']['Authorization'] ?? '', 'Bearer tok_123');
check('first and last name make the name', $sent['name'] ?? null, 'Dana Reyes');
check('the email and phone picked out', [$sent['email'] ?? null, $sent['phone'] ?? null], ['dana@example.com', '(555) 010-0100']);
check('every answer kept under its question', $sent['fields']['Message'] ?? null, 'Chimney leaking');
check('the spam trap is not an answer', array_key_exists('trap', $sent['fields'] ?? []), false);
check('a bad email is not sent as one', isset(ezbizi_elementor_form_lead(['e' => ['type' => 'email', 'title' => 'Email', 'value' => 'nope']])['email']), false);
check('an empty form sends nothing', ezbizi_elementor_form_lead(['m' => ['type' => 'text', 'title' => 'Message', 'value' => '']]), null);
check('an Elementor ZIP question is read as the ZIP', ezbizi_elementor_form_lead(['z' => ['type' => 'text', 'title' => 'Zip Code', 'value' => '06810-1234']])['zip'] ?? null, '06810');
$_POST['ezbizi_zip'] = '10001';
check('else the box the plugin added', ezbizi_elementor_form_lead(['m' => ['type' => 'text', 'title' => 'Message', 'value' => 'hi']])['fields']['ZIP code'] ?? null, '10001');
unset($_POST['ezbizi_zip']);
$GLOBALS['opt']['ezbizi_token'] = '';
$GLOBALS['actions']['shutdown'] = [];
ezbizi_elementor_form_record($record);
do_action('shutdown');
check('a site with no token sends nothing', $GLOBALS['post_calls'], 1);

echo PHP_EOL . "A form whose email fails still thanks the visitor" . PHP_EOL;
$mail = ['to' => 'info@x.test', 'subject' => 's', 'message' => 'm', 'headers' => '', 'attachments' => []];
$GLOBALS['opt']['ezbizi_token'] = 'tok';
$GLOBALS['doing_ajax'] = false;
check('other mail is left alone', ezbizi_form_mail(null, $mail), null);
$GLOBALS['doing_ajax'] = true;
$_POST = ['action' => 'elementor_pro_forms_send_form'];
$GLOBALS['mail_ok'] = false;
$GLOBALS['mail_calls'] = 0;
check('a failed form email is still a sent enquiry', ezbizi_form_mail(null, $mail), true);
check('because the email was still tried', $GLOBALS['mail_calls'], 1);
$GLOBALS['opt']['ezbizi_token'] = '';
check('but not on a site EzBizi does not hear from', ezbizi_form_mail(null, $mail), null);
$GLOBALS['doing_ajax'] = false;
$GLOBALS['mail_ok'] = true;
$_POST = [];

echo PHP_EOL . "An Elementor form without the robot check's answer is refused" . PHP_EOL;
$solve = function (array $c) {
    for ($n = 0; $n <= $c['max']; $n++) {
        if (hash('sha256', $c['salt'] . $n) === $c['challenge']) {
            return json_encode(['salt' => $c['salt'], 'number' => $n, 'challenge' => $c['challenge'], 'signature' => $c['signature']]);
        }
    }
};
$answer = $solve(ezbizi_challenge());
check('a solved puzzle passes', ezbizi_challenge_passes($answer), true);
check('but only once', ezbizi_challenge_passes($answer), false);
check('no answer is refused', ezbizi_challenge_passes(''), false);
$salt = 'x?expires=' . (time() + 60);
check('a puzzle this site did not sign is refused', ezbizi_challenge_passes(json_encode(['salt' => $salt, 'number' => 1,
    'challenge' => hash('sha256', $salt . '1'), 'signature' => str_repeat('0', 64)])), false);
$handler = fn () => new class {
    public $errors = [];
    public function add_error($f, $m = '') { $this->errors[$f] = $m; return $this; }
    public function add_error_message($m) { return $this; }
};
$bot = $handler();
$_POST = [];
do_action('elementor_pro/forms/validation', null, $bot);
check('a bot posting straight at the form is refused', isset($bot->errors['ezbizi_pow']), true);
$person = $handler();
$_POST = ['ezbizi_pow' => $solve(ezbizi_challenge())];
do_action('elementor_pro/forms/validation', null, $person);
check('a person whose browser solved it is not', $person->errors, []);
$_POST = [];

echo PHP_EOL . "The state in words, as A1 printed it" . PHP_EOL;
reset_site();
serve_preset_payload(['state' => 'NJ', 'state_link' => 'https://www.nj.gov/']);
check('the name from the code', $GLOBALS['sc']['ezbizi_state_name']([]), 'New Jersey');
check('linked to the state website', str_contains($GLOBALS['sc']['ezbizi_state_anchor']([]), '>New Jersey</a>'), true);
reset_site();
serve_preset_payload(['state' => 'Ontario']);
check('a name that is not a code prints as stored', $GLOBALS['sc']['ezbizi_state_name']([]), 'Ontario');
check('and without a link it is only the name', $GLOBALS['sc']['ezbizi_state_anchor']([]), 'Ontario');

echo PHP_EOL . "Street View draws only a Google Maps embed" . PHP_EOL;
$embed = 'https://www.google.com/maps/embed?pb=!4v1!6m8!1m7!1sAbc';
check('a link is used as it is', ezbizi_street_view_src($embed), $embed);
check('a pasted iframe is read for its src', ezbizi_street_view_src('<iframe src="' . $embed . '" width="600"></iframe>'), $embed);
check('any other site is refused', ezbizi_street_view_src('https://evil.test/maps/embed?x'), '');
check('a lookalike host is refused', ezbizi_street_view_src('https://google.com.evil.test/maps/embed'), '');
check('plain http is refused', ezbizi_street_view_src('http://www.google.com/maps/embed?pb=1'), '');
check('a Maps page that is not an embed is refused', ezbizi_street_view_src('https://www.google.com/maps/place/x'), '');

echo PHP_EOL . "The gallery and the video carousel draw the page's own content" . PHP_EOL;
// A1 Tools' setting names, so a page moves across by renaming its widget.
$gallery = ezbizi_gallery_render([
    'work_gallery' => [['id' => 1, 'url' => 'https://x.test/work1.jpg'], ['id' => 2, 'url' => 'https://x.test/work2.jpg']],
    'images' => [
        ['image' => ['url' => 'https://x.test/after.jpg'], 'category' => 'Chimney', 'before_image' => ['url' => 'https://x.test/before.jpg']],
        ['image' => ['url' => ''], 'category' => 'Skipped'],
    ],
    'bulk_groups' => [['category' => 'Dryer vent', 'gallery' => [['url' => 'https://x.test/d1.jpg'], ['url' => 'https://x.test/d2.jpg']]]],
    'per_page' => 4, 'columns' => 2,
    'projects_button_label' => 'View Our Projects', 'back_button_label' => 'Back to Our Work',
]);
check('our work comes first', strpos($gallery, 'data-view="work"') < strpos($gallery, 'data-view="projects"'), true);
check('and the projects wait behind a button', str_contains($gallery, 'data-view="projects" hidden'), true);
check('two work pictures and three projects, the empty row dropped', substr_count($gallery, '<figure class="ezg-item"'), 5);
check('a before picture sits beside its after', str_contains($gallery, 'before.jpg') && str_contains($gallery, 'ezg-pair'), true);
check('two categories, so a filter', str_contains($gallery, '<select'), true);
check('two columns', str_contains($gallery, 'repeat(2,'), true);
check('a number stored as an int is still the setting', str_contains($gallery, 'data-per="4"'), true);
check('nothing at all renders nothing', ezbizi_gallery_render([]), '');
check('a picture link cannot carry a script', str_contains(ezbizi_gallery_render(['images' => [['image' => ['url' => 'javascript:alert(1)']]]]), 'javascript:'), false);

$videos = ezbizi_video_carousel_render([
    'videos' => [
        ['video_url' => 'https://www.youtube.com/shorts/abcdefghijk', 'video_title' => 'Sweep'],
        ['video_url' => ['url' => 'https://youtu.be/ABCDEFGHIJK']],
        ['video_url' => 'https://vimeo.com/12345'],
    ],
    'slides_per_view' => '4', 'video_loop' => 'yes', 'carousel_autoplay' => 'yes',
]);
check('two YouTube videos, the Vimeo link skipped', substr_count($videos, '<figure class="ezv-card"'), 2);
check('a thumbnail, not a player, until tapped', str_contains($videos, 'i.ytimg.com/vi/abcdefghijk/') && ! str_contains($videos, '<iframe'), true);
check('the privacy-enhanced player', str_contains($videos, 'youtube-nocookie.com/embed/abcdefghijk'), true);
check('a looped video names itself as the playlist', str_contains($videos, 'playlist=abcdefghijk'), true);
check('two videos fit in four, so no arrows', str_contains($videos, 'class="ezv-arrow'), false);
check('no videos renders nothing', ezbizi_video_carousel_render(['videos' => [['video_url' => 'nope']]]), '');
$a1g = ezbizi_gallery_render(['image_ratio' => '16-9', 'page_color' => '#ffd500', 'work_gallery' => [['url' => 'https://a1.test/w.jpg']], 'images' => [['image' => ['url' => 'https://a1.test/a.jpg']]]]);
check('a gallery ratio crops every tile to it', str_contains($a1g, 'aspect-ratio:16/9'), true);
check('work tiles are one height, as A1 drew them', str_contains($a1g, '[data-view=work] .ezg-item{height:360px'), true);
check('the page numbers take A1\'s colour', str_contains($a1g, 'color:#ffd500'), true);
check('and a colour that is not one is dropped', str_contains(ezbizi_gallery_render(['page_color' => 'red;}body{x', 'images' => [['image' => ['url' => 'https://a1.test/a.jpg']]]]), 'red;}'), false);
$a1v = ezbizi_video_carousel_render(['slides_per_view' => 1, 'arrows_position' => 'outside', 'arrows_color' => '#ffd500', 'videos' => [['video_url' => 'https://youtu.be/08aVKV6xiPI'], ['video_url' => 'https://youtu.be/kXrNMnDSGZo']]]);
check('arrows can sit outside the videos', str_contains($a1v, 'color:#ffd500') && str_contains($a1v, 'padding:0 48px'), true);
check('with a dot per page', substr_count($a1v, 'aria-label="Page '), 2);
check('a watch link is read', ezbizi_youtube_id('https://www.youtube.com/watch?feature=x&v=abcdefghijk'), 'abcdefghijk');

// Rodrigo's audit, 2026-10-09: X's own overrides are fields the Helper can read and clear,
// and the social title templates can be corrected like the search ones.
check('the SEO Helper carries the X title', ezbizi_seo_keys()['twitter_title'] ?? null, '_yoast_wpseo_twitter-title');
check('and the social title template for pages', ezbizi_seo_site_fields()['social_page_title_template'] ?? null, 'social-title-page');

// 2026-10-15: on a-1chimney.com/booking the page's content filters turned the
// widget script's && into &#038;&#038; and the ZIP step did nothing. Before the
// footer, the script waits for it instead of riding inside the shortcode's HTML.
$GLOBALS['did_action']['wp_footer'] = 0;
$GLOBALS['ezbizi_late_js'] = [];
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload()];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
$early = $GLOBALS['sc']['ezbizi_form']([]);
check('before the footer a form carries no script', str_contains($early, '<script'), false);
$GLOBALS['did_action']['wp_footer'] = 1;
ob_start();
do_action('wp_footer');
$footer = (string) ob_get_clean();
preg_match('/id="(ezbizi-form-[0-9]+)"/', $early, $late);
check('and the footer prints it, for that form', str_contains($footer, '<script') && str_contains($footer, $late[1] ?? 'x'), true);

echo "\n".($failures === 0
    ? "PASS  {$checks} checks\n\n"
    : "FAIL  {$failures} of {$checks} checks\n\n");

exit($failures === 0 ? 0 : 1);
