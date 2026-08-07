<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Operations {
    public static function feed_diagnostics($publisher_id) {
        global $wpdb;

        Rifnote_Search_Publishers::maybe_install();

        $publisher = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Rifnote_Search_Publishers::publishers_table() . ' WHERE id = %d', (int) $publisher_id), ARRAY_A);

        if (!$publisher) {
            return new WP_Error('rifnote_publisher_missing', __('Publisher not found.', 'rifnote-search'), array('status' => 404));
        }

        $feed_url = esc_url_raw($publisher['rss_feed_url']);
        $diagnostics = array(
            'publisher_id' => (int) $publisher['id'],
            'publisher_name' => $publisher['publisher_name'],
            'feed_url' => $feed_url,
            'approval_status' => $publisher['approval_status'],
            'verification_status' => $publisher['verification_status'],
            'feed_status' => $publisher['feed_status'],
            'last_checked' => $publisher['feed_last_checked'],
            'last_error' => $publisher['feed_last_error'],
            'robots_allowed' => null,
            'http_status' => null,
            'valid_xml' => false,
            'item_count' => 0,
            'freshest_item' => '',
            'duplicate_count' => 0,
            'duplicate_rate' => 0,
            'preview_items' => array(),
            'recommendations' => array(),
        );

        if (!$feed_url) {
            $diagnostics['recommendations'][] = __('Add an RSS feed URL before approval.', 'rifnote-search');
            return $diagnostics;
        }

        if (Rifnote_Search_Legal::is_domain_blocked($feed_url)) {
            $diagnostics['recommendations'][] = __('This feed domain is blocked by legal tools.', 'rifnote-search');
        }

        $diagnostics['robots_allowed'] = Rifnote_Search_Legal::robots_allowed($feed_url);

        if (!$diagnostics['robots_allowed']) {
            $diagnostics['recommendations'][] = __('robots.txt blocks RifnoteBot for this feed.', 'rifnote-search');
            return $diagnostics;
        }

        $response = wp_remote_get($feed_url, array('timeout' => 12, 'redirection' => 3, 'user-agent' => 'RifnoteBot/1.0; +' . home_url('/')));

        if (is_wp_error($response)) {
            $diagnostics['recommendations'][] = $response->get_error_message();
            return $diagnostics;
        }

        $diagnostics['http_status'] = (int) wp_remote_retrieve_response_code($response);

        if ($diagnostics['http_status'] < 200 || $diagnostics['http_status'] >= 300) {
            $diagnostics['recommendations'][] = sprintf(__('Feed returned HTTP %d.', 'rifnote-search'), $diagnostics['http_status']);
            return $diagnostics;
        }

        $items = Rifnote_Search_Ingestion::parse_feed_xml((string) wp_remote_retrieve_body($response));

        if (is_wp_error($items)) {
            $diagnostics['recommendations'][] = $items->get_error_message();
            return $diagnostics;
        }

        $diagnostics['valid_xml'] = true;
        $diagnostics['item_count'] = count($items);
        $duplicates = 0;

        foreach (array_slice($items, 0, 12) as $item) {
            $duplicate = Rifnote_Search_Ingestion::already_exists($item['link'], $item['title']);
            $duplicates += $duplicate ? 1 : 0;
            $diagnostics['preview_items'][] = array(
                'title' => $item['title'],
                'url' => esc_url_raw($item['link']),
                'published_at' => $item['published_at'],
                'duplicate' => $duplicate,
            );
        }

        $diagnostics['duplicate_count'] = $duplicates;
        $diagnostics['duplicate_rate'] = $diagnostics['preview_items'] ? round(($duplicates / count($diagnostics['preview_items'])) * 100, 2) : 0;
        $dates = array_filter(wp_list_pluck($items, 'published_at'));
        rsort($dates);
        $diagnostics['freshest_item'] = $dates ? $dates[0] : '';

        if (!$diagnostics['item_count']) {
            $diagnostics['recommendations'][] = __('Feed is valid but contains no usable items.', 'rifnote-search');
        }

        if ($diagnostics['duplicate_rate'] >= 80) {
            $diagnostics['recommendations'][] = __('Most preview items are duplicates. Lower ingestion frequency or check canonical links.', 'rifnote-search');
        }

        if ('verified' !== $publisher['verification_status']) {
            $diagnostics['recommendations'][] = __('Verify publisher before RSS ingestion.', 'rifnote-search');
        }

        return $diagnostics;
    }

    public static function editorial_console() {
        return array(
            'rising_queries' => Rifnote_Search_Analytics::summary(1)['top_queries'],
            'no_result_queue' => Rifnote_Search_Platform_Insights::recent_no_results(8),
            'feed_errors' => array_filter(Rifnote_Search_Ingestion::feed_health_summary(20), function ($feed) {
                return !empty($feed['feed_last_error']) || in_array($feed['feed_status'], array('error', 'blocked', 'robots_blocked'), true);
            }),
            'legal_requests' => Rifnote_Search_Legal::recent_requests(8),
            'beta_feedback' => Rifnote_Search_Beta::recent_feedback(8),
            'index_health' => Rifnote_Search_Index::health(),
            'last_ingestion' => get_option('rifnote_search_ingestion_last_run', array()),
        );
    }

    public static function ranking_simulation($args, $limit = 10) {
        $args = wp_parse_args($args, array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'relevance'));
        $ids = array_slice(Rifnote_Search_Engine::candidate_ids($args), 0, max(1, min(30, (int) $limit)));

        return array_values(array_filter(array_map(function ($post_id) use ($args) {
            $post = get_post($post_id);

            if (!$post) {
                return null;
            }

            $result = Rifnote_Search_Engine::result_payload($post_id, $args);
            $breakdown = self::score_breakdown($post, $args, $result);
            $result['score_breakdown'] = $breakdown;

            return $result;
        }, $ids)));
    }

    public static function score_breakdown($post, $args, $result = array()) {
        $query = strtolower($args['query'] ?? '');
        $title = strtolower(get_the_title($post));
        $excerpt = strtolower(Rifnote_Search_Engine::plain_excerpt($post));
        $content = strtolower(wp_strip_all_tags($post->post_content));
        $text_relevance = 0.35;

        if ('' !== $query) {
            $text_relevance = 0;
            $text_relevance += false !== strpos($title, $query) ? 0.55 : 0;
            $text_relevance += false !== strpos($excerpt, $query) ? 0.25 : 0;
            $text_relevance += false !== strpos($content, $query) ? 0.2 : 0;
            $text_relevance = min(1, $text_relevance);
        }

        $published = get_post_time('U', true, $post);
        $age_days = max(0, (time() - $published) / DAY_IN_SECONDS);
        $freshness = $age_days <= 1 ? 1 : ($age_days <= 7 ? 0.8 : ($age_days <= 30 ? 0.55 : 0.25));
        $stored = get_post_meta($post->ID, 'freshness_score', true);

        if ('' !== $stored) {
            $freshness = Rifnote_Search_Engine::normalize_score($stored, $freshness);
        }

        $source_authority = Rifnote_Search_Engine::normalize_score(get_post_meta($post->ID, 'source_authority_score', true), 0.5);
        $ctr = Rifnote_Search_Engine::normalize_score(get_post_meta($post->ID, 'click_through_score', true), 0.5);
        $editor_boost = Rifnote_Search_Engine::normalize_score(get_post_meta($post->ID, 'editor_boost', true), 0);
        $category = Rifnote_Search_Engine::category_slug($args['category'] ?? '');
        $category_match = $category && has_category($category, $post) ? 1 : 0;
        $beta_adjustment = isset($result['beta_ranking_adjustment']) ? (float) $result['beta_ranking_adjustment'] : 0;

        return array(
            'text_relevance' => round($text_relevance, 4),
            'freshness' => round($freshness, 4),
            'source_authority' => round($source_authority, 4),
            'click_through' => round($ctr, 4),
            'category_match' => round($category_match, 4),
            'editor_boost' => round($editor_boost, 4),
            'beta_adjustment' => round($beta_adjustment, 4),
            'weighted_total' => round((0.40 * $text_relevance) + (0.25 * $freshness) + (0.15 * $source_authority) + (0.10 * $ctr) + (0.05 * $category_match) + (0.05 * $editor_boost) + $beta_adjustment, 4),
        );
    }

    public static function daily_briefing($limit = 8) {
        $trending = Rifnote_Search_Trending::topics(12);
        $stories = Rifnote_Search_Engine::payload(array('query' => '', 'category' => '', 'date_range' => '24h', 'sort' => 'relevance'), 1, max(5, min(20, (int) $limit)));
        $clusters = array();

        foreach ($stories['results'] as $story) {
            $clusters[] = array(
                'headline' => $story['headline'],
                'summary' => $story['excerpt'],
                'source_name' => $story['source_name'],
                'story_url' => $story['story_url'],
                'read_full_story_url' => $story['read_full_story_url'],
                'score' => $story['score'],
            );
        }

        return array(
            'date' => gmdate('Y-m-d'),
            'title' => __('Rifnote Daily Drop', 'rifnote-search'),
            'intro' => __('The stories, searches and topics worth catching today.', 'rifnote-search'),
            'trending_topics' => array_slice($trending, 0, 8),
            'stories' => $clusters,
            'delivery_ready' => array('email' => true, 'push' => true, 'api' => true),
            'generated_at' => gmdate(DATE_ATOM),
        );
    }
}
