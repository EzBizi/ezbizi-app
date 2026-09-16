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
function human_time_diff($from, $to = 0) {
    $d = abs(($to ?: time()) - $from);

    return $d < 3600 ? intdiv($d, 60).' mins' : ($d < 86400 ? intdiv($d, 3600).' hours' : intdiv($d, 86400).' days');
}
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
/*
 * Records instead of discarding, because the menu is the thing being asserted.
 * `add_options_page` is gone with the page that used it: a stub for a function
 * the plugin no longer calls is a check that can never fail.
 */
function add_menu_page($page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null) {
    $GLOBALS['menu'][] = compact('page_title', 'menu_title', 'cap', 'slug', 'cb', 'icon', 'pos');
}
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

check('it asks for a name', str_contains($chat_html, 'name="name"'), true);
check('and a way to reply', str_contains($chat_html, 'name="contact"'), true);
check('and what they want', str_contains($chat_html, 'name="message"'), true);
check('all three required', substr_count($chat_html, 'required'), 3);
check('there is a way out of the panel', str_contains($chat_html, 'ezbizi-chat-close'), true);

// The thread exists but is not what they meet first.
check('the message box waits its turn', str_contains($chat_html, '<div class="ezbizi-chat-thread" hidden>'), true);

/*
 * The fix for what the photograph actually showed. A theme that sets
 * `background` on inputs turned ours near-black; stating it is what stops
 * that, and a widget living in somebody else's stylesheet has to state
 * everything it cares about.
 */
check('the input states its own colours', str_contains($chat_html, 'background:#fff;color:#1b1b1b'), true);
check('and the launcher its own shape', str_contains($chat_html, 'border-radius:999px'), true);

// It must not be readable as HTML when it comes back from a stranger.
check('replies are drawn as text', str_contains($chat_html, 'row.textContent=m.text'), true);
check('and never as markup', str_contains($chat_html, 'row.innerHTML=m.text'), false);

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
    unset($GLOBALS['ezbizi_chat_drawn']);
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

check('both widgets are registered', count($manager->registered), 2);
check('the form, under a name Elementor can address', isset($manager->registered['ezbizi-form']), true);
check('the chat too', isset($manager->registered['ezbizi-chat']), true);

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

echo "\n".($failures === 0
    ? "PASS  {$checks} checks\n\n"
    : "FAIL  {$failures} of {$checks} checks\n\n");

exit($failures === 0 ? 0 : 1);
