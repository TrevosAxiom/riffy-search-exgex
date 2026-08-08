<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Ingestion {
    const CRON_HOOK = 'rifnote_search_ingest_feeds';
    const SMART_STATUS_OPTION = 'rifnote_smart_rss_feed_status';
    const LOG_OPTION = 'rifnote_smart_rss_run_log';

    public static function schedule() {
        $schedule = get_option('rifnote_smart_rss_enabled', true) ? 'rifnote_every_five_minutes' : 'rifnote_every_fifteen_minutes';
        $current = wp_get_schedule(self::CRON_HOOK);

        if ($current && $current !== $schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        self::repair_schedule(false);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK);
        }
    }

    public static function repair_schedule($force = false) {
        $schedule = get_option('rifnote_smart_rss_enabled', true) ? 'rifnote_every_five_minutes' : 'rifnote_every_fifteen_minutes';

        if (wp_doing_cron()) {
            return false;
        }

        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!$next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK);
            return true;
        }

        $now = time();
        if ($next >= $now) {
            return false;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event($now + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK);
        self::append_log(array(
            'status' => 'repaired',
            'message' => sprintf(
                /* translators: %s: human-readable overdue duration. */
                __('RSS schedule was stale by %s, so Rifnote cleared and rescheduled only the RSS hook.', 'rifnote-search'),
                self::human_duration($now - (int) $next)
            ),
            'summary' => array(
                'previous_next_run' => gmdate(DATE_ATOM, (int) $next),
                'new_next_run' => gmdate(DATE_ATOM, $now + MINUTE_IN_SECONDS),
                'schedule' => $schedule,
            ),
        ));

        return true;
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function schedule_status() {
        $next = wp_next_scheduled(self::CRON_HOOK);
        $now = time();
        $overdue = $next && $next < $now;
        $overdue_seconds = $overdue ? $now - (int) $next : 0;

        return array(
            'next_run' => $next ? (int) $next : 0,
            'next_run_gmt' => $next ? gmdate(DATE_ATOM, (int) $next) : '',
            'next_run_local' => $next ? wp_date('Y-m-d H:i:s', (int) $next) : '',
            'schedule' => wp_get_schedule(self::CRON_HOOK) ?: '',
            'is_overdue' => (bool) $overdue,
            'overdue_seconds' => (int) $overdue_seconds,
            'overdue_label' => $overdue ? self::human_duration($overdue_seconds) : '',
            'is_locked' => (bool) get_transient('rifnote_rss_ingestion_lock'),
        );
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['rifnote_every_five_minutes'])) {
            $schedules['rifnote_every_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every 5 minutes', 'rifnote-search'),
            );
        }

        if (!isset($schedules['rifnote_every_fifteen_minutes'])) {
            $schedules['rifnote_every_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'rifnote-search'),
            );
        }

        return $schedules;
    }

    private static function human_duration($seconds) {
        $seconds = max(0, (int) $seconds);

        if ($seconds < MINUTE_IN_SECONDS) {
            return sprintf(_n('%s second', '%s seconds', $seconds, 'rifnote-search'), number_format_i18n($seconds));
        }

        $minutes = (int) floor($seconds / MINUTE_IN_SECONDS);
        if ($minutes < 60) {
            return sprintf(_n('%s minute', '%s minutes', $minutes, 'rifnote-search'), number_format_i18n($minutes));
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 48) {
            return sprintf(_n('%s hour', '%s hours', $hours, 'rifnote-search'), number_format_i18n($hours));
        }

        $days = (int) floor($hours / 24);
        return sprintf(_n('%s day', '%s days', $days, 'rifnote-search'), number_format_i18n($days));
    }

    public static function sanitize_smart_rss_list($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $clean = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || 0 === strpos($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $url_index = 0;

            foreach ($parts as $index => $part) {
                if (preg_match('#^https?://#i', $part)) {
                    $url_index = $index;
                    break;
                }
            }

            $url = esc_url_raw($parts[$url_index] ?? '');
            if (!$url || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $name = $url_index > 0 ? $parts[0] : '';
            $category = $parts[$url_index + 1] ?? ($parts[1] ?? '');
            $mode = sanitize_key($parts[$url_index + 2] ?? '');
            $clean[] = implode(' | ', array_filter(array(
                Rifnote_Search_Source_Meta::normalize_text($name),
                $url,
                Rifnote_Search_Source_Meta::normalize_text($category),
                in_array($mode, array('publish', 'review'), true) ? $mode : '',
            )));
        }

        return implode("\n", array_slice(array_unique($clean), 0, 2000));
    }

    public static function smart_feeds() {
        $lines = preg_split('/\r\n|\r|\n/', (string) get_option('rifnote_smart_rss_list', ''));
        $feeds = array();

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ('' === $line || 0 === strpos($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $url_index = 0;
            foreach ($parts as $part_index => $part) {
                if (preg_match('#^https?://#i', $part)) {
                    $url_index = $part_index;
                    break;
                }
            }

            $feed_url = esc_url_raw($parts[$url_index] ?? '');
            if (!$feed_url) {
                continue;
            }

            $name = $url_index > 0 ? $parts[0] : wp_parse_url($feed_url, PHP_URL_HOST);
            $category = Rifnote_Search_Aggregation::normalize_category($parts[$url_index + 1] ?? ($parts[1] ?? 'News'));
            $mode = sanitize_key($parts[$url_index + 2] ?? '');
            $auto_approve = $mode ? 'publish' === $mode : (bool) get_option('rifnote_smart_rss_auto_publish', true);
            $home = Rifnote_Search_Source_Meta::source_home_from_url($feed_url);
            $feeds[] = array(
                'id' => -abs(crc32($feed_url)),
                'publisher_name' => Rifnote_Search_Source_Meta::normalize_text($name ?: wp_parse_url($feed_url, PHP_URL_HOST)),
                'website_url' => $home ? $home : $feed_url,
                'contact_email' => get_option('admin_email'),
                'categories' => $category,
                'rss_feed_url' => $feed_url,
                'auto_approve' => $auto_approve,
                'smart_rss' => true,
                'smart_index' => $index,
            );
        }

        return $feeds;
    }

    public static function approved_publishers_with_feeds() {
        global $wpdb;

        Rifnote_Search_Publishers::maybe_install();

        $table = Rifnote_Search_Publishers::publishers_table();

        return $wpdb->get_results("SELECT * FROM {$table} WHERE approval_status = 'approved' AND verification_status = 'verified' AND rss_feed_url IS NOT NULL AND rss_feed_url <> '' ORDER BY publisher_name ASC", ARRAY_A);
    }

    public static function run_cron() {
        return self::run_once();
    }

    public static function run_once($limit = 0) {
        if (get_transient('rifnote_rss_ingestion_lock')) {
            $locked = array('locked' => true, 'ran_at' => gmdate(DATE_ATOM));
            self::append_log(array(
                'status' => 'locked',
                'message' => __('RSS run skipped because another run is still locked.', 'rifnote-search'),
                'summary' => $locked,
            ));
            return $locked;
        }

        set_transient('rifnote_rss_ingestion_lock', 1, 4 * MINUTE_IN_SECONDS);

        $limit = $limit ? absint($limit) : absint(get_option('rifnote_smart_rss_batch_size', 25));
        $limit = max(1, min(100, $limit));
        $feeds = self::queue_feeds();
        $publishers = self::next_feed_batch($feeds, $limit);
        $items_per_feed = max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10))));
        $summary = array(
            'checked' => 0,
            'created' => 0,
            'published' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'recovered' => 0,
            'total_feeds' => count($feeds),
            'batch_size' => $limit,
            'items_per_feed' => $items_per_feed,
            'expected_max_items' => count($publishers) * $items_per_feed,
            'expected_urls' => array_map(array(__CLASS__, 'feed_log_item'), $publishers),
            'cursor' => (int) get_option('rifnote_smart_rss_cursor', 0),
            'feeds' => array(),
            'ran_at' => gmdate(DATE_ATOM),
        );

        try {
            foreach ($publishers as $publisher) {
                $result = self::ingest_publisher($publisher);
                $summary['checked']++;
                $summary['created'] += (int) $result['created'];
                $summary['published'] += (int) $result['published'];
                $summary['duplicates'] += (int) $result['duplicates'];
                $summary['recovered'] += (int) ($result['recovered'] ?? 0);
                $summary['errors'] += !empty($result['ok']) ? 0 : 1;
                $summary['feeds'][] = $result;
            }
        } catch (Throwable $error) {
            $summary['errors']++;
            $summary['fatal_error'] = $error->getMessage();
        } finally {
            update_option('rifnote_search_ingestion_last_run', $summary, false);
            self::append_log(array(
                'status' => !empty($summary['fatal_error']) ? 'error' : ($summary['errors'] ? 'warning' : 'ok'),
                'message' => !empty($summary['fatal_error'])
                    ? sprintf(__('RSS run stopped unexpectedly: %s', 'rifnote-search'), $summary['fatal_error'])
                    : sprintf(
                        /* translators: 1: checked feeds, 2: created stories, 3: published stories. */
                        __('Checked %1$d feed(s), created %2$d, published %3$d.', 'rifnote-search'),
                        (int) $summary['checked'],
                        (int) $summary['created'],
                        (int) $summary['published']
                    ),
                'summary' => $summary,
            ));
            delete_transient('rifnote_rss_ingestion_lock');
        }

        return $summary;
    }

    public static function queue_preview($limit = 0) {
        self::repair_schedule(true);

        $limit = $limit ? absint($limit) : absint(get_option('rifnote_smart_rss_batch_size', 25));
        $limit = max(1, min(100, $limit));
        $feeds = self::queue_feeds();
        $total = count($feeds);
        $cursor = (int) get_option('rifnote_smart_rss_cursor', 0);
        $items_per_feed = max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10))));
        $batch = array();

        if ($total) {
            if ($cursor < 0 || $cursor >= $total) {
                $cursor = 0;
            }

            for ($i = 0; $i < min($limit, $total); $i++) {
                $batch[] = $feeds[($cursor + $i) % $total];
            }
        }

        return array(
            'schedule' => self::schedule_status(),
            'next_run' => wp_next_scheduled(self::CRON_HOOK),
            'next_run_gmt' => wp_next_scheduled(self::CRON_HOOK) ? gmdate(DATE_ATOM, (int) wp_next_scheduled(self::CRON_HOOK)) : '',
            'total_feeds' => $total,
            'cursor' => $cursor,
            'batch_size' => $limit,
            'items_per_feed' => $items_per_feed,
            'expected_max_items' => count($batch) * $items_per_feed,
            'feeds' => array_map(array(__CLASS__, 'feed_log_item'), $batch),
        );
    }

    private static function queue_feeds() {
        $feeds = self::approved_publishers_with_feeds();

        if (get_option('rifnote_smart_rss_enabled', true)) {
            $feeds = array_merge($feeds, self::smart_feeds());
        }

        return array_values($feeds);
    }

    private static function next_feed_batch($feeds, $limit) {
        $total = count($feeds);
        if (!$total) {
            update_option('rifnote_smart_rss_cursor', 0, false);
            return array();
        }

        $cursor = (int) get_option('rifnote_smart_rss_cursor', 0);
        if ($cursor < 0 || $cursor >= $total) {
            $cursor = 0;
        }

        $batch = array();
        for ($i = 0; $i < min($limit, $total); $i++) {
            $batch[] = $feeds[($cursor + $i) % $total];
        }

        update_option('rifnote_smart_rss_cursor', ($cursor + count($batch)) % $total, false);

        return $batch;
    }

    private static function feed_log_item($publisher) {
        return array(
            'name' => Rifnote_Search_Source_Meta::normalize_text($publisher['publisher_name'] ?? ''),
            'feed_url' => esc_url_raw($publisher['rss_feed_url'] ?? ''),
            'category' => Rifnote_Search_Source_Meta::normalize_text($publisher['categories'] ?? ''),
            'mode' => !empty($publisher['auto_approve']) ? 'publish' : 'review',
        );
    }

    public static function ingest_publisher($publisher) {
        $feed_url = esc_url_raw((string) ($publisher['rss_feed_url'] ?? ''));
        $result = array(
            'publisher_id' => (int) $publisher['id'],
            'publisher_name' => (string) $publisher['publisher_name'],
            'feed_url' => $feed_url,
            'ok' => false,
            'created' => 0,
            'published' => 0,
            'duplicates' => 0,
            'recovered' => 0,
            'fetched_items' => 0,
            'expected_items' => max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10)))),
            'error' => '',
        );

        if (!$feed_url || !preg_match('#^https?://#', $feed_url)) {
            $result['error'] = __('Feed URL is missing or invalid.', 'rifnote-search');
            self::update_feed_health($publisher, 'error', $result['error'], 0);
            return $result;
        }

        if (Rifnote_Search_Legal::is_domain_blocked($feed_url) || Rifnote_Search_Legal::is_domain_blocked($publisher['website_url'] ?? '')) {
            $result['error'] = __('Publisher domain is blocked from Rifnote Search ingestion.', 'rifnote-search');
            self::update_feed_health($publisher, 'blocked', $result['error'], 0);
            return $result;
        }

        $robots_warning = '';
        if (!Rifnote_Search_Legal::robots_allowed($feed_url)) {
            $robots_warning = __('RSS robots.txt warning recorded; continuing because this is a syndication feed.', 'rifnote-search');
            $result['warning'] = $robots_warning;
        }

        $response = wp_remote_get($feed_url, array(
            'timeout' => max(3, min(20, absint(get_option('rifnote_smart_rss_timeout', 8)))),
            'redirection' => 3,
            'user-agent' => 'RifnoteBot/1.0; +' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            self::update_feed_health($publisher, 'error', $result['error'], 0);
            return $result;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            $result['error'] = sprintf(__('Feed returned HTTP %d.', 'rifnote-search'), $status);
            self::update_feed_health($publisher, 'error', $result['error'], 0);
            return $result;
        }

        $items = self::parse_feed_xml((string) wp_remote_retrieve_body($response));

        if (is_wp_error($items)) {
            $result['error'] = $items->get_error_message();
            self::update_feed_health($publisher, 'error', $result['error'], 0);
            return $result;
        }

        $items_per_feed = max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10))));
        $result['fetched_items'] = count($items);
        foreach (array_slice($items, 0, $items_per_feed) as $item) {
            $existing = self::existing_item($item['link'], $item['title']);

            if (!empty($existing['post_id'])) {
                $result['duplicates']++;
                continue;
            }

            if (!empty($existing['submission'])) {
                $recovered = self::recover_existing_submission($publisher, $existing['submission']);

                if (is_wp_error($recovered)) {
                    $result['error'] = $recovered->get_error_message();
                    continue;
                }

                $result['duplicates']++;

                if (!empty($recovered['published'])) {
                    $result['published']++;
                    $result['recovered']++;
                }

                continue;
            }

            $created = self::create_submission_from_feed_item($publisher, $item);

            if (is_wp_error($created)) {
                $result['error'] = $created->get_error_message();
                continue;
            }

            $result['created']++;

            if (!empty($created['published'])) {
                $result['published']++;
            }
        }

        $result['ok'] = true;
        self::update_feed_health($publisher, $robots_warning ? 'robots_warning' : 'ok', $robots_warning, $result['created']);

        return $result;
    }

    public static function parse_feed_xml($xml) {
        $xml = trim((string) $xml);

        if ('' === $xml) {
            return new WP_Error('rifnote_empty_feed', __('Feed body was empty.', 'rifnote-search'));
        }

        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$feed) {
            return new WP_Error('rifnote_invalid_feed', __('Feed XML could not be parsed.', 'rifnote-search'));
        }

        $entries = isset($feed->channel->item) ? $feed->channel->item : (isset($feed->entry) ? $feed->entry : array());
        $items = array();

        foreach ($entries as $entry) {
            $title = self::node_text($entry->title);
            $link = self::entry_link($entry);
            $excerpt = self::node_text($entry->description);

            if (!$excerpt && isset($entry->children('content', true)->encoded)) {
                $excerpt = self::node_text($entry->children('content', true)->encoded);
            }

            if (!$excerpt && isset($entry->summary)) {
                $excerpt = self::node_text($entry->summary);
            }

            if (!$title || !$link) {
                continue;
            }

            $date = self::node_text($entry->pubDate);

            if (!$date && isset($entry->updated)) {
                $date = self::node_text($entry->updated);
            }

            if (!$date && isset($entry->published)) {
                $date = self::node_text($entry->published);
            }

            $items[] = array(
                'title' => $title,
                'link' => esc_url_raw($link),
                'excerpt' => wp_trim_words(wp_strip_all_tags($excerpt), 45),
                'published_at' => $date ? gmdate('Y-m-d H:i:s', strtotime($date)) : current_time('mysql', true),
                'image_url' => self::entry_image($entry),
            );
        }

        return $items;
    }

    private static function node_text($node) {
        return html_entity_decode(trim(wp_strip_all_tags((string) $node)), ENT_QUOTES, get_bloginfo('charset'));
    }

    private static function entry_link($entry) {
        if (isset($entry->link)) {
            foreach ($entry->link as $link) {
                $attributes = $link->attributes();

                if (isset($attributes['href'])) {
                    return (string) $attributes['href'];
                }

                $value = trim((string) $link);

                if ($value) {
                    return $value;
                }
            }
        }

        if (isset($entry->guid)) {
            $guid = trim((string) $entry->guid);
            return preg_match('#^https?://#', $guid) ? $guid : '';
        }

        return '';
    }

    private static function entry_image($entry) {
        if (isset($entry->enclosure)) {
            foreach ($entry->enclosure as $enclosure) {
                $attributes = $enclosure->attributes();
                $type = isset($attributes['type']) ? (string) $attributes['type'] : '';

                if (isset($attributes['url']) && (!$type || 0 === strpos($type, 'image/'))) {
                    return esc_url_raw((string) $attributes['url']);
                }
            }
        }

        $media = $entry->children('media', true);

        foreach (array('content', 'thumbnail') as $node) {
            if (isset($media->{$node})) {
                foreach ($media->{$node} as $asset) {
                    $attributes = $asset->attributes();

                    if (isset($attributes['url'])) {
                        return esc_url_raw((string) $attributes['url']);
                    }
                }
            }
        }

        return '';
    }

    public static function already_exists($url, $headline = '') {
        global $wpdb;

        $url = esc_url_raw((string) $url);
        $normalized = Rifnote_Search_Source_Meta::normalize_headline($headline);

        if ($url) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'original_url' AND meta_value = %s LIMIT 1",
                $url
            ));

            if ($post_id) {
                return true;
            }

            $submission_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . Rifnote_Search_Publishers::submissions_table() . " WHERE original_url = %s LIMIT 1",
                $url
            ));

            if ($submission_id) {
                return true;
            }
        }

        if ($normalized) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'normalized_headline' AND meta_value = %s LIMIT 1",
                $normalized
            ));

            if ($post_id) {
                return true;
            }
        }

        return false;
    }

    private static function existing_item($url, $headline = '') {
        global $wpdb;

        $url = esc_url_raw((string) $url);
        $normalized = Rifnote_Search_Source_Meta::normalize_headline($headline);
        $result = array(
            'post_id' => 0,
            'submission' => null,
        );

        if ($url) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'original_url' AND meta_value = %s LIMIT 1",
                $url
            ));

            if ($post_id) {
                $result['post_id'] = (int) $post_id;
                return $result;
            }

            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . Rifnote_Search_Publishers::submissions_table() . " WHERE original_url = %s ORDER BY id DESC LIMIT 1",
                $url
            ), ARRAY_A);

            if ($submission) {
                $result['submission'] = $submission;
                return $result;
            }
        }

        if ($normalized) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'normalized_headline' AND meta_value = %s LIMIT 1",
                $normalized
            ));

            if ($post_id) {
                $result['post_id'] = (int) $post_id;
            }
        }

        return $result;
    }

    private static function recover_existing_submission($publisher, $submission) {
        if (empty($publisher['auto_approve'])) {
            return array('published' => false);
        }

        if (!empty($submission['wp_post_id']) && 'publish' === get_post_status((int) $submission['wp_post_id'])) {
            return array('published' => false);
        }

        $post_id = Rifnote_Search_Publishers::approve_submission((int) $submission['id'], 'publish', 'rss');

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
        Rifnote_Search_Index::index_post($post_id);

        return array(
            'published' => true,
            'post_id' => (int) $post_id,
        );
    }

    public static function create_submission_from_feed_item($publisher, $item) {
        global $wpdb;

        $headline = Rifnote_Search_Source_Meta::normalize_text($item['title'] ?? '');
        $original_url = Rifnote_Search_Publishers::sanitize_url_required($item['link'] ?? '');

        if (!$headline || !$original_url) {
            return new WP_Error('rifnote_invalid_feed_item', __('Feed item is missing a title or URL.', 'rifnote-search'));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($original_url)) {
            return new WP_Error('rifnote_blocked_feed_item', __('Feed item domain is blocked from Rifnote Search ingestion.', 'rifnote-search'));
        }

        $now = current_time('mysql', true);
        $status = !empty($publisher['auto_approve']) ? 'approved' : 'pending';
        $category = Rifnote_Search_Aggregation::normalize_category($publisher['categories'] ?? '', array(
            $headline,
            $item['excerpt'] ?? '',
            $publisher['publisher_name'] ?? '',
        ));

        $inserted = $wpdb->insert(Rifnote_Search_Publishers::submissions_table(), array(
            'publisher_id' => (int) $publisher['id'],
            'publisher_name' => Rifnote_Search_Source_Meta::normalize_text($publisher['publisher_name']),
            'website_url' => Rifnote_Search_Publishers::sanitize_url_required($publisher['website_url']),
            'contact_email' => sanitize_email($publisher['contact_email']),
            'headline' => $headline,
            'original_url' => $original_url,
            'excerpt' => Rifnote_Search_Source_Meta::normalize_text($item['excerpt'] ?? '', true),
            'category' => $category,
            'tags' => '',
            'image_url' => esc_url_raw($item['image_url'] ?? ''),
            'author_name' => '',
            'published_at' => !empty($item['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($item['published_at'])) : $now,
            'permission_confirmed' => 1,
            'rights_confirmed' => 1,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        if (!$inserted) {
            return new WP_Error('rifnote_feed_insert_failed', __('Feed item could not be saved.', 'rifnote-search'));
        }

        $submission_id = (int) $wpdb->insert_id;
        $published = false;

        if (!empty($publisher['auto_approve'])) {
            $post_id = Rifnote_Search_Publishers::approve_submission($submission_id, 'publish', 'rss');

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), false);
            $published = true;
        }

        return array(
            'submission_id' => $submission_id,
            'published' => $published,
        );
    }

    public static function recent_logs($limit = 12) {
        $logs = get_option(self::LOG_OPTION, array());
        $logs = is_array($logs) ? $logs : array();

        return array_slice($logs, 0, max(1, min(50, absint($limit))));
    }

    private static function append_log($entry) {
        $logs = get_option(self::LOG_OPTION, array());
        $logs = is_array($logs) ? $logs : array();
        $entry = array(
            'created_at' => current_time('mysql', true),
            'status' => sanitize_key($entry['status'] ?? 'info'),
            'message' => sanitize_text_field($entry['message'] ?? ''),
            'summary' => is_array($entry['summary'] ?? null) ? $entry['summary'] : array(),
        );

        array_unshift($logs, $entry);
        update_option(self::LOG_OPTION, array_slice($logs, 0, 50), false);
    }

    private static function update_feed_health($publisher, $status, $error, $created) {
        global $wpdb;
        $publisher_id = is_array($publisher) ? (int) ($publisher['id'] ?? 0) : (int) $publisher;

        if ($error) {
            Rifnote_Search_Hardening::log_error('rss_ingestion', $error, array(
                'publisher_id' => (int) $publisher_id,
                'feed_status' => sanitize_key($status),
            ), 'warning');
        }

        if (is_array($publisher) && !empty($publisher['smart_rss'])) {
            $statuses = get_option(self::SMART_STATUS_OPTION, array());
            $statuses = is_array($statuses) ? $statuses : array();
            $key = esc_url_raw($publisher['rss_feed_url'] ?? '');
            $statuses[$key] = array(
                'name' => Rifnote_Search_Source_Meta::normalize_text($publisher['publisher_name'] ?? ''),
                'status' => sanitize_key($status),
                'last_checked' => current_time('mysql', true),
                'last_error' => $error ? sanitize_textarea_field($error) : '',
                'items_indexed' => (int) $created,
            );
            update_option(self::SMART_STATUS_OPTION, array_slice($statuses, -250, null, true), false);
            return;
        }

        $wpdb->update(Rifnote_Search_Publishers::publishers_table(), array(
            'feed_status' => sanitize_key($status),
            'feed_last_checked' => current_time('mysql', true),
            'feed_last_error' => $error ? sanitize_textarea_field($error) : '',
            'feed_items_indexed' => (int) $created,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $publisher_id));
    }

    public static function smart_statuses($limit = 20) {
        $statuses = get_option(self::SMART_STATUS_OPTION, array());
        $statuses = is_array($statuses) ? array_reverse($statuses, true) : array();
        return array_slice($statuses, 0, max(1, min(100, (int) $limit)), true);
    }

    public static function feed_health_summary($limit = 30) {
        global $wpdb;

        Rifnote_Search_Publishers::maybe_install();

        $table = Rifnote_Search_Publishers::publishers_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rss_feed_url IS NOT NULL AND rss_feed_url <> '' ORDER BY feed_last_checked DESC, updated_at DESC LIMIT %d",
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }
}
