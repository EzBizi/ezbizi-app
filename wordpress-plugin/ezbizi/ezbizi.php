<?php
/**
 * Plugin Name:       EzBizi
 * Plugin URI:        https://ezbizi.com
 * Description:       Publishes your company details — phone, address, licence, hours — from EzBizi, so one change updates every page of this site.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EzBizi
 * Author URI:        https://www.ezbizi.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ezbizi
 */

/*
 * ── What this plugin is, in one paragraph ──
 *
 * EzBizi holds the company's business facts. This plugin reads the ones for
 * THIS host and turns each into a shortcode: `[ezbizi_phone]` renders the phone
 * number the company set for this site. A subdomain is a separate site in
 * EzBizi with its own token, which is how nj.example.com answers a different
 * number from example.com without a second theme or a second plugin.
 *
 * ── Three decisions worth knowing before editing this file ──
 *
 * 1. THE SHORTCODE LIST COMES FROM THE SERVER. `fields` in the payload names
 *    every key and the server derives the shortcode by the same rule this file
 *    does. Adding a field in EzBizi makes it a working shortcode here at the
 *    next refresh, with nobody updating this plugin. That is deliberate; do not
 *    replace it with a hardcoded list.
 *
 * 2. A FAILED FETCH MUST NEVER BLANK A LIVE PAGE. The response is cached in a
 *    transient for freshness AND kept in an option that never expires. If
 *    EzBizi is unreachable, the last known good values keep rendering. A
 *    customer's phone number disappearing from their website because our API
 *    had a bad minute is the one failure this plugin exists to avoid.
 *
 * 3. THE API HOST IS A SETTING, NOT A CONSTANT. Baking a hostname into a binary
 *    is what made a previous app's domain move a migration instead of a config
 *    change; the same mistake costs the same here, and costs it on servers we
 *    do not control.
 */

if (! defined('ABSPATH')) {
    exit;
}

const EZBIZI_VERSION = '1.0.1';
const EZBIZI_CACHE_KEY = 'ezbizi_payload';
const EZBIZI_LAST_GOOD = 'ezbizi_last_good';
const EZBIZI_CACHE_TTL = 300;
// How stale a payload may get before a visitor is made to wait for a fresh
// one. Only reached on sites where WP-Cron never runs.
const EZBIZI_STALE_MAX = 3600;
const EZBIZI_REFRESHING = 'ezbizi_refreshing';

/**
 * The host this WordPress install answers on, in the form EzBizi stores.
 *
 * Must agree with WebSite::normalizeHost on the server — lowercase, no scheme,
 * no port, no leading www. A disagreement here reads as "this token belongs to
 * another site", which is a confusing way to say "we spell hosts differently".
 */
function ezbizi_host() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    if (! is_string($host) || $host === '') {
        return '';
    }

    return preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
}

/** The page path the visitor is on, in the form EzBizi stores overrides under. */
function ezbizi_path() {
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $path = wp_parse_url($uri, PHP_URL_PATH);

    if (! is_string($path)) {
        return '';
    }

    $path = '/' . trim($path, '/');

    return $path === '/' ? '' : strtolower($path);
}

function ezbizi_settings() {
    return array(
        'api'   => get_option('ezbizi_api_base', 'https://app.ezbizi.com'),
        'token' => get_option('ezbizi_token', ''),
    );
}

/**
 * The payload a page renders from. NEVER makes a visitor wait when it can help
 * it.
 *
 * ── Why this is not simply "fetch when the cache expires" ──
 *
 * It was, and that put a blocking HTTP request on the critical path of whichever
 * unlucky visitor arrived first after each expiry. On a slow or unreachable API
 * that is up to the timeout added to somebody's homepage, caused by us, on a
 * site we do not run. Stale by five minutes is invisible; eight seconds is not.
 *
 * So: serve what we have and refresh behind the request. Blocking happens in
 * exactly two cases, both correct — there is nothing at all to show yet, or the
 * last good read is older than the ceiling below.
 */
function ezbizi_payload($force = false) {
    if ($force) {
        return ezbizi_fetch();
    }

    $cached = get_transient(EZBIZI_CACHE_KEY);

    if (is_array($cached)) {
        return $cached;
    }

    $last = get_option(EZBIZI_LAST_GOOD, null);

    // First read after setup. There is nothing to serve, so the wait buys
    // something — and it happens on the settings screen, not on a visitor's page.
    if (! is_array($last)) {
        return ezbizi_fetch();
    }

    /*
     * A site with WP-Cron disabled would otherwise serve one stale payload
     * forever, because the background refresh below never runs. Past the
     * ceiling we block rather than publish an unbounded-stale phone number.
     */
    if (time() - (int) (isset($last['fetched_at']) ? $last['fetched_at'] : 0) > EZBIZI_STALE_MAX) {
        return ezbizi_fetch();
    }

    if (! get_transient(EZBIZI_REFRESHING)) {
        // Short-lived, so a failed refresh retries soon rather than being
        // locked out until it expires.
        set_transient(EZBIZI_REFRESHING, 1, 60);
        wp_schedule_single_event(time(), 'ezbizi_refresh');
    }

    return $last;
}

add_action('ezbizi_refresh', 'ezbizi_fetch');

/**
 * Actually ask EzBizi. The only function in this file that makes a request.
 *
 * Returns the last known good payload on any failure, and an empty shape only
 * when this site has never had a successful read.
 */
function ezbizi_fetch() {
    $settings = ezbizi_settings();
    $empty = array('values' => array(), 'pages' => array(), 'fields' => array());

    if ($settings['token'] === '') {
        return get_option(EZBIZI_LAST_GOOD, $empty);
    }

    $url = rtrim($settings['api'], '/') . '/api/v1/web/plugin/values?' . http_build_query(array(
        'host'           => ezbizi_host(),
        'plugin_version' => EZBIZI_VERSION,
    ));

    $response = wp_remote_get($url, array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $settings['token'],
            'Accept'        => 'application/json',
        ),
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        /*
         * Cache the FAILURE briefly too. Without this, a site whose token was
         * revoked calls the API on every page view of every visitor — the
         * plugin turning into a load generator against us is a worse outcome
         * than stale values.
         */
        set_transient(EZBIZI_CACHE_KEY, get_option(EZBIZI_LAST_GOOD, $empty), 60);

        return get_option(EZBIZI_LAST_GOOD, $empty);
    }

    $payload = json_decode(wp_remote_retrieve_body($response), true);

    if (! is_array($payload) || ! isset($payload['values'])) {
        return get_option(EZBIZI_LAST_GOOD, $empty);
    }

    // Stamped so ezbizi_payload() can tell "five minutes stale" from "this site
    // has had no successful read since March".
    $payload['fetched_at'] = time();

    set_transient(EZBIZI_CACHE_KEY, $payload, EZBIZI_CACHE_TTL);
    update_option(EZBIZI_LAST_GOOD, $payload, false);

    return $payload;
}

/** One value, preferring this page's override over the site's answer. */
function ezbizi_value($key) {
    $payload = ezbizi_payload();
    $path = ezbizi_path();

    if ($path !== '' && isset($payload['pages'][$path][$key])) {
        return (string) $payload['pages'][$path][$key];
    }

    return isset($payload['values'][$key]) ? (string) $payload['values'][$key] : '';
}

/**
 * `[ezbizi key="phone"]`, and `[ezbizi_phone]` for every key the server names.
 *
 * A missing value renders as nothing rather than as a warning: a half-filled
 * panel is a normal state on the day somebody installs this, and a live page is
 * not the place to tell them about it.
 */
function ezbizi_shortcode($atts) {
    $atts = shortcode_atts(array('key' => '', 'default' => ''), $atts, 'ezbizi');
    $value = $atts['key'] === '' ? '' : ezbizi_value($atts['key']);

    if ($value === '') {
        $value = (string) $atts['default'];
    }

    return substr($atts['key'], -4) === '_url' ? esc_url($value) : esc_html($value);
}

add_shortcode('ezbizi', 'ezbizi_shortcode');

add_action('init', function () {
    $payload = ezbizi_payload();
    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();

    foreach ($fields as $field) {
        if (! isset($field['key'])) {
            continue;
        }

        $key = $field['key'];

        add_shortcode('ezbizi_' . $key, function ($atts) use ($key) {
            $atts = is_array($atts) ? $atts : array();
            $atts['key'] = $key;

            return ezbizi_shortcode($atts);
        });
    }
});

// ── Settings ────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page('EzBizi', 'EzBizi', 'manage_options', 'ezbizi', 'ezbizi_settings_page');
});

add_action('admin_init', function () {
    register_setting('ezbizi', 'ezbizi_token', array('sanitize_callback' => 'ezbizi_keep_saved_token'));
    register_setting('ezbizi', 'ezbizi_api_base', array('sanitize_callback' => 'esc_url_raw'));
});

/*
 * Changing either setting invalidates the cache immediately. Without this,
 * somebody pastes a corrected token, sees the same error for five minutes, and
 * concludes the token is wrong too.
 */
add_action('update_option_ezbizi_token', 'ezbizi_forget_cache', 10, 0);
add_action('update_option_ezbizi_api_base', 'ezbizi_forget_cache', 10, 0);

/**
 * An empty token field means "leave it alone", not "erase it".
 *
 * The field is rendered empty on every load — see the settings page — because a
 * token echoed into `value` puts a live credential in the page source, the
 * browser cache and any screenshot of the screen. The cost of not echoing it is
 * that blank can no longer mean "clear", so it means "unchanged" instead.
 */
function ezbizi_keep_saved_token($value) {
    $value = sanitize_text_field($value);

    return $value === '' ? (string) get_option('ezbizi_token', '') : $value;
}

function ezbizi_forget_cache() {
    delete_transient(EZBIZI_CACHE_KEY);
}

function ezbizi_settings_page() {
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['ezbizi_refresh']) && check_admin_referer('ezbizi_refresh')) {
        ezbizi_forget_cache();
        ezbizi_payload(true);
    }

    $settings = ezbizi_settings();
    $payload = ezbizi_payload();
    $connected = ! empty($payload['site']['host']);

    echo '<div class="wrap"><h1>EzBizi</h1>';

    echo '<p>Paste the token EzBizi showed you when you added <code>'
        . esc_html(ezbizi_host()) . '</code> under Web Management.</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('ezbizi');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="ezbizi_token">Site token</label></th><td>'
        . '<input name="ezbizi_token" id="ezbizi_token" type="password" class="regular-text" value="" '
        . 'autocomplete="off" placeholder="'
        . ($settings['token'] === '' ? 'Paste the token from EzBizi' : 'Saved. Paste a new one to replace it.')
        . '"><p class="description">'
        . 'The token is never shown again once saved. Leave this empty to keep the current one.'
        . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="ezbizi_api_base">EzBizi address</label></th><td>'
        . '<input name="ezbizi_api_base" id="ezbizi_api_base" type="url" class="regular-text" value="'
        . esc_attr($settings['api']) . '"><p class="description">Leave this alone unless EzBizi told you to change it.</p></td></tr>';
    echo '</tbody></table>';
    submit_button();
    echo '</form>';

    echo '<h2>Connection</h2>';

    if ($connected) {
        echo '<p><strong>Connected</strong> as ' . esc_html($payload['site']['host']) . '.</p>';
    } else {
        echo '<p><strong>Not connected yet.</strong> Check the token, then refresh below.</p>';
    }

    echo '<form method="post">';
    wp_nonce_field('ezbizi_refresh');
    echo '<input type="hidden" name="ezbizi_refresh" value="1">';
    submit_button('Refresh now', 'secondary');
    echo '</form>';

    echo '<h2>Your shortcodes</h2><table class="widefat striped"><thead><tr>'
        . '<th>Shortcode</th><th>Shows</th><th>Right now</th></tr></thead><tbody>';

    $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();

    if ($fields === array()) {
        echo '<tr><td colspan="3">Connect the site to see its shortcodes.</td></tr>';
    }

    foreach ($fields as $field) {
        $value = ezbizi_value($field['key']);

        echo '<tr><td><code>' . esc_html($field['shortcode']) . '</code></td><td>'
            . esc_html($field['label']) . '</td><td>'
            . ($value === '' ? '<em>not set in EzBizi</em>' : esc_html($value))
            . '</td></tr>';
    }

    echo '</tbody></table></div>';
}
