<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_News_API {
    const ENDPOINT = 'https://api.thenewsapi.com/v1/news/top';
    const CRON_HOOK = 'rifnote_search_thenewsapi_import';

    public static function settings() {
        return array(
            'enabled' => (bool) get_option('rifnote_thenewsapi_enabled', false),
            'api_key' => (string) get_option('rifnote_thenewsapi_key', ''),
            'locale' => sanitize_text_field((string) get_option('rifnote_thenewsapi_locale', 'ng,us,gb')),
            'language' => sanitize_text_field((string) get_option('rifnote_thenewsapi_language', 'en')),
            'categories' => sanitize_text_field((string) get_option('rifnote_thenewsapi_categories', 'general,business,tech,politics,sports')),
            'limit' => max(1, min(25, absint(get_option('rifnote_thenewsapi_limit', 10)))),
            'auto_publish' => (bool) get_option('rifnote_thenewsapi_auto_publish', true),
            'interval' => self::sanitize_interval(get_option('rifnote_thenewsapi_interval', 'rifnote_every_six_hours')),
        );
    }

    public static function configured() {
        $settings = self::settings();

        return $settings['enabled'] && !empty($settings['api_key']);
    }

    public static function interval_options() {
        return array(
            'rifnote_every_fifteen_minutes' => __('Every 15 minutes', 'rifnote-search'),
            'rifnote_every_thirty_minutes' => __('Every 30 minutes', 'rifnote-search'),
            'hourly' => __('Every hour', 'rifnote-search'),
            'rifnote_every_three_hours' => __('Every 3 hours', 'rifnote-search'),
            'rifnote_every_six_hours' => __('Every 6 hours', 'rifnote-search'),
            'twicedaily' => __('Twice daily', 'rifnote-search'),
            'daily' => __('Daily', 'rifnote-search'),
        );
    }

    public static function sanitize_interval($interval) {
        $interval = sanitize_key((string) $interval);
        $options = self::interval_options();

        return isset($options[$interval]) ? $interval : 'rifnote_every_six_hours';
    }

    public static function cron_schedules($schedules) {
        $custom = array(
            'rifnote_every_fifteen_minutes' => array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'rifnote-search'),
            ),
            'rifnote_every_thirty_minutes' => array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display' => __('Every 30 minutes', 'rifnote-search'),
            ),
            'rifnote_every_three_hours' => array(
                'interval' => 3 * HOUR_IN_SECONDS,
                'display' => __('Every 3 hours', 'rifnote-search'),
            ),
            'rifnote_every_six_hours' => array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 hours', 'rifnote-search'),
            ),
        );

        foreach ($custom as $key => $schedule) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $schedule;
            }
        }

        return $schedules;
    }

    public static function schedule() {
        $settings = self::settings();
        $next = wp_next_scheduled(self::CRON_HOOK);
        $current_schedule = wp_get_schedule(self::CRON_HOOK);

        if (!self::configured()) {
            if ($next) {
                self::clear_schedule();
            }

            return;
        }

        if ($next && $current_schedule !== $settings['interval']) {
            self::clear_schedule();
            $next = false;
        }

        if (!$next) {
            wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, $settings['interval'], self::CRON_HOOK);
        }
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function run_cron() {
        return self::import_top_stories(false);
    }

    public static function import_top_stories($force = false) {
        $settings = self::settings();
        $summary = array(
            'provider' => 'TheNewsAPI',
            'configured' => self::configured(),
            'checked' => 0,
            'created' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'message' => '',
            'ran_at' => gmdate(DATE_ATOM),
        );

        if (!$summary['configured']) {
            $summary['message'] = __('Enable TheNewsAPI and add an API token first.', 'rifnote-search');
            update_option('rifnote_thenewsapi_last_run', $summary, false);
            return $summary;
        }

        $cache_key = 'rifnote_thenewsapi_last_fetch';

        if (!$force && get_transient($cache_key)) {
            $summary['message'] = __('TheNewsAPI import was skipped because the free-plan cache window is still active.', 'rifnote-search');
            update_option('rifnote_thenewsapi_last_run', $summary, false);
            return $summary;
        }

        $url = add_query_arg(array_filter(array(
            'api_token' => $settings['api_key'],
            'locale' => $settings['locale'],
            'language' => $settings['language'],
            'categories' => $settings['categories'],
            'limit' => $settings['limit'],
            'page' => 1,
            'sort' => 'published_at',
        )), self::ENDPOINT);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'redirection' => 2,
            'user-agent' => 'RifnoteBot/1.0; +' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            $summary['errors']++;
            $summary['message'] = $response->get_error_message();
            update_option('rifnote_thenewsapi_last_run', $summary, false);
            return $summary;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($body)) {
            $summary['errors']++;
            $summary['message'] = sprintf(__('TheNewsAPI returned HTTP %d.', 'rifnote-search'), $status);
            update_option('rifnote_thenewsapi_last_run', $summary, false);
            return $summary;
        }

        if (!empty($body['error']['message'])) {
            $summary['errors']++;
            $summary['message'] = sanitize_text_field($body['error']['message']);
            update_option('rifnote_thenewsapi_last_run', $summary, false);
            return $summary;
        }

        $articles = !empty($body['data']) && is_array($body['data']) ? $body['data'] : array();

        foreach ($articles as $article) {
            $summary['checked']++;

            if (self::article_exists($article)) {
                $summary['duplicates']++;
                continue;
            }

            $created = self::create_post_from_article($article, $settings);

            if (is_wp_error($created)) {
                $summary['errors']++;
                $summary['message'] = $created->get_error_message();
                continue;
            }

            $summary['created']++;
        }

        $summary['message'] = sprintf(__('Imported %1$d stories from %2$d TheNewsAPI articles.', 'rifnote-search'), (int) $summary['created'], (int) $summary['checked']);
        set_transient($cache_key, 1, 15 * MINUTE_IN_SECONDS);
        update_option('rifnote_thenewsapi_last_run', $summary, false);

        return $summary;
    }

    private static function article_exists($article) {
        $url = isset($article['url']) ? esc_url_raw($article['url']) : '';
        $title = isset($article['title']) ? Rifnote_Search_Source_Meta::normalize_text($article['title']) : '';
        $uuid = isset($article['uuid']) ? sanitize_text_field($article['uuid']) : '';

        if ($uuid && get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_key' => 'thenewsapi_uuid',
            'meta_value' => $uuid,
        ))) {
            return true;
        }

        return Rifnote_Search_Ingestion::already_exists($url, $title);
    }

    private static function create_post_from_article($article, $settings) {
        $title = Rifnote_Search_Source_Meta::normalize_text($article['title'] ?? '');
        $url = esc_url_raw($article['url'] ?? '');
        $excerpt = self::article_excerpt($article);

        if (!$title || !$url) {
            return new WP_Error('rifnote_thenewsapi_invalid_article', __('TheNewsAPI article is missing a title or URL.', 'rifnote-search'));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($url)) {
            return new WP_Error('rifnote_thenewsapi_blocked_domain', __('TheNewsAPI article domain is blocked.', 'rifnote-search'));
        }

        $published_at = !empty($article['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($article['published_at'])) : current_time('mysql', true);
        $post_id = wp_insert_post(array(
            'post_type' => 'post',
            'post_status' => $settings['auto_publish'] ? 'publish' : 'draft',
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $excerpt,
            'post_date_gmt' => $published_at,
            'post_date' => get_date_from_gmt($published_at),
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $source_domain = Rifnote_Search_Source_Meta::normalize_text($article['source'] ?? Rifnote_Search_Source_Meta::source_domain($url));
        $source_url = Rifnote_Search_Source_Meta::source_home_from_url($url);

        update_post_meta($post_id, 'source_name', $source_domain ? $source_domain : __('TheNewsAPI Source', 'rifnote-search'));
        update_post_meta($post_id, 'source_url', $source_url);
        update_post_meta($post_id, 'original_url', $url);
        update_post_meta($post_id, 'read_full_story_url', $url);
        update_post_meta($post_id, 'canonical_url', $url);
        update_post_meta($post_id, 'source_type', 'external');
            update_post_meta($post_id, 'thenewsapi_uuid', Rifnote_Search_Source_Meta::normalize_text($article['uuid'] ?? ''));
        update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline($title));
        update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags($excerpt ? $excerpt : $title)));
        update_post_meta($post_id, 'freshness_score', 1);
        update_post_meta($post_id, 'source_authority_score', 0.62);
        Rifnote_Search_Source_Meta::stamp_origin($post_id, 'TheNewsAPI', 'TheNewsAPI', 'news_api');

        if (!empty($article['image_url'])) {
            update_post_meta($post_id, 'rifnote_source_image_url', esc_url_raw($article['image_url']));
        }

        $category = self::article_category($article);
        Rifnote_Search_Aggregation::assign_category($post_id, $category, array(
            $title,
            $excerpt,
            $source_domain,
            !empty($article['keywords']) ? Rifnote_Search_Source_Meta::normalize_text($article['keywords']) : '',
        ));

        if (!empty($article['keywords'])) {
            wp_set_post_terms($post_id, array_map('trim', explode(',', Rifnote_Search_Source_Meta::normalize_text($article['keywords']))), 'post_tag', false);
        }

        if ('publish' === get_post_status($post_id)) {
            Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
            Rifnote_Search_Index::index_post($post_id);
        }

        return $post_id;
    }

    private static function article_excerpt($article) {
        $excerpt = '';

        foreach (array('description', 'snippet') as $field) {
            if (!empty($article[$field])) {
                $excerpt = (string) $article[$field];
                break;
            }
        }

        return wp_trim_words(wp_strip_all_tags(Rifnote_Search_Source_Meta::normalize_text($excerpt, true)), 42);
    }

    private static function article_category($article) {
        $categories = !empty($article['categories']) && is_array($article['categories']) ? $article['categories'] : array('News');
        $first = sanitize_title((string) reset($categories));

        $map = array(
            'sports' => 'Football',
            'tech' => 'Tech',
            'business' => 'Business',
            'politics' => 'Politics',
            'entertainment' => 'Entertainment',
            'general' => 'News',
            'science' => 'Tech',
            'health' => 'Health',
            'food' => 'Lifestyle',
            'travel' => 'Travel',
        );

        return Rifnote_Search_Aggregation::normalize_category($map[$first] ?? ucwords(str_replace('-', ' ', $first)), array(
            $article['title'] ?? '',
            $article['description'] ?? '',
            $article['snippet'] ?? '',
            !empty($article['keywords']) ? $article['keywords'] : '',
        ));
    }
}
