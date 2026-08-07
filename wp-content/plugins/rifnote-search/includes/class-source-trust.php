<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Source_Trust {
    public static function source_payload($domain) {
        global $wpdb;

        $domain = Rifnote_Search_Legal::normalize_domain($domain);

        if (!$domain) {
            return new WP_Error('rifnote_source_missing', __('Source domain is missing.', 'rifnote-search'), array('status' => 404));
        }

        Rifnote_Search_Publishers::maybe_install();

        $publisher = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Rifnote_Search_Publishers::publishers_table() . ' WHERE website_url LIKE %s OR rss_feed_url LIKE %s ORDER BY updated_at DESC LIMIT 1',
            '%' . $wpdb->esc_like($domain) . '%',
            '%' . $wpdb->esc_like($domain) . '%'
        ), ARRAY_A);

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'source_url', 'value' => $domain, 'compare' => 'LIKE'),
                array('key' => 'original_url', 'value' => $domain, 'compare' => 'LIKE'),
                array('key' => 'read_full_story_url', 'value' => $domain, 'compare' => 'LIKE'),
            ),
        ));

        $source_name = $publisher ? $publisher['publisher_name'] : $domain;
        $authority = $publisher ? (float) $publisher['source_authority_score'] : 50.0;
        $blocked = Rifnote_Search_Legal::is_domain_blocked($domain);
        $website_url = $publisher ? esc_url_raw($publisher['website_url']) : 'https://' . $domain . '/';

        return array(
            'domain' => $domain,
            'source_name' => $source_name,
            'source_domain' => $domain,
            'source_url' => $website_url,
            'source_logo_url' => Rifnote_Search_Source_Meta::source_logo_url(0, $website_url, $website_url),
            'source_initials' => Rifnote_Search_Source_Meta::source_initials($source_name, $domain),
            'website_url' => $website_url,
            'publisher' => $publisher ? array(
                'id' => (int) $publisher['id'],
                'approval_status' => $publisher['approval_status'],
                'verification_status' => $publisher['verification_status'],
                'feed_status' => $publisher['feed_status'],
                'feed_last_checked' => $publisher['feed_last_checked'],
                'feed_last_error' => $publisher['feed_last_error'],
                'auto_approve' => (bool) $publisher['auto_approve'],
            ) : null,
            'trust' => array(
                'source_authority_score' => $authority,
                'blocked' => $blocked,
                'verified' => $publisher && 'verified' === $publisher['verification_status'],
                'approved' => $publisher && 'approved' === $publisher['approval_status'],
                'recent_story_count' => count($posts),
            ),
            'stories' => array_map(function ($post) {
                return Rifnote_Search_Engine::result_payload($post->ID, array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'latest'));
            }, $posts),
        );
    }

    public static function why_this_result($result) {
        return array(
            'relevance' => __('Matched query text, metadata, source fields or category filters.', 'rifnote-search'),
            'freshness' => __('Recent publishing time improves ranking for news queries.', 'rifnote-search'),
            'source_authority' => sprintf(__('Source authority contributes to ranking. Current source score: %s.', 'rifnote-search'), isset($result['source_authority_score']) ? $result['source_authority_score'] : __('available in metadata', 'rifnote-search')),
            'engagement' => __('Click-through and publisher traffic signals can improve ranking over time.', 'rifnote-search'),
            'editorial' => !empty($result['beta_ranking_adjustment']) ? sprintf(__('Beta ranking adjustment applied: %s.', 'rifnote-search'), $result['beta_ranking_adjustment']) : __('No beta ranking adjustment applied.', 'rifnote-search'),
        );
    }
}
