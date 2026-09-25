<?php
/**
 * Put a site's pages back as they were before a1tools-swap.php wrote them.
 *
 *   wp eval-file /tmp/a1tools-restore.php          (dry: counts only)
 *   wp eval-file /tmp/a1tools-restore.php write
 *
 * Reads the backups the swap left in /tmp/a1swap/<host>/<post id>.json. The
 * backups stay, so a re-swap after this starts from the same originals.
 */
global $wpdb;

$write = in_array('write', isset($args) ? (array) $args : array(), true);
$host = preg_replace('/^www\./', '', (string) parse_url(home_url(), PHP_URL_HOST));
$n = array('posts' => 0, 'meta' => 0);

foreach (glob('/tmp/a1swap/' . $host . '/*.json') ?: array() as $file) {
    $b = json_decode(file_get_contents($file), true);
    $id = (int) ($b['ID'] ?? 0);

    if (! $id || ! get_post($id)) {
        continue;
    }

    $n['posts']++;

    if ($write) {
        $wpdb->update($wpdb->posts, array('post_content' => (string) $b['post_content']), array('ID' => $id));
    }

    foreach ((array) ($b['meta'] ?? array()) as $key => $value) {
        $n['meta']++;

        // Stored raw (as the database held it), so written back raw.
        if ($write) {
            $wpdb->update($wpdb->postmeta, array('meta_value' => $value), array('post_id' => $id, 'meta_key' => $key));
        }
    }

    if ($write) {
        delete_post_meta($id, '_elementor_css');
        delete_post_meta($id, '_elementor_element_cache');
        clean_post_cache($id);
    }
}

if ($write) {
    try {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    } catch (\Throwable $e) {
    }
}

echo 'RESTORE ', wp_json_encode(array('host' => $host, 'mode' => $write ? 'write' : 'dry') + $n), "\n";
