<?php
/**
 * The SEO Helper's hands on this site: read and write each page's search
 * title, description and focus keyphrase.
 *
 * Jose, 2026-09-28: EzBizi gets its own SEO helper in place of A1's editor.
 * The site's SEO plugin (Yoast Premium on every site today) stays the part
 * that prints the tags, the sitemap and the schema; these routes only read
 * and change the values it prints, so EzBizi keeps no second copy to drift.
 *
 * Called by EzBizi with the service account the site handed over at pairing
 * (Basic auth with an application password), so WordPress itself decides
 * whether that account may edit the page.
 */

if (! defined('ABSPATH')) {
    exit;
}

/** EzBizi's field name → the meta key the SEO plugin reads. */
function ezbizi_seo_keys() {
    return array(
        'title' => '_yoast_wpseo_title',
        'description' => '_yoast_wpseo_metadesc',
        'keyphrase' => '_yoast_wpseo_focuskw',
        'noindex' => '_yoast_wpseo_meta-robots-noindex',
        'canonical' => '_yoast_wpseo_canonical',
        'social_title' => '_yoast_wpseo_opengraph-title',
        'social_description' => '_yoast_wpseo_opengraph-description',
        'social_image' => '_yoast_wpseo_opengraph-image',
        // X keeps its own overrides; empty means it uses the social ones above
        // (Rodrigo's audit, 2026-10-09: About Us still said 2023 on X).
        'twitter_title' => '_yoast_wpseo_twitter-title',
        'twitter_description' => '_yoast_wpseo_twitter-description',
        'twitter_image' => '_yoast_wpseo_twitter-image',
    );
}

add_action('rest_api_init', function () {
    $can_edit = function ($request) {
        $id = (int) $request['id'];

        // A page that does not exist lets the route say 404, not a 403 that reads
        // like a broken account (Rodrigo's audit F8, 2026-10-09).
        return $id > 0 && get_post($id) ? current_user_can('edit_post', $id) : current_user_can('edit_pages');
    };

    register_rest_route('ezbizi/v1', '/seo', array(
        'methods' => 'GET',
        'callback' => 'ezbizi_seo_list',
        'permission_callback' => $can_edit,
    ));
    register_rest_route('ezbizi/v1', '/seo/(?P<id>\d+)', array(
        array('methods' => 'GET', 'callback' => 'ezbizi_seo_one', 'permission_callback' => $can_edit),
        array('methods' => 'POST', 'callback' => 'ezbizi_seo_save', 'permission_callback' => $can_edit),
    ));
    // The site's default titles, which the Helper can correct (a year written
    // out instead of the year variable). Site-wide, so it takes more than
    // editing one page does.
    register_rest_route('ezbizi/v1', '/seo/site', array(
        'methods' => 'POST',
        'callback' => 'ezbizi_seo_site_save',
        'permission_callback' => function () {
            return current_user_can('edit_published_pages');
        },
    ));
    register_rest_route('ezbizi/v1', '/seo/(?P<id>\d+)/preview', array(
        'methods' => 'POST',
        'callback' => 'ezbizi_seo_preview',
        'permission_callback' => $can_edit,
    ));
});

/** GET /seo?page=1&per_page=100 — every published page and post, with its SEO. */
function ezbizi_seo_list($request) {
    $per = max(1, min(200, (int) ($request['per_page'] ?: 100)));
    $query = new WP_Query(array(
        'post_type' => array('page', 'post'),
        'post_status' => 'publish',
        'posts_per_page' => $per,
        'paged' => max(1, (int) ($request['page'] ?: 1)),
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => false,
    ));

    return new WP_REST_Response(array(
        'total' => (int) $query->found_posts,
        'pages' => array_map('ezbizi_seo_row', $query->posts),
        'site' => ezbizi_seo_site(),
    ));
}

function ezbizi_seo_one($request) {
    $post = get_post((int) $request['id']);

    return $post ? new WP_REST_Response(ezbizi_seo_row($post)) : new WP_Error('not_found', 'No such page.', array('status' => 404));
}

/** POST /seo/{id} — the fields sent are written; an empty one is cleared. */
function ezbizi_seo_save($request) {
    $post = get_post((int) $request['id']);

    if (! $post) {
        return new WP_Error('not_found', 'No such page.', array('status' => 404));
    }

    foreach (ezbizi_seo_keys() as $field => $meta) {
        if (! $request->has_param($field)) {
            continue;
        }

        $value = $request[$field];
        $value = $field === 'noindex' ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '')
            : (in_array($field, array('canonical', 'social_image', 'twitter_image'), true) ? esc_url_raw((string) $value) : sanitize_text_field((string) $value));

        if ($value === '') {
            delete_post_meta($post->ID, $meta);
        } else {
            update_post_meta($post->ID, $meta, $value);
        }
    }

    ezbizi_seo_rebuild($post->ID);

    return new WP_REST_Response(ezbizi_seo_row(get_post($post->ID)));
}

/** POST /seo/{id}/preview — what a draft title and description would print, without saving. */
function ezbizi_seo_preview($request) {
    $post = get_post((int) $request['id']);

    if (! $post) {
        return new WP_Error('not_found', 'No such page.', array('status' => 404));
    }

    return new WP_REST_Response(array(
        'title' => ezbizi_seo_resolve((string) $request['title'], $post, 'title'),
        'description' => ezbizi_seo_resolve((string) $request['description'], $post, 'description'),
    ));
}

/** One page: what is stored, and what the SEO plugin will print from it. */
function ezbizi_seo_row($post) {
    $stored = array();

    foreach (ezbizi_seo_keys() as $field => $meta) {
        $stored[$field] = (string) get_post_meta($post->ID, $meta, true);
    }

    $stored['noindex'] = $stored['noindex'] === '1';
    $final = array('title' => '', 'description' => '');

    if (function_exists('YoastSEO')) {
        try {
            $final = ezbizi_seo_printed($post->ID);

            // The SEO plugin prints from its own per-page record. Values written
            // straight to the meta (an import, another tool) leave that record
            // behind, and the site's default printed instead: a lone "%" on 257
            // pages (Rodrigo's audit F1, 2026-10-09). Rebuild it and read again.
            if ($stored['description'] !== '' && $final['description'] !== ezbizi_seo_resolve($stored['description'], $post, 'description')) {
                ezbizi_seo_reindex($post->ID);
                $final = ezbizi_seo_printed($post->ID);
            }
        } catch (\Throwable $e) {
            // Fall through to our own reading.
        }
    }

    if ($final['title'] === '') {
        $final['title'] = ezbizi_seo_resolve($stored['title'], $post, 'title');
        $final['description'] = ezbizi_seo_resolve($stored['description'], $post, 'description');
    }

    return array(
        'id' => $post->ID,
        'type' => $post->post_type,
        'status' => $post->post_status,
        'name' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
        'url' => get_permalink($post),
        'slug' => $post->post_name,
        'modified' => $post->post_modified_gmt,
        'seo' => $stored,
        'final' => $final,
    );
}

/** What the SEO plugin prints for a page: search, social and X. */
function ezbizi_seo_printed($id) {
    $meta = YoastSEO()->meta->for_post($id);

    return array(
        'title' => (string) $meta->title,
        'description' => (string) $meta->description,
        'social_title' => (string) $meta->open_graph_title,
        'social_description' => (string) $meta->open_graph_description,
        'twitter_title' => (string) $meta->twitter_title,
        'twitter_description' => (string) $meta->twitter_description,
    );
}

/** A title or description with the SEO plugin's %%variables%% filled in; the site default when empty. */
function ezbizi_seo_resolve($text, $post, $kind) {
    if ($text === '' && $kind === 'title') {
        $text = ezbizi_seo_template('title-' . $post->post_type);
    }

    if ($text === '') {
        return '';
    }

    return function_exists('wpseo_replace_vars') ? trim((string) wpseo_replace_vars($text, $post)) : trim($text);
}

/** Site-wide settings the Helper checks: the page title template, the separator, the site name. */
function ezbizi_seo_site() {
    return array(
        'plugin' => defined('WPSEO_VERSION') ? 'yoast ' . WPSEO_VERSION : null,
        'page_title_template' => ezbizi_seo_template('title-page'),
        'post_title_template' => ezbizi_seo_template('title-post'),
        'social_page_title_template' => ezbizi_seo_template('social-title-page'),
        'social_post_title_template' => ezbizi_seo_template('social-title-post'),
        'site_name' => get_bloginfo('name'),
        'search_engines_blocked' => ! get_option('blog_public'),
    );
}

/** POST /seo/site — the default page and post titles. */
function ezbizi_seo_site_save($request) {
    $titles = get_option('wpseo_titles');

    if (! is_array($titles)) {
        return new WP_Error('no_seo_plugin', 'This site has no SEO plugin settings to change.', array('status' => 409));
    }

    foreach (ezbizi_seo_site_fields() as $field => $key) {
        if ($request->has_param($field)) {
            $titles[$key] = sanitize_text_field((string) $request[$field]);
        }
    }

    update_option('wpseo_titles', $titles);

    // Every page's title may have changed.
    if (function_exists('ezbizi_purge_page_cache')) {
        ezbizi_purge_page_cache();
    }

    return new WP_REST_Response(ezbizi_seo_site());
}

/** The site-wide title settings the Helper may change → where the SEO plugin keeps each. */
function ezbizi_seo_site_fields() {
    return array(
        'page_title_template' => 'title-page',
        'post_title_template' => 'title-post',
        'social_page_title_template' => 'social-title-page',
        'social_post_title_template' => 'social-title-post',
    );
}

/** One of the SEO plugin's site-wide title settings, read from where it keeps them. */
function ezbizi_seo_template($key) {
    $titles = get_option('wpseo_titles');

    return is_array($titles) && isset($titles[$key]) ? (string) $titles[$key] : '';
}

/**
 * Make the SEO plugin see the new values now. It keeps its own per-page record
 * of them, and a write to the meta alone is not always noticed until the page
 * is next saved in the editor.
 */
function ezbizi_seo_rebuild($id) {
    clean_post_cache($id);
    do_action('wpseo_saved_postdata');

    // A cached copy of the page would keep showing the old title.
    if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_page_cache_by_post')) {
        Cache_Enabler::clear_page_cache_by_post($id);
    }

    // And the host's own page cache in front of WordPress, which keeps pages for two hours.
    if (function_exists('ezbizi_queue_nginx_purge')) {
        ezbizi_queue_nginx_purge();
    }

    ezbizi_seo_reindex($id);
}

/** Rebuild the SEO plugin's own record of one page from its meta. */
function ezbizi_seo_reindex($id) {
    if (! function_exists('YoastSEO')) {
        return;
    }

    try {
        clean_post_cache($id);
        $repository = YoastSEO()->classes->get('Yoast\WP\SEO\Repositories\Indexable_Repository');
        $builder = YoastSEO()->classes->get('Yoast\WP\SEO\Builders\Indexable_Builder');
        $builder->build_for_id_and_type($id, 'post', $repository->find_by_id_and_type($id, 'post', false));
        // It also remembers what it printed earlier in this request.
        YoastSEO()->classes->get('Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer')->clear();
    } catch (\Throwable $e) {
        // The next save in the editor rebuilds it; the meta is written either way.
    }
}
