<?php
/**
 * Plugin Name:       EzBizi
 * Plugin URI:        https://ezbizi.com
 * Description:       Publishes your company details — phone, address, licence, hours — from EzBizi, so one change updates every page of this site.
 * Version:           1.26.21
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

const EZBIZI_VERSION = '1.26.21';
const EZBIZI_CACHE_KEY = 'ezbizi_payload';
const EZBIZI_LAST_GOOD = 'ezbizi_last_good';
const EZBIZI_CACHE_TTL = 300;
// How stale a payload may get before a visitor is made to wait for a fresh
// one. Only reached on sites where WP-Cron never runs.
const EZBIZI_STALE_MAX = 3600;
const EZBIZI_REFRESHING = 'ezbizi_refreshing';

/*
 * When EzBizi started REFUSING this site's token, as distinct from failing to
 * answer. Cleared by any successful read.
 *
 * A 401 or 403 is the server saying it does not recognise this site any more.
 * A timeout, a 500 or a DNS failure is us having a bad day, and a site must
 * never hide anything because of that — those keep serving the last good
 * payload exactly as before.
 */
const EZBIZI_REFUSED_SINCE = 'ezbizi_refused_since';

/*
 * There is no grace period, and there was one for half a day.
 *
 * Jose's rule, on the day the chat went onto every page: it "should only show
 * up if a connection has been connected properly". A site EzBizi is refusing
 * is not connected, whatever it was yesterday, so seven days of grace was
 * seven days of a chat box on a site somebody had disconnected, collecting
 * messages into an account that will not accept them.
 *
 * I had argued for that grace because rotating a token leaves a gap of minutes
 * while somebody pastes the new one, and the public key the widgets use often
 * survives it. The reasoning still holds and it is not worth what it costs: a
 * chat that vanishes for ten minutes during a rotation is a small fault that
 * fixes itself, and a chat on a site that is no longer ours is not.
 *
 * There is deliberately no constant left to tune. A duration compared against
 * zero would also answer "not yet" for the first second of every refusal, and
 * a boundary like that is a bug waiting for the one test that stamps and reads
 * in the same second — which is exactly how this one announced itself.
 *
 * Only a REFUSAL counts: 401 or 403, EzBizi saying it does not recognise this
 * site. A timeout or a 500 is our own bad day and changes nothing.
 */

require_once __DIR__ . '/service-account.php';
require_once __DIR__ . '/enquiries.php';
require_once __DIR__ . '/elementor-forms.php';
require_once __DIR__ . '/seo-helper.php';
// After `enquiries.php`, which owns the helpers the presentation widgets share
// — the id counter, the palette reader and the string accessor.
require_once __DIR__ . '/widgets.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/elementor.php';

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

    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        /*
         * Refused is not the same as unreachable, and only one of them is
         * about this site. 401 and 403 are EzBizi saying it does not recognise
         * the token; anything else is a bad day at our end and must change
         * nothing here. Stamped once, so the clock measures how long the
         * refusal has lasted rather than restarting on every page view.
         */
        if (($code === 401 || $code === 403) && ! get_option(EZBIZI_REFUSED_SINCE, false)) {
            update_option(EZBIZI_REFUSED_SINCE, time(), false);
        }

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

    // Answered, so whatever was wrong is over.
    delete_option(EZBIZI_REFUSED_SINCE);

    // Stamped so ezbizi_payload() can tell "five minutes stale" from "this site
    // has had no successful read since March".
    $payload['fetched_at'] = time();

    /*
     * Has anything a page RENDERS actually changed?
     *
     * Compared before the write, and with `fetched_at` taken out of both —
     * that stamp changes on every refresh and would make every refresh look
     * like a change, which would purge the page cache every few minutes on
     * 110 sites for nothing.
     */
    $changed = ezbizi_payload_differs(get_option(EZBIZI_LAST_GOOD, null), $payload);

    set_transient(EZBIZI_CACHE_KEY, $payload, EZBIZI_CACHE_TTL);
    update_option(EZBIZI_LAST_GOOD, $payload, false);

    if ($changed) {
        ezbizi_purge_page_cache();
    }

    /*
     * The token has just been proved good by a 200, which is the earliest
     * moment this site can safely hand EzBizi an account to act as. Does
     * nothing on every later read, and cannot fail loudly enough to affect the
     * payload it was called from.
     */
    ezbizi_service_link();
    ezbizi_service_reconcile($payload);

    return $payload;
}

/**
 * Whether two payloads would draw a different page.
 *
 * `fetched_at` is removed from both: it moves on every refresh and is the one
 * key that never changes what a visitor sees.
 */
function ezbizi_payload_differs($before, $after) {
    if (! is_array($before)) {
        return true;
    }

    unset($before['fetched_at'], $after['fetched_at']);

    return $before !== $after;
}

/**
 * Throw away the site's cached HTML, because EzBizi's answer changed.
 *
 * ── The defect this exists for ──
 *
 * Jose, 2026-09-23: he switched the live chat OFF in EzBizi and the bubble
 * kept appearing on a-1chimney.com. Every link in the chain was already
 * correct — the database said off, the server omitted the `chat` block from
 * the reader payload, this plugin's own cache had no chat block, and a freshly
 * generated page drew no bubble. What a visitor got was HTML generated before
 * he flipped the switch and held by the site's page cache. Purging it by hand
 * removed the bubble; 37 entries, measured.
 *
 * So a setting changed in EzBizi did not reach a visitor until the page cache
 * expired on its own. That is wrong for every widget and WORST for this one,
 * because the chat switch is the control somebody reaches for when they want
 * to STOP taking conversations — a safety switch that takes effect later is
 * not a safety switch.
 *
 * ── Why it is a list of plugins rather than one call ──
 *
 * There is no WordPress-wide "clear the page cache". Each caching plugin has
 * its own, so this asks every one it knows for and does nothing where none is
 * installed. A site running something not on this list keeps the old
 * behaviour, which is what it has today — this can only improve.
 *
 * Nothing here is allowed to be fatal: it runs inside the payload refresh on
 * somebody's live page load, and a cache plugin that changed its own API must
 * not take the website down with it. Every call is guarded by an existence
 * check for exactly that reason.
 */
function ezbizi_purge_page_cache() {
    // Cache Enabler — what the a-1chimney estate runs, and the one this was
    // written for.
    if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
        Cache_Enabler::clear_complete_cache();
    }

    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }

    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }

    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    /*
     * `function_exists` on WordPress's OWN functions, which looks paranoid and
     * is not: this function is reached from `ezbizi_payload()`, and the
     * plugin-check harness runs that with a WordPress surface that stubs some
     * functions and not others. It called `has_action()` and died — a fatal,
     * on the path every page load takes, caught because that harness executes
     * the plugin rather than reading it.
     *
     * The docblock above already said nothing here may be fatal. The guard I
     * wrote for OTHER people's plugins had to apply to the host as well.
     */
    if (function_exists('has_action') && function_exists('do_action')) {
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }

        // The nginx layer in front of WordPress, where one is wired up. The
        // estate's own purge is a shell command this cannot reach, so this is
        // the half a site can do for itself.
        if (has_action('rt_nginx_helper_purge_all')) {
            do_action('rt_nginx_helper_purge_all');
        }

        /*
         * And a hook of our own, so a site with a cache nothing above knows
         * can clear itself without waiting for this list to grow.
         */
        do_action('ezbizi_payload_changed');
    }

    ezbizi_queue_nginx_purge();
}

/**
 * Ask the web host to drop this site's cached pages.
 *
 * The estate's nginx keeps pages for two hours and has no purge module; a
 * root job purges a host when a marker named after it appears in
 * <webspace>/.purge-queue (the one place PHP may write under Plesk's
 * open_basedir). Until 2026-09-29 only A1 Tools' updates dropped a marker, so
 * an hours change made in EzBizi took up to two hours to show (Jose, testing
 * Rodrigo's hours editor on aus.a-1chimney.com).
 *
 * Silently nothing on a host without that layout.
 */
function ezbizi_queue_nginx_purge() {
    if (! defined('ABSPATH') || ! function_exists('home_url')) {
        return;
    }

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $dir = rtrim(ABSPATH, '/');

    for ($i = 0; $i < 6 && dirname($dir) !== '/var/www/vhosts'; $i++) {
        $up = dirname($dir);

        if ($up === $dir) {
            return;
        }

        $dir = $up;
    }

    if (dirname($dir) !== '/var/www/vhosts' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
        return;
    }

    $queue = $dir . '/.purge-queue';

    if (! is_dir($queue)) {
        @mkdir($queue, 0775, true);
    }

    if (is_dir($queue) && is_writable($queue)) {
        @touch($queue . '/' . strtolower($host));
    }
}

/*
 * POST /wp-json/ezbizi/v1/refresh — EzBizi says "something changed, read it now".
 *
 * Called with the service account right after a save (an hours change, a
 * value), so the site does not wait up to five minutes for its own refresh.
 * Reads the payload at once; a real change purges the page cache as above.
 */
add_action('rest_api_init', function () {
    register_rest_route('ezbizi/v1', '/refresh', array(
        'methods' => 'POST',
        'callback' => function () {
            $payload = ezbizi_fetch();

            return new WP_REST_Response(array('fetched_at' => isset($payload['fetched_at']) ? (int) $payload['fetched_at'] : null));
        },
        'permission_callback' => function () {
            return current_user_can('edit_pages');
        },
    ));
});

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

    if (ezbizi_is_link_key($atts['key'])) {
        return esc_url($value);
    }

    $out = esc_html($value);

    /*
     * A page's text or a template may name another value — "serving
     * [ezbizi_state_anchor] homeowners" — as the texts imported from A1 do.
     * Only EzBizi's own shortcodes run, each on its own, so text a customer
     * typed cannot call anything else; and a value naming itself stops after
     * a few levels instead of looping.
     */
    static $depth = 0;

    if ($depth < 3 && strpos($out, '[ezbizi_') !== false
        && (preg_match('/_field_[0-9]+$/', $atts['key']) === 1 || preg_match('/^template_[0-9]+$/', $atts['key']) === 1)) {
        $depth++;
        $out = preg_replace_callback('/\[ezbizi_[a-z0-9_]+\]/', function ($m) {
            return do_shortcode($m[0]);
        }, $out);
        $depth--;
    }

    return $out;
}

/**
 * Whether a field holds a web address.
 *
 * ── Why the suffix decides ──
 *
 * A URL must never go through `esc_html`: it turns the `&` in a query string
 * into `&amp;`, and it does nothing about a `javascript:` scheme somebody
 * stored. `esc_url` handles both, so the only question is which fields are
 * addresses — and the key is the only thing this plugin knows about a value it
 * has never seen before.
 *
 * `_link` joined `_url` when City link and State link arrived: they are called
 * links on the screen, and a rule that covered only one suffix would have
 * rendered the customer's own civic links through the wrong escaper while
 * looking entirely correct.
 */
function ezbizi_is_link_key($key) {
    return substr($key, -4) === '_url' || substr($key, -5) === '_link';
}

add_shortcode('ezbizi', 'ezbizi_shortcode');

// No scrollbar on a phone (Jose, 2026-10-08: A-1's theme draws a thick yellow
// one); a computer keeps the theme's.
add_action('wp_head', function () {
    echo '<style>@media (max-width:768px){::-webkit-scrollbar{display:none;width:0;height:0}html,body{scrollbar-width:none}}</style>';
}, 99);

/*
 * /r/Ab3xK9m: the short review link a job's thank-you text carries (Jose,
 * 2026-10-08: ~40 characters instead of ~140). The code is EzBizi's; this
 * only forwards it to the site's own reviews page, which sends it back.
 */
add_action('init', function () {
    $path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);

    if (! preg_match('~^/r/([A-Za-z0-9]{7})/?$~', $path, $m)) {
        return;
    }

    $page = trim((string) ezbizi_value('review_link'));
    $page = preg_match('~^https?://~i', $page) ? $page : home_url('/reviews/');
    nocache_headers();
    wp_redirect($page . (strpos($page, '?') === false ? '?' : '&') . 'c=' . $m[1], 302);
    exit;
}, 1);

/*
 * /b/Ab3xK9m: the short booking link an after-hours reply carries (Jose,
 * 2026-10-20, "like we did with the review links"). The code is EzBizi's; this
 * forwards it to the site's booking page, whose widget sends it back as l.
 */
add_action('init', function () {
    $path = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);

    if (! preg_match('~^/b/([A-Za-z0-9]{7})/?$~', $path, $m)) {
        return;
    }

    nocache_headers();
    wp_redirect(home_url('/booking/') . '?l=' . $m[1], 302);
    exit;
}, 1);

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

/*
 * Its own item in the sidebar, next to A1 Tools. Jose, 2026-09-15.
 *
 * ── This reverses the previous decision, and the reason is worth keeping ──
 *
 * It used to sit under Settings, which is the directory's guidance for a plugin
 * whose whole interface is one screen. The comment that lived here recorded the
 * cost honestly and then declined to pay it: "the first person to install this
 * one went to the sidebar, found nothing, and concluded the plugin had not
 * worked." That person was the customer. A Settings link on the plugin row and
 * an admin notice were added instead, and they were not enough — the evidence
 * that they were not enough is that the same person asked for this again.
 *
 * The guidance is guidance rather than a rule, and it is aimed at sidebars
 * crowded by plugins that each claim a row. This one is how a business connects
 * its website to the system that runs it; the sibling it sits beside does the
 * same job for the same people.
 */
add_action('admin_menu', function () {
    add_menu_page(
        'EzBizi',
        'EzBizi',
        'manage_options',
        'ezbizi',
        'ezbizi_settings_page',
        // A globe, matching the Web Management tab this screen pairs with.
        'dashicons-admin-site-alt3',
        // Just below Settings, which is 80 — the neighbourhood somebody is in
        // when they go looking for it, and where A1 Tools already sits.
        81
    );
});

/**
 * A Settings link on the plugin's own row.
 *
 * This is where somebody is standing at the moment they wonder where the
 * settings are — they have just activated it and are looking at the row. Every
 * other configured plugin on that screen offers this link, so its absence reads
 * as "this one has nothing to configure".
 */
add_filter('plugin_action_links_'.plugin_basename(__FILE__), function ($links) {
    array_unshift($links, sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=ezbizi')),
        esc_html__('Settings', 'ezbizi')
    ));

    return $links;
});

/**
 * Say so, once, while the plugin is installed and doing nothing.
 *
 * Without a token this plugin is inert: no request, no values, every shortcode
 * empty. That is indistinguishable from a broken install unless it says which
 * one it is. Shown only to people who could actually fix it, and only on admin
 * screens rather than on the site.
 */
add_action('admin_notices', function () {
    if (! current_user_can('manage_options')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    /*
     * Not on its own page — the form is right there.
     *
     * `toplevel_page_` and not `settings_page_`: WordPress names the screen
     * after WHERE the menu item is, so moving the page out of Settings renamed
     * its screen, and this guard went on matching a screen that no longer
     * exists — nagging people while they stood on the form it was sending them
     * to. Nothing in the harness fired admin_menu, so nothing saw it.
     */
    if ($screen && $screen->id === 'toplevel_page_ezbizi') {
        return;
    }

    $settings = ezbizi_settings();

    if ($settings['token'] !== '') {
        /*
         * A token that is present and refused is a different sentence from no
         * token at all, and it has to be SAID: past the ceiling the chat and
         * the form stop drawing, and a widget that vanishes without explanation
         * reads as the plugin breaking rather than as the pairing ending.
         */
        $refused = function_exists('ezbizi_refused_for') ? ezbizi_refused_for() : 0;

        if ($refused > 0) {
            printf(
                '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
                esc_html(sprintf(
                    /* translators: %s: how long, e.g. "8 days". */
                    __(
                        'EzBizi has been refusing this site\'s token for %s, so the chat and contact form have stopped showing. Your published values are unaffected. Issue a new site token in Web Management and paste it here.',
                        'ezbizi'
                    ),
                    human_time_diff(time() - $refused)
                )),
                esc_url(admin_url('admin.php?page=ezbizi')),
                esc_html__('Open EzBizi settings', 'ezbizi')
            );
        }

        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
        esc_html__(
            'EzBizi is active but not connected yet, so its shortcodes render nothing. Paste the site token from Web Management to finish.',
            'ezbizi'
        ),
        esc_url(admin_url('admin.php?page=ezbizi')),
        esc_html__('Open EzBizi settings', 'ezbizi')
    );
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
