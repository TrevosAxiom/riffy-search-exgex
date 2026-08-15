<?php

if (!defined('ABSPATH')) {
    exit;
}

$plugin = Rifnote_Search_Plugin::instance();
$plugin->enqueue_app_assets();

$nav_items = Rifnote_Search_Pages::nav_items();
$nav_groups = Rifnote_Search_Pages::nav_groups();
$search_query = is_search() ? get_search_query() : (isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '');
$dashboard_url = is_user_logged_in() ? home_url('/for-you/') : wp_login_url(home_url('/for-you/'));
$site_logo_url = esc_url(get_option('rifnote_site_logo_url', ''));
$site_icon_url = esc_url(get_site_icon_url(96));

if (!$site_icon_url) {
    $site_icon_url = esc_url(RIFNOTE_SEARCH_URL . 'public/rifnote-favicon.svg');
}

$mobile_categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC',
    'number' => 24,
));
$category_icon_for_slug = static function ($slug) {
    $slug = sanitize_key($slug);
    $icons = array(
        'notes' => '<svg viewBox="0 0 24 24" fill="none"><path d="M7 4h8l2 2v14H7z"/><path d="M15 4v4h4M9 11h6M9 15h6"/></svg>',
        'football' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 5h-5L8 10zM4 11l4-1M16 10l4 1M9.5 15 8 20M14.5 15l1.5 5"/></svg>',
        'sports' => '<svg viewBox="0 0 24 24" fill="none"><path d="M14 5a2 2 0 1 0-4 0 2 2 0 0 0 4 0ZM12 7l-2 5-4 2M12 7l3 4h4M10 12l4 3-1 5M9 13l-2 7"/></svg>',
        'world' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9M12 3c-2.5 2.7-3.8 5.7-3.8 9s1.3 6.3 3.8 9"/></svg>',
        'technology' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 6h14v10H5zM8 20h8M10 16l-1 4M14 16l1 4"/></svg>',
        'tech' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 6h14v10H5zM8 20h8M10 16l-1 4M14 16l1 4"/></svg>',
        'health' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 20s-8-4.8-8-10a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.2-8 10-8 10Z"/><path d="M7 12h3l1-2 2 5 1-3h3"/></svg>',
        'science' => '<svg viewBox="0 0 24 24" fill="none"><path d="M9 3h6M10 3v5l-5 9a3 3 0 0 0 2.6 4.5h8.8A3 3 0 0 0 19 17l-5-9V3"/><path d="M7 16h10"/></svg>',
        'politics' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 10h16M6 10v8M10 10v8M14 10v8M18 10v8M4 20h16M12 4l8 4H4z"/></svg>',
        'business' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/><path d="m4 9 6-4 6 7 4-3"/></svg>',
        'entertainment' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 7c3-2 6-2 9 0v7c0 3-2 5-4.5 6C6 19 4 17 4 14z"/><path d="M13 8c2-1 5-1 7 0v6c0 3-2 5-4.5 6-.8-.3-1.5-.8-2.1-1.4M7 11h0M10 11h0M7 15c1 .8 2 .8 3 0"/></svg>',
        'opinion' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 13h5"/></svg>',
        'crime' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4M11 7v8M7 11h8"/></svg>',
        'default' => '<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h11l3 3v13H5z"/><path d="M16 4v4h4M8 11h8M8 15h8"/></svg>',
    );

    return $icons[$slug] ?? $icons['default'];
};

$page_title = get_bloginfo('name');
$page_kicker = __('Rifnote', 'rifnote-search');
$page_description = '';
$is_notes_archive = is_category('notes');
$current_latest_posts = array();
$get_source_payload = static function ($post_id) {
    if (class_exists('Rifnote_Search_Source_Meta')) {
        return Rifnote_Search_Source_Meta::source_payload($post_id);
    }

    return array(
        'source_name' => get_bloginfo('name'),
        'source_logo_url' => '',
        'source_initials' => 'R',
        'read_full_story_url' => get_permalink($post_id),
        'original_url' => get_permalink($post_id),
        'has_external_source' => false,
    );
};
$story_link_for_post = static function ($post_id) use ($get_source_payload) {
    $source = $get_source_payload($post_id);
    $cluster_id = get_post_meta($post_id, 'story_cluster_id', true) ? get_post_meta($post_id, 'story_cluster_id', true) : 'post_' . $post_id;
    $has_story_hub = class_exists('Rifnote_Search_Aggregation') ? Rifnote_Search_Aggregation::post_has_story_hub($post_id) : false;

    return array(
        'url' => get_permalink($post_id),
        'external' => false,
        'source' => $source,
        'has_story_hub' => (bool) $has_story_hub,
        'story_url' => $has_story_hub ? home_url('/story/' . rawurlencode($cluster_id) . '/') : '',
    );
};
$render_source_logo = static function ($source) {
    $logo_url = esc_url($source['source_logo_url'] ?? '');
    $initials = sanitize_text_field((string) ($source['source_initials'] ?? 'R'));

    if (!$initials) {
        $initials = 'R';
    }

    echo '<span class="rs-public-source-logo">';

    if ($logo_url) {
        echo '<img src="' . esc_url($logo_url) . '" alt="" loading="lazy" />';
    }

    echo '<b>' . esc_html(substr($initials, 0, 2)) . '</b></span>';
};
$render_admin_actions = static function ($post_id) {
    $post_id = absint($post_id);

    if (!$post_id || (!current_user_can('edit_post', $post_id) && !current_user_can('delete_post', $post_id))) {
        return;
    }

    $title = wp_strip_all_tags(get_the_title($post_id));
    $edit_url = current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id, '') : '';
    $delete_url = current_user_can('delete_post', $post_id) ? get_delete_post_link($post_id, '', false) : '';

    echo '<div class="rs-public-admin-actions" aria-label="' . esc_attr__('Admin story actions', 'rifnote-search') . '">';

    if ($edit_url) {
        echo '<a href="' . esc_url($edit_url) . '" aria-label="' . esc_attr(sprintf(__('Edit %s', 'rifnote-search'), $title)) . '" title="' . esc_attr__('Edit story', 'rifnote-search') . '">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
        echo '</a>';
    }

    if ($delete_url) {
        echo '<a class="is-delete" href="' . esc_url($delete_url) . '" aria-label="' . esc_attr(sprintf(__('Move %s to trash', 'rifnote-search'), $title)) . '" title="' . esc_attr__('Move to trash', 'rifnote-search') . '" data-confirm="' . esc_attr(sprintf(__('Move "%s" to trash?', 'rifnote-search'), $title)) . '" onclick="return window.confirm(this.getAttribute(&quot;data-confirm&quot;));">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
        echo '</a>';
    }

    echo '</div>';
};
$story_image_for_post = static function ($post_id) {
    $post_id = absint($post_id);
    $image_url = $post_id ? get_the_post_thumbnail_url($post_id, 'large') : '';

    if (!$image_url && class_exists('Rifnote_Search_Admin')) {
        $image_url = Rifnote_Search_Admin::story_default_image_url($post_id);
    }

    return esc_url($image_url);
};
$latest_sidebar_posts = static function ($exclude = 0) {
    global $wpdb;

    $exclude = absint($exclude);
    $cache_key = 'rifnote_public_latest_sidebar_' . $exclude;
    $cached_ids = get_transient($cache_key);

    if (is_array($cached_ids)) {
        return array_values(array_filter(array_map('get_post', array_map('absint', $cached_ids))));
    }

    $where = "p.post_type = 'post'
        AND p.post_status = 'publish'
        AND (
            (pm.meta_key = 'rifnote_origin_channel' AND pm.meta_value = 'admin')
            OR (pm.meta_key = 'rifnote_origin_model' AND pm.meta_value = 'Rifnote Admin')
        )";
    $params = array();

    if ($exclude) {
        $where .= ' AND p.ID != %d';
        $params[] = $exclude;
    }

    $sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        WHERE {$where}
        ORDER BY p.post_date DESC
        LIMIT 6
    ";
    $ids = $params ? $wpdb->get_col($wpdb->prepare($sql, $params)) : $wpdb->get_col($sql);
    $posts = array_values(array_filter(array_map('get_post', array_map('absint', $ids))));

    set_transient($cache_key, wp_list_pluck($posts, 'ID'), 30 * MINUTE_IN_SECONDS);

    return $posts;
};
$manual_adjacent_posts = static function ($post_id) {
    global $wpdb;

    $post_id = absint($post_id);
    $cache_key = 'rifnote_public_adjacent_' . $post_id;
    $cached_ids = get_transient($cache_key);

    if (is_array($cached_ids)) {
        return array(
            'previous' => !empty($cached_ids['previous']) ? get_post((int) $cached_ids['previous']) : null,
            'next' => !empty($cached_ids['next']) ? get_post((int) $cached_ids['next']) : null,
        );
    }

    $post_date = get_post_field('post_date', $post_id);
    $previous_sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        WHERE p.post_type = 'post'
          AND p.post_status = 'publish'
          AND p.ID != %d
          AND p.post_date < %s
          AND (
              (pm.meta_key = 'rifnote_origin_channel' AND pm.meta_value = 'admin')
              OR (pm.meta_key = 'rifnote_origin_model' AND pm.meta_value = 'Rifnote Admin')
          )
        ORDER BY p.post_date DESC
        LIMIT 1
    ";
    $next_sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        WHERE p.post_type = 'post'
          AND p.post_status = 'publish'
          AND p.ID != %d
          AND p.post_date > %s
          AND (
              (pm.meta_key = 'rifnote_origin_channel' AND pm.meta_value = 'admin')
              OR (pm.meta_key = 'rifnote_origin_model' AND pm.meta_value = 'Rifnote Admin')
          )
        ORDER BY p.post_date ASC
        LIMIT 1
    ";

    $previous_id = (int) $wpdb->get_var($wpdb->prepare($previous_sql, $post_id, $post_date));
    $next_id = (int) $wpdb->get_var($wpdb->prepare($next_sql, $post_id, $post_date));

    $result = array(
        'previous' => $previous_id ? get_post($previous_id) : null,
        'next' => $next_id ? get_post($next_id) : null,
    );

    set_transient($cache_key, array(
        'previous' => $result['previous'] ? $result['previous']->ID : 0,
        'next' => $result['next'] ? $result['next']->ID : 0,
    ), HOUR_IN_SECONDS);

    return $result;
};
if (is_singular()) {
    $page_title = get_the_title();
    $page_kicker = is_page() ? __('Page', 'rifnote-search') : __('Story', 'rifnote-search');
    $page_description = is_singular('post') ? get_the_excerpt() : '';
} elseif (is_search()) {
    $page_title = sprintf(__('Search results for “%s”', 'rifnote-search'), get_search_query());
    $page_kicker = __('Search', 'rifnote-search');
    $page_description = __('Stories and pages from across Rifnote.', 'rifnote-search');
} elseif (is_home()) {
    $page_title = __('Latest stories', 'rifnote-search');
    $page_kicker = __('Blog', 'rifnote-search');
    $page_description = __('Fresh posts, updates and source-backed notes from Rifnote.', 'rifnote-search');
} elseif (is_archive()) {
    if ($is_notes_archive) {
        $page_title = __('Live Notes', 'rifnote-search');
        $page_kicker = __('Editor notes', 'rifnote-search');
        $page_description = '';
    } elseif (is_category()) {
        $page_title = single_cat_title('', false);
        $page_kicker = __('Archive', 'rifnote-search');
        $page_description = wp_strip_all_tags(category_description());
    } elseif (is_tag()) {
        $page_title = single_tag_title('', false);
        $page_kicker = __('Topic', 'rifnote-search');
        $page_description = wp_strip_all_tags(tag_description());
    } elseif (is_author()) {
        $page_title = get_the_author();
        $page_kicker = __('Author', 'rifnote-search');
        $page_description = '';
    } else {
        $page_title = wp_strip_all_tags(get_the_archive_title());
        $page_kicker = __('Archive', 'rifnote-search');
        $page_description = wp_strip_all_tags(get_the_archive_description());
    }
} elseif (is_404()) {
    $page_title = __('This page slipped away', 'rifnote-search');
    $page_kicker = __('404', 'rifnote-search');
    $page_description = __('Try a search, jump into football, or head back to the main Rifnote desk.', 'rifnote-search');
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script>
        window.RIFNOTE_SEARCH = <?php echo wp_json_encode($plugin->runtime_context()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
    </script>
    <?php wp_head(); ?>
</head>
<body <?php body_class('rifnote-search-plugin-page rifnote-public-wp-page'); ?>>
<?php wp_body_open(); ?>
<div class="rs-pwa-launch" role="status" aria-live="polite">
    <div class="rs-pwa-launch-card">
        <img src="<?php echo esc_url(Rifnote_Search_PWA::app_icon_url()); ?>" alt="" />
        <strong><?php esc_html_e('Rifnote', 'rifnote-search'); ?></strong>
        <span class="rs-pwa-launch-ring" aria-hidden="true"></span>
        <small><?php esc_html_e('Loading your desk', 'rifnote-search'); ?></small>
    </div>
</div>
<div class="rs-plugin-page">
    <div class="rs-public-route-loader" aria-live="polite" aria-hidden="true">
        <div class="rs-public-route-glass" role="status">
            <span class="rs-public-route-orb" aria-hidden="true"></span>
            <span class="rs-public-route-line is-wide" aria-hidden="true"></span>
            <span class="rs-public-route-line" aria-hidden="true"></span>
            <span class="rs-sr-only"><?php esc_html_e('Loading', 'rifnote-search'); ?></span>
        </div>
    </div>
    <header class="rs-plugin-header<?php echo $site_logo_url ? ' has-site-logo' : ''; ?>" role="banner">
        <a class="rs-plugin-brand<?php echo $site_logo_url ? ' has-site-logo' : ''; ?>" href="<?php echo esc_url(home_url('/search/')); ?>">
            <?php if ($site_logo_url) : ?>
                <img class="rs-plugin-logo" src="<?php echo $site_logo_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
                <img class="rs-plugin-favicon" src="<?php echo $site_icon_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
            <?php else : ?>
                <span class="rs-plugin-mark" aria-hidden="true">R</span>
                <img class="rs-plugin-favicon" src="<?php echo $site_icon_url; ?>" alt="<?php esc_attr_e('Rifnote Search', 'rifnote-search'); ?>" />
                <span>
                    <strong><?php esc_html_e('Rifnote Search', 'rifnote-search'); ?></strong>
                    <small><?php esc_html_e('News, scores, trends', 'rifnote-search'); ?></small>
                </span>
            <?php endif; ?>
        </a>
        <form class="rs-plugin-search" action="<?php echo esc_url(home_url('/search/')); ?>" method="get" role="search">
            <input name="q" type="search" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search news and trends', 'rifnote-search'); ?>" />
            <button type="submit"><span class="rs-search-button-text"><?php esc_html_e('Search', 'rifnote-search'); ?></span><svg class="rs-search-button-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg></button>
        </form>
        <div class="rs-plugin-actions">
            <?php require RIFNOTE_SEARCH_DIR . 'templates/partials/menu-drawer.php'; ?>
            <button class="rs-plugin-install" type="button" hidden><?php esc_html_e('Install App', 'rifnote-search'); ?></button>
            <a class="rs-plugin-login" href="<?php echo esc_url($dashboard_url); ?>" aria-label="<?php esc_attr_e('Open user dashboard', 'rifnote-search'); ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M20 21a8 8 0 0 0-16 0" /><circle cx="12" cy="7" r="4" /></svg>
                <b><?php esc_html_e('Account', 'rifnote-search'); ?></b>
            </a>
        </div>
    </header>

    <main class="rs-plugin-main rs-public-main" id="main">
        <section class="rs-public-layout<?php echo is_singular('post') ? ' is-story-layout' : ''; ?>">
            <div class="rs-public-content">
                <?php if (!is_singular('post')) : ?>
                    <header class="rs-public-hero">
                        <span><?php echo esc_html($page_kicker); ?></span>
                        <h1><?php echo esc_html($page_title); ?></h1>
                        <?php if ($page_description) : ?>
                            <p><?php echo esc_html($page_description); ?></p>
                        <?php endif; ?>
                    </header>
                <?php endif; ?>

                <?php if (is_404()) : ?>
                    <section class="rs-public-card rs-public-404">
                        <p><?php esc_html_e('No wahala. Search the topic again or jump into one of the busy corners of Rifnote.', 'rifnote-search'); ?></p>
                        <div class="rs-public-actions">
                            <a class="rs-button primary" href="<?php echo esc_url(home_url('/search/')); ?>"><?php esc_html_e('Search Rifnote', 'rifnote-search'); ?></a>
                            <a class="rs-button ghost" href="<?php echo esc_url(home_url('/football/')); ?>"><?php esc_html_e('Open Football', 'rifnote-search'); ?></a>
                        </div>
                    </section>
                <?php elseif (is_singular()) : ?>
                    <?php while (have_posts()) : the_post(); ?>
                        <?php
                        $current_source = $get_source_payload(get_the_ID());
                        $is_external_story = !empty($current_source['has_external_source']);
                        $story_image_url = 'post' === get_post_type() ? $story_image_for_post(get_the_ID()) : '';
                        $news_now_posts = $latest_sidebar_posts(get_the_ID());
                        $current_latest_posts = $news_now_posts;
                        ?>
                        <article <?php post_class(('post' === get_post_type() ? 'rs-public-story ' : 'rs-public-article ') . ($is_external_story ? 'is-external-source' : 'is-rifnote-original')); ?>>
                            <?php if ('post' === get_post_type()) : ?>
                                <?php if ($story_image_url) : ?>
                                    <figure class="rs-public-story-media">
                                        <img src="<?php echo esc_url($story_image_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" loading="eager" />
                                    </figure>
                                <?php endif; ?>
                                <header class="rs-public-story-title-card">
                                    <?php $render_admin_actions(get_the_ID()); ?>
                                    <h1><?php the_title(); ?></h1>
                                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('F j, Y')); ?></time>
                                </header>
                            <?php elseif (has_post_thumbnail()) : ?>
                                <figure class="rs-public-featured"><?php the_post_thumbnail('large'); ?></figure>
                            <?php endif; ?>
                            <?php if ('post' === get_post_type()) : ?>
                                <?php echo do_shortcode('[rifnote_share_links id="' . absint(get_the_ID()) . '"]'); ?>
                            <?php endif; ?>
                            <div class="rs-public-body">
                                <?php the_content(); ?>
                            </div>
                            <?php if ('post' === get_post_type() && $news_now_posts) : ?>
                                <section class="rs-public-news-now" aria-label="<?php esc_attr_e('News now', 'rifnote-search'); ?>">
                                    <h2><?php esc_html_e('More News:', 'rifnote-search'); ?></h2>
                                    <ul>
                                        <?php foreach (array_slice($news_now_posts, 0, 4) as $news_post) : ?>
                                            <li><a href="<?php echo esc_url(get_permalink($news_post)); ?>"><?php echo esc_html(get_the_title($news_post)); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </section>
                            <?php endif; ?>
                            <?php
                            wp_link_pages(array(
                                'before' => '<nav class="rs-public-pages">' . esc_html__('Pages:', 'rifnote-search'),
                                'after' => '</nav>',
                            ));
                            ?>
                            <?php if ('post' === get_post_type()) : ?>
                                <?php
                                $adjacent_posts = $manual_adjacent_posts(get_the_ID());
                                $previous_post = $adjacent_posts['previous'];
                                $next_post = $adjacent_posts['next'];
                                ?>
                                <?php if ($previous_post || $next_post) : ?>
                                    <nav class="rs-public-prevnext" aria-label="<?php esc_attr_e('Adjacent stories', 'rifnote-search'); ?>">
                                        <?php if ($previous_post) : ?>
                                            <a href="<?php echo esc_url(get_permalink($previous_post)); ?>"><span><?php esc_html_e('Previous', 'rifnote-search'); ?></span><strong><?php echo esc_html(get_the_title($previous_post)); ?></strong></a>
                                        <?php endif; ?>
                                        <?php if ($next_post) : ?>
                                            <a href="<?php echo esc_url(get_permalink($next_post)); ?>"><span><?php esc_html_e('Next', 'rifnote-search'); ?></span><strong><?php echo esc_html(get_the_title($next_post)); ?></strong></a>
                                        <?php endif; ?>
                                    </nav>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                        <?php if (comments_open() || get_comments_number()) : ?>
                            <section class="rs-public-comments"><?php comments_template(); ?></section>
                        <?php endif; ?>
                    <?php endwhile; ?>
                <?php else : ?>
                    <div class="rs-public-notes-archive" data-rs-archive-list>
                        <?php if (have_posts()) : ?>
                            <?php while (have_posts()) : the_post(); ?>
                                <?php
                                $story_link = $story_link_for_post(get_the_ID());
                                $story_target = $story_link['external'] ? '_blank' : '';
                                $story_rel = $story_link['external'] ? 'noreferrer' : '';
                                ?>
                                <article <?php post_class('rs-public-note-item'); ?>>
                                    <?php $render_admin_actions(get_the_ID()); ?>
                                    <span class="rs-public-note-dot" aria-hidden="true"></span>
                                    <div>
                                        <div class="rs-public-meta">
                                            <span><?php echo esc_html(get_the_date()); ?></span>
                                            <span class="rs-public-source-chip"><?php $render_source_logo($story_link['source']); ?><?php echo esc_html($story_link['source']['source_name'] ?: get_the_author()); ?></span>
                                            <?php if (!$is_notes_archive) : ?>
                                                <span><?php echo esc_html(wp_strip_all_tags(get_the_category_list(', '))); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <h2><a href="<?php echo esc_url($story_link['url']); ?>"<?php echo $story_target ? ' target="' . esc_attr($story_target) . '"' : ''; ?><?php echo $story_rel ? ' rel="' . esc_attr($story_rel) . '"' : ''; ?>><?php the_title(); ?></a></h2>
                                        <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 34)); ?></p>
                                        <?php if ($story_link['has_story_hub'] && $story_link['story_url']) : ?>
                                            <div class="rs-public-story-actions">
                                                <a class="rs-public-aggregation-link" href="<?php echo esc_url($story_link['story_url']); ?>"><?php esc_html_e('Breakdown this Story', 'rifnote-search'); ?></a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endwhile; ?>
                            <?php $next_archive_url = get_next_posts_page_link(); ?>
                            <?php if ($next_archive_url) : ?>
                                <div class="rs-public-load-more-wrap">
                                    <button class="rs-public-load-more" type="button" data-rs-load-more data-next-url="<?php echo esc_url($next_archive_url); ?>"><?php esc_html_e('Load More', 'rifnote-search'); ?></button>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <section class="rs-public-card">
                                <h2><?php esc_html_e('Nothing landed yet', 'rifnote-search'); ?></h2>
                                <p><?php esc_html_e('Try a different search or check the latest notes on the homepage.', 'rifnote-search'); ?></p>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (is_singular('post')) : ?>
                <aside class="rs-public-latest-sidebar" aria-label="<?php esc_attr_e('Latest news', 'rifnote-search'); ?>">
                    <h2><?php esc_html_e('Latest News', 'rifnote-search'); ?></h2>
                    <?php foreach ($current_latest_posts as $index => $latest_post) : ?>
                        <a class="<?php echo 0 === $index ? 'is-lead' : ''; ?>" href="<?php echo esc_url(get_permalink($latest_post)); ?>"><?php echo esc_html(get_the_title($latest_post)); ?></a>
                    <?php endforeach; ?>
                </aside>
            <?php else : ?>
                <aside class="rs-public-live-mount" aria-label="<?php esc_attr_e('Live updates', 'rifnote-search'); ?>">
                    <?php echo $plugin->render_app('sitewide-live'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </aside>
            <?php endif; ?>
        </section>
    </main>

    <footer class="rs-plugin-footer" role="contentinfo">
        <p>&copy; <?php echo esc_html(gmdate('Y')); ?> <?php esc_html_e('Rifnote Search', 'rifnote-search'); ?></p>
        <nav aria-label="<?php esc_attr_e('Rifnote Search footer', 'rifnote-search'); ?>">
            <a href="<?php echo esc_url(home_url('/publisher-dashboard/')); ?>"><?php esc_html_e('Publisher Hub', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/submit-news/')); ?>"><?php esc_html_e('Send a Story', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/publisher-docs/')); ?>"><?php esc_html_e('Publisher Guide', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/beta-feedback/')); ?>"><?php esc_html_e('Beta Feedback', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/daily-briefing/')); ?>"><?php esc_html_e('Daily Drop', 'rifnote-search'); ?></a>
            <a href="<?php echo esc_url(home_url('/for-you/')); ?>"><?php esc_html_e('My Feed', 'rifnote-search'); ?></a>
        </nav>
    </footer>

    <nav class="rs-mobile-tabbar" aria-label="<?php esc_attr_e('Primary mobile navigation', 'rifnote-search'); ?>">
        <a href="<?php echo esc_url(home_url('/search/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/></svg></span><b><?php esc_html_e('Home', 'rifnote-search'); ?></b></a>
        <a href="<?php echo esc_url(home_url('/category/notes/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span><b><?php esc_html_e('Notes', 'rifnote-search'); ?></b></a>
        <a href="<?php echo esc_url(home_url('/football/')); ?>"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 5h-5L8 10zM4 11l4-1M16 10l4 1M9.5 15 8 20M14.5 15l1.5 5"/></svg></span><b><?php esc_html_e('Football', 'rifnote-search'); ?></b></a>
        <a class="is-live" href="#live-updates" data-rs-live-open><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4"/><path d="M4.9 4.9a10 10 0 0 0 0 14.2M19.1 4.9a10 10 0 0 1 0 14.2"/></svg></span><b><?php esc_html_e('LIVE', 'rifnote-search'); ?></b></a>
        <button class="rs-mobile-menu-button" type="button" data-rs-menu-open aria-label="<?php esc_attr_e('Open menu', 'rifnote-search'); ?>">
            <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span>
            <b><?php esc_html_e('Menu', 'rifnote-search'); ?></b>
        </button>
    </nav>
    <script>
        document.addEventListener('click', function(event) {
            var opener = event.target.closest('[data-rs-live-open]');
            if (!opener) return;
            event.preventDefault();
            document.dispatchEvent(new CustomEvent('rifnote:open-live'));
        });

        document.addEventListener('click', function(event) {
            var tab = event.target.closest('[data-rs-mobile-tab]');
            if (!tab) return;

            var panel = tab.closest('.rs-mobile-menu-panel');
            if (!panel) return;

            var target = tab.getAttribute('data-rs-mobile-tab');
            panel.querySelectorAll('[data-rs-mobile-tab]').forEach(function(button) {
                var active = button === tab;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panel.querySelectorAll('[data-rs-mobile-panel]').forEach(function(section) {
                section.classList.toggle('is-active', section.getAttribute('data-rs-mobile-panel') === target);
            });
        });

        (function() {
            var loader = document.querySelector('.rs-public-route-loader');
            var archiveList = document.querySelector('[data-rs-archive-list]');
            var sameOrigin = window.location.origin;

            function showLoader() {
                if (!loader) return;
                loader.setAttribute('aria-hidden', 'false');
                document.documentElement.classList.add('rs-public-is-loading');
            }

            function hideLoader() {
                if (!loader) return;
                loader.setAttribute('aria-hidden', 'true');
                document.documentElement.classList.remove('rs-public-is-loading');
            }

            document.addEventListener('submit', function(event) {
                var form = event.target.closest('.rs-plugin-search');
                if (!form) return;
                showLoader();
            });

            document.addEventListener('click', function(event) {
                var copyButton = event.target.closest('[data-rs-copy-link]');
                if (copyButton) {
                    event.preventDefault();
                    var copyUrl = copyButton.getAttribute('data-rs-copy-link') || window.location.href;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(copyUrl);
                    }
                    copyButton.classList.add('copied');
                    setTimeout(function() { copyButton.classList.remove('copied'); }, 1200);
                    return;
                }

                var loadMore = event.target.closest('[data-rs-load-more]');
                if (loadMore) {
                    event.preventDefault();
                    var nextUrl = loadMore.getAttribute('data-next-url');

                    if (!nextUrl || !archiveList || loadMore.disabled) return;

                    loadMore.disabled = true;
                    loadMore.classList.add('is-loading');
                    loadMore.textContent = '<?php echo esc_js(__('Loading...', 'rifnote-search')); ?>';

                    fetch(nextUrl, { credentials: 'same-origin' })
                        .then(function(response) { return response.text(); })
                        .then(function(html) {
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');
                            var nextList = doc.querySelector('[data-rs-archive-list]');
                            var nextButton = doc.querySelector('[data-rs-load-more]');
                            var loadMoreWrap = loadMore.closest('.rs-public-load-more-wrap');

                            if (nextList) {
                                nextList.querySelectorAll('.rs-public-note-item').forEach(function(item) {
                                    archiveList.insertBefore(document.importNode(item, true), loadMoreWrap);
                                });
                            }

                            if (nextButton && nextButton.getAttribute('data-next-url')) {
                                loadMore.disabled = false;
                                loadMore.classList.remove('is-loading');
                                loadMore.setAttribute('data-next-url', nextButton.getAttribute('data-next-url'));
                                loadMore.textContent = '<?php echo esc_js(__('Load More', 'rifnote-search')); ?>';
                            } else {
                                loadMore.closest('.rs-public-load-more-wrap')?.remove();
                            }
                        })
                        .catch(function() {
                            loadMore.disabled = false;
                            loadMore.classList.remove('is-loading');
                            loadMore.textContent = '<?php echo esc_js(__('Try Again', 'rifnote-search')); ?>';
                        });
                    return;
                }

                var link = event.target.closest('a[href]');
                if (!link || link.target || link.hasAttribute('download') || link.closest('[data-rs-live-open]') || link.closest('[data-rs-load-more]')) return;
                var href = link.getAttribute('href') || '';
                if (!href || href.charAt(0) === '#' || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return;

                try {
                    var url = new URL(href, window.location.href);
                    if (url.origin === sameOrigin && url.href !== window.location.href) {
                        showLoader();
                    }
                } catch (_) {}
            });

            window.addEventListener('pageshow', hideLoader);
        })();
    </script>
</div>
<?php wp_footer(); ?>
</body>
</html>
