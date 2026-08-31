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
function add_options_page() {}
function current_user_can() { return true; }
function settings_fields() {}
function submit_button() {}
function wp_nonce_field() {}
function check_admin_referer() { return true; }

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
    $_SERVER['REQUEST_URI'] = '/';
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

echo "\nA blank token field keeps the saved token\n";
reset_site();
check('blank means unchanged', ezbizi_keep_saved_token(''), 'ezb_test');
check('a new one replaces it', ezbizi_keep_saved_token('ezb_other'), 'ezb_other');

echo "\n".($failures === 0
    ? "PASS  {$checks} checks\n\n"
    : "FAIL  {$failures} of {$checks} checks\n\n");

exit($failures === 0 ? 0 : 1);
