<?php
/**
 * Deleting the plugin takes its data with it.
 *
 * The token is the reason this file exists rather than being optional tidiness:
 * it is a live credential for one website, and leaving it in `wp_options` after
 * somebody deletes the plugin means it survives in every database backup taken
 * afterwards, on a site that is no longer using it.
 *
 * The cached values go too. They are the company's public business details
 * rather than anything sensitive, but a stale copy of them serves nobody once
 * the thing that renders them is gone.
 *
 * Deleting here does NOT revoke the token at our end — nothing on a customer's
 * server can be trusted to do that. Revoking is "Issue a new token" in EzBizi,
 * under Web Management, and the plugin's own screen says so.
 */

// Set by WordPress when it runs this file. Its absence means the file was
// reached some other way, which is the one case where it must do nothing.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Literal names rather than the constants in ezbizi.php: WordPress runs this
// file on its own, and the plugin is not loaded when it does.
delete_option('ezbizi_token');
delete_option('ezbizi_api_base');
delete_option('ezbizi_last_good');
delete_option('ezbizi_last_good_fp');

delete_transient('ezbizi_payload');
delete_transient('ezbizi_refreshing');

wp_clear_scheduled_hook('ezbizi_refresh');

// ponytail: single site only. A network-activated multisite install would need
// a loop over get_sites() with switch_to_blog(); worth adding the day somebody
// runs this across a network, and dead weight until then.
