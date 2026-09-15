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

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opt']) ? $GLOBALS['opt'][$k] : $d; }
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
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }

function add_shortcode($tag, $cb) { $GLOBALS['sc'][$tag] = $cb; }
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

function home_url() { return $GLOBALS['home'] ?? 'https://a-1chimney.com'; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return filter_var((string) $s, FILTER_VALIDATE_URL) ? (string) $s : ''; }
function esc_url_raw($s) { return (string) $s; }
function register_setting($g, $n, $a = []) { $GLOBALS['settings'][$n] = $a; }
function add_filter($hook, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$hook][] = $cb; }
function apply_filters($hook, $value) {
    foreach ($GLOBALS['filters'][$hook] ?? [] as $cb) { $value = $cb($value); }
    return $value;
}
function plugin_basename($f) { return 'ezbizi/ezbizi.php'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/'.$p; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function add_options_page() {}
function current_user_can() { return $GLOBALS['is_admin'] ?? true; }
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
    // A filter one case registers must not decide the next one.
    unset($GLOBALS['filters']['wp_is_application_passwords_available']);
    $GLOBALS['home'] = 'https://a-1chimney.com';
    $_SERVER['REQUEST_URI'] = '/';
}

function widget_payload($enabled = true) {
    $base = json_decode(payload_json(['phone' => '555-0100']), true);
    $base['public_key'] = 'pk_testkey';
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
check('www stripped, lowercased', ezbizi_host(), 'a-1chimney.com');
$GLOBALS['home'] = 'https://nj.a-1chimney.com';
check('a subdomain is its own host', ezbizi_host(), 'nj.a-1chimney.com');
$GLOBALS['home'] = 'https://a-1chimney.com';

echo "\nShortcodes come from the server's field list\n";
reset_site();
ok_response(['phone' => '555-0100', 'booking_url' => 'https://book.example.com']);
do_action('init');
check('[ezbizi_phone] registered', isset($GLOBALS['sc']['ezbizi_phone']), true);
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
check('and given the way there', str_contains($notice, 'page=ezbizi'), true);

reset_site();
$GLOBALS['screen'] = null;
ob_start();
do_action('admin_notices');
check('a connected site is not nagged', ob_get_clean(), '');

$GLOBALS['opt']['ezbizi_token'] = '';
$GLOBALS['screen'] = (object) ['id' => 'settings_page_ezbizi'];
ob_start();
do_action('admin_notices');
check('nor is somebody already on the form', ob_get_clean(), '');
$GLOBALS['screen'] = null;

echo "\nThe plugin's own row offers a way in\n";
$links = apply_filters(
    'plugin_action_links_ezbizi/ezbizi.php',
    ['<a href="#">Deactivate</a>']
);
check('a Settings link is added first', str_contains($links[0], 'page=ezbizi'), true);
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
check('the form posts to the form endpoint', str_contains($form, 'web' . chr(92) . '/form'), true);

$chat = $GLOBALS['sc']['ezbizi_chat']([]);
check('the chat title comes from EzBizi', str_contains($chat, 'Talk to A-1'), true);
check('and its position', str_contains($chat, 'right:20px'), true);
check('the chat posts to the chat endpoint', str_contains($chat, '/api/v1/web/chat'), true);

echo PHP_EOL . "A shortcode argument cannot override EzBizi" . PHP_EOL;
$forced = $GLOBALS['sc']['ezbizi_form'](['fields' => 'Something,Else', 'button' => 'Override me']);
check('the fields still come from EzBizi', str_contains($forced, 'What needs doing?'), true);
check('and the button is too', str_contains($forced, '>Send it<'), true);
check('the argument is ignored entirely', str_contains($forced, 'Override me'), false);

echo PHP_EOL . "A widget switched off in EzBizi renders nothing" . PHP_EOL;
reset_site();
$GLOBALS['http_next'] = ['code' => 200, 'body' => widget_payload(false)];
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('the form is absent', $GLOBALS['sc']['ezbizi_form']([]), '');
check('and so is the chat', $GLOBALS['sc']['ezbizi_chat']([]), '');
check(
    'somebody who can fix it is told',
    str_contains(($GLOBALS['is_admin'] = true) ? $GLOBALS['sc']['ezbizi_form']([]) : '', 'switched off'),
    true
);

echo PHP_EOL . "A site with no browser key shows a visitor nothing" . PHP_EOL;
reset_site();
ok_response(['phone' => '555-0100']);
ezbizi_fetch();
$GLOBALS['is_admin'] = false;
check('the form renders empty', $GLOBALS['sc']['ezbizi_form']([]), '');
check('and so does the chat', $GLOBALS['sc']['ezbizi_chat']([]), '');


echo "\n".($failures === 0
    ? "PASS  {$checks} checks\n\n"
    : "FAIL  {$failures} of {$checks} checks\n\n");

exit($failures === 0 ? 0 : 1);
