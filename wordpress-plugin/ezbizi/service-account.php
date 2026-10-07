<?php
/**
 * The WordPress account EzBizi acts as, and the credential it acts with.
 *
 * ── Why this exists ──
 *
 * The shortcodes are a one-way read: EzBizi holds the facts, this plugin
 * renders them. Anything EzBizi needs to DO on the site — publish a page, edit
 * one, upload an image — is not something a shortcode can express, and it needs
 * an account to do it as.
 *
 * ── Why it is not created on activation ──
 *
 * Activation happens before the site has a token, so there is nobody to send a
 * credential to and no way to prove the site is the one it claims to be. The
 * account is created after the FIRST SUCCESSFUL authenticated read instead: at
 * that point the token is known good, the pairing is proven in both
 * directions, and the site is handing a credential to somebody it has just
 * heard back from.
 *
 * ── Why an application password rather than the account's own ──
 *
 * A real password would let EzBizi sign into wp-admin as that user, which is
 * far more than it needs and impossible to narrow. An application password is
 * REST-only, one of many, individually revocable from Users without touching
 * the account, and it never grants the interactive login.
 *
 * ── The worst failure, and what prevents it ──
 *
 * Creating the credential and failing to deliver it would leave a live
 * application password on the customer's site that nothing holds and nobody
 * knows to revoke. So the credential is REVOKED when the handover fails, and
 * the next refresh starts again from nothing.
 */

if (! defined('ABSPATH')) {
    exit;
}

const EZBIZI_SERVICE_LOGIN = 'ezbizi-service';
const EZBIZI_SERVICE_ROLE = 'ezbizi_service';
const EZBIZI_SERVICE_LINKED = 'ezbizi_service_linked';
const EZBIZI_SERVICE_APP_NAME = 'EzBizi';

/**
 * Everything the account may do, named one at a time.
 *
 * There is no manage_options, no edit_users, no install_plugins and no
 * edit_theme_options: EzBizi publishes content and has no business changing the
 * site's settings, its people, or its code. unfiltered_html is absent on
 * purpose too — EzBizi's text goes through the same filters everybody else's
 * does. Deletion is absent because publishing is the job; a mistake should cost
 * an edit, not a missing page.
 *
 * @return array<string, bool>
 */
function ezbizi_service_capabilities() {
    return array(
        'read'                 => true,
        'upload_files'         => true,
        'edit_posts'           => true,
        'edit_published_posts' => true,
        'publish_posts'        => true,
        'edit_pages'           => true,
        'edit_published_pages' => true,
        'publish_pages'        => true,
        /*
         * Pages and posts somebody else wrote. The SEO Helper saves a search
         * title on /about-us, which the site's owner authored, and WordPress
         * answered 403 without these (MCP/API, 2026-10-07: "WordPress returns
         * 403 to EzBizi's SEO editor"). Editing, still never deleting.
         */
        'edit_others_pages'    => true,
        'edit_others_posts'    => true,
        'delete_posts'         => false,
        'delete_pages'         => false,
    );
}

/** The role, created if absent and re-narrowed if this plugin has narrowed it. */
function ezbizi_service_role() {
    $caps = ezbizi_service_capabilities();
    $role = get_role(EZBIZI_SERVICE_ROLE);

    if ($role === null) {
        add_role(EZBIZI_SERVICE_ROLE, 'EzBizi Service', $caps);

        return;
    }

    /*
     * A plugin update that removes a capability must remove it from sites that
     * already have the role, or the tightening only ever helps new installs.
     */
    foreach ($caps as $cap => $granted) {
        if ($granted) {
            $role->add_cap($cap);
        } else {
            $role->remove_cap($cap);
        }
    }
}

/** The user, created if absent. Returns the WP_User, or null if it cannot be. */
function ezbizi_service_user() {
    $existing = get_user_by('login', EZBIZI_SERVICE_LOGIN);

    if ($existing instanceof WP_User) {
        return $existing;
    }

    ezbizi_service_role();

    $id = wp_insert_user(array(
        'user_login'   => EZBIZI_SERVICE_LOGIN,
        'user_pass'    => wp_generate_password(64, true, true),
        'user_email'   => 'service+' . wp_generate_password(12, false) . '@ezbizi.invalid',
        'display_name' => 'EzBizi',
        'role'         => EZBIZI_SERVICE_ROLE,
    ));

    if (is_wp_error($id)) {
        return null;
    }

    return get_user_by('id', $id);
}

/**
 * Create the credential, hand it over, and keep it only if the handover worked.
 *
 * Runs at most once per site until something unlinks it. Silent on every
 * failure, because this is reached from a content read: an install that cannot
 * pair must still render a phone number.
 */
/**
 * Whether this site permits application passwords, asked so that a background
 * request cannot answer for the site.
 *
 * ── The bug this exists to fix ──
 *
 * `wp_is_application_passwords_available()` defaults to `is_ssl()`, which is a
 * fact about the CURRENT REQUEST rather than about the site. The pairing runs
 * from a content read, and a content read is usually WP-Cron — a loopback that
 * is frequently plain HTTP even on an HTTPS site, and always HTTP under WP-CLI.
 * So on a perfectly ordinary site the answer was "no", every time, forever, and
 * silently: the customer saw a plugin that worked and no account ever appeared.
 * Measured on a live install, where the site is HTTPS and this returned false.
 *
 * ── Why this does not simply bypass the check ──
 *
 * The filter is also how a site owner switches application passwords OFF, and
 * overriding that would be taking a decision that is theirs. So: compute what
 * WordPress would default to, ask for the filtered answer, and compare. If they
 * differ, somebody set it deliberately and their answer stands. If they agree,
 * the only input was `is_ssl()` — and we substitute the question actually worth
 * asking, which is whether the SITE is served over HTTPS.
 */
function ezbizi_application_passwords_allowed() {
    if (wp_is_application_passwords_available()) {
        return true;
    }

    /*
     * It said no. Two different things produce that answer and only one of them
     * is a decision:
     *
     *   - the site owner filtered application passwords off, which stands; or
     *   - `is_ssl()` was false, which is a fact about THIS REQUEST.
     *
     * Comparing the filtered answer against the computed default cannot tell
     * them apart, because when is_ssl() is false the default is already false
     * and an owner's "off" looks identical. Asking the filter with `true`
     * separates them: a filter that switches the feature off returns false
     * whatever it is handed, and an unfiltered site hands `true` straight back.
     *
     * Caught by the plugin harness, which had a case for the switched-off site
     * and went red on the first version of this function.
     */
    if (! apply_filters('wp_is_application_passwords_available', true)) {
        return false;
    }

    // Nobody objected, so the only "no" was about this request. Ask the
    // question that is actually about the site.
    return wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https';
}

function ezbizi_service_link() {
    if (get_option(EZBIZI_SERVICE_LINKED, false)) {
        return;
    }

    $settings = ezbizi_settings();

    if ($settings['token'] === '') {
        return;
    }

    /*
     * A credential must never cross plain HTTP. Localhost is the exception a
     * developer needs and somebody on the wire cannot use.
     */
    $api = rtrim($settings['api'], '/');
    $scheme = wp_parse_url($api, PHP_URL_SCHEME);
    $api_host = (string) wp_parse_url($api, PHP_URL_HOST);

    if ($scheme !== 'https' && ! in_array($api_host, array('localhost', '127.0.0.1'), true)) {
        return;
    }

    if (! class_exists('WP_Application_Passwords') || ! ezbizi_application_passwords_allowed()) {
        return;
    }

    $user = ezbizi_service_user();

    if (! $user instanceof WP_User) {
        return;
    }

    $created = WP_Application_Passwords::create_new_application_password(
        $user->ID,
        array('name' => EZBIZI_SERVICE_APP_NAME)
    );

    if (is_wp_error($created) || ! isset($created[0]) || ! isset($created[1]['uuid'])) {
        return;
    }

    $password = $created[0];
    $uuid = $created[1]['uuid'];

    $response = wp_remote_post($api . '/api/v1/web/plugin/service-account', array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $settings['token'],
            'Accept'        => 'application/json',
        ),
        'body' => array(
            'host'         => ezbizi_host(),
            'rest_url'     => get_rest_url(),
            'user_login'   => $user->user_login,
            'password'     => $password,
            'capabilities' => implode(',', array_keys(array_filter(ezbizi_service_capabilities()))),
        ),
    ));

    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

    if ($code !== 200 && $code !== 201) {
        // Nobody holds it, so nobody may keep it.
        WP_Application_Passwords::delete_application_password($user->ID, $uuid);

        return;
    }

    update_option(EZBIZI_SERVICE_LINKED, $uuid, false);
}

/**
 * Revoke our own copy when EzBizi says it no longer holds the pairing.
 *
 * Unlinking on the EzBizi side cannot reach into WordPress and delete an
 * application password — an application password is not allowed to manage
 * application passwords, which is the right rule and makes a push impossible.
 * So the read that already happens every few minutes carries the answer, and
 * the site revokes its own credential the moment it hears the pairing is over.
 */
function ezbizi_service_reconcile($payload) {
    if (! is_array($payload) || ! isset($payload['service_account']['linked'])) {
        return;
    }

    if ($payload['service_account']['linked']) {
        // A plugin update that changes the role reaches sites already paired:
        // re-applied once per version, not on every read.
        if (get_option('ezbizi_service_role_version') !== EZBIZI_VERSION) {
            ezbizi_service_role();
            update_option('ezbizi_service_role_version', EZBIZI_VERSION, false);
        }

        return;
    }

    if (get_option(EZBIZI_SERVICE_LINKED, false)) {
        ezbizi_service_unlink();
    }
}

/**
 * Take the access away again.
 *
 * Deleting the application password is what actually revokes it. The user is
 * left in place: content it published would be reassigned or orphaned by
 * deleting it, and a site owner who wants it gone can remove it from Users
 * like any other account.
 */
function ezbizi_service_unlink() {
    $uuid = get_option(EZBIZI_SERVICE_LINKED, false);
    $user = get_user_by('login', EZBIZI_SERVICE_LOGIN);

    if (is_string($uuid) && $user instanceof WP_User && class_exists('WP_Application_Passwords')) {
        WP_Application_Passwords::delete_application_password($user->ID, $uuid);
    }

    delete_option(EZBIZI_SERVICE_LINKED);
}

/*
 * A second way in, because the first one does not cover every site.
 *
 * `ezbizi_service_link()` otherwise runs only from `ezbizi_fetch()`, which
 * happens when a shortcode renders. A site that has installed the plugin and
 * pasted its token but not yet put a shortcode on a page never calls home, so
 * it never pairs — and that is the normal state for the first days of an
 * install. An admin screen load is a real HTTPS request by somebody who is
 * setting the site up, which makes it both the likeliest moment and the one
 * where `is_ssl()` is honestly true.
 *
 * Cheap on every later load: the function returns on its first line once the
 * site is paired.
 */
add_action('admin_init', 'ezbizi_service_link');

// Deactivating the plugin must not leave a working key behind.
register_deactivation_hook(dirname(__FILE__) . '/ezbizi.php', 'ezbizi_service_unlink');

/*
 * A changed token means a different site, or the same site re-paired. Either
 * way the credential belongs to the pairing that has just ended.
 */
add_action('update_option_ezbizi_token', 'ezbizi_service_unlink', 10, 0);
