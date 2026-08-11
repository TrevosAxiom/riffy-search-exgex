<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Ingestion {
    const CRON_HOOK = 'rifnote_search_ingest_feeds';
    const SMART_STATUS_OPTION = 'rifnote_smart_rss_feed_status';
    const LOG_OPTION = 'rifnote_smart_rss_run_log';
    const LOCAL_CLEANUP_OPTION = 'rifnote_smart_rss_local_cleanup_at';

    public static function schedule() {
        if (!get_option('rifnote_smart_rss_enabled', true)) {
            self::clear_schedule();
            return;
        }

        if (self::warehouse_worker_enabled()) {
            self::clear_schedule();
            return;
        }

        $schedule = self::schedule_slug();
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
        if (!get_option('rifnote_smart_rss_enabled', true)) {
            self::clear_schedule();
            return false;
        }

        if (self::warehouse_worker_enabled()) {
            self::clear_schedule();
            return false;
        }

        $schedule = self::schedule_slug();

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

        $grace = $force ? 0 : max(2 * MINUTE_IN_SECONDS, self::interval_seconds());
        if (($now - (int) $next) < $grace) {
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

    public static function maybe_reschedule_on_settings_update($option, $old_value, $value) {
        $watched = array(
            'rifnote_smart_rss_enabled',
            'rifnote_smart_rss_interval_minutes',
            'rifnote_smart_rss_storage_mode',
            'rifnote_rss_warehouse_worker_enabled',
        );

        if (!in_array($option, $watched, true)) {
            return;
        }

        self::clear_schedule();
        self::schedule();
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
            'interval_minutes' => self::interval_minutes(),
            'is_overdue' => (bool) $overdue,
            'overdue_seconds' => (int) $overdue_seconds,
            'overdue_label' => $overdue ? self::human_duration($overdue_seconds) : '',
            'is_locked' => (bool) get_transient('rifnote_rss_ingestion_lock'),
            'warehouse_worker_enabled' => self::warehouse_worker_enabled(),
        );
    }

    public static function cron_schedules($schedules) {
        $minutes = self::interval_minutes();
        $slug = self::schedule_slug($minutes);

        $schedules[$slug] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => sprintf(
                /* translators: %s: number of minutes. */
                _n('Every %s minute', 'Every %s minutes', $minutes, 'rifnote-search'),
                number_format_i18n($minutes)
            ),
        );

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

    public static function interval_minutes() {
        return max(1, min(1440, absint(get_option('rifnote_smart_rss_interval_minutes', 5))));
    }

    public static function interval_seconds() {
        return self::interval_minutes() * MINUTE_IN_SECONDS;
    }

    public static function storage_mode() {
        $mode = sanitize_key((string) get_option('rifnote_smart_rss_storage_mode', 'warehouse'));

        return in_array($mode, array('warehouse', 'hybrid', 'wordpress'), true) ? $mode : 'warehouse';
    }

    public static function warehouse_worker_enabled() {
        return 'warehouse' === self::storage_mode() && (bool) get_option('rifnote_rss_warehouse_worker_enabled', true);
    }

    public static function local_retention_days() {
        return max(1, min(365, absint(get_option('rifnote_smart_rss_local_retention_days', 30))));
    }

    public static function schedule_slug($minutes = null) {
        $minutes = null === $minutes ? self::interval_minutes() : max(1, min(1440, absint($minutes)));
        return 'rifnote_rss_every_' . $minutes . '_minutes';
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
        if (!get_option('rifnote_smart_rss_enabled', true)) {
            return array('skipped' => true, 'reason' => 'disabled');
        }

        if (self::warehouse_worker_enabled()) {
            self::clear_schedule();
            return array('skipped' => true, 'reason' => 'warehouse_worker');
        }

        $summary = self::run_once();
        self::maybe_cleanup_local_rss_posts();

        return $summary;
    }

    public static function run_once($limit = 0, $force = false) {
        $storage_mode = self::storage_mode();

        if ('warehouse' === $storage_mode) {
            return self::run_warehouse_once($limit, $force);
        }

        if ($force) {
            delete_transient('rifnote_rss_ingestion_lock');
        }

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
            'forced' => (bool) $force,
            'interval_minutes' => self::interval_minutes(),
            'storage' => $storage_mode,
        );

        if (empty($feeds)) {
            $summary['empty_queue'] = true;
            $summary['empty_queue_message'] = __('No RSS feeds are configured. Add Smart RSS feed URLs or verified publisher RSS feeds before running ingestion.', 'rifnote-search');
        }

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
            update_option('rifnote_smart_rss_last_run_at', time(), false);
            update_option('rifnote_smart_rss_next_due_at', time() + self::interval_seconds(), false);
            self::append_log(array(
                'status' => !empty($summary['fatal_error']) ? 'error' : ($summary['errors'] ? 'warning' : 'ok'),
                'message' => !empty($summary['fatal_error'])
                    ? sprintf(__('RSS run stopped unexpectedly: %s', 'rifnote-search'), $summary['fatal_error'])
                    : (!empty($summary['empty_queue']) ? $summary['empty_queue_message'] : sprintf(
                        /* translators: 1: checked feeds, 2: created stories, 3: published stories. */
                        __('Checked %1$d feed(s), created %2$d, published %3$d.', 'rifnote-search'),
                        (int) $summary['checked'],
                        (int) $summary['created'],
                        (int) $summary['published']
                    )),
                'summary' => $summary,
            ));
            delete_transient('rifnote_rss_ingestion_lock');
        }

        if ('hybrid' === $storage_mode && class_exists('Rifnote_Search_Data_API') && Rifnote_Search_Data_API::enabled()) {
            $summary['warehouse'] = self::push_current_batch_to_warehouse($publishers);
            update_option('rifnote_data_api_last_rss_ingest', $summary['warehouse'], false);
            update_option('rifnote_search_ingestion_last_run', $summary, false);
        }

        return $summary;
    }

    public static function run_warehouse_once($limit = 0, $force = false) {
        if (self::warehouse_worker_enabled()) {
            $summary = array(
                'ok' => true,
                'skipped' => true,
                'reason' => 'warehouse_worker',
                'storage' => 'warehouse',
                'checked' => 0,
                'created' => 0,
                'published' => 0,
                'duplicates' => 0,
                'errors' => 0,
                'ran_at' => gmdate(DATE_ATOM),
                'message' => __('RSS ingestion is owned by the VPS warehouse worker. WordPress is not fetching feeds.', 'rifnote-search'),
            );
            update_option('rifnote_search_ingestion_last_run', $summary, false);
            self::append_log(array(
                'status' => 'worker',
                'message' => $summary['message'],
                'summary' => $summary,
            ));
            self::clear_schedule();

            return $summary;
        }

        if ($force) {
            delete_transient('rifnote_rss_ingestion_lock');
        }

        if (get_transient('rifnote_rss_ingestion_lock')) {
            $locked = array('locked' => true, 'storage' => 'warehouse', 'ran_at' => gmdate(DATE_ATOM));
            self::append_log(array(
                'status' => 'locked',
                'message' => __('RSS warehouse run skipped because another run is still locked.', 'rifnote-search'),
                'summary' => $locked,
            ));
            return $locked;
        }

        set_transient('rifnote_rss_ingestion_lock', 1, 4 * MINUTE_IN_SECONDS);

        $limit = $limit ? absint($limit) : absint(get_option('rifnote_smart_rss_batch_size', 25));
        $limit = max(1, min(100, $limit));
        $feeds = self::queue_feeds();
        $publishers = self::next_feed_batch($feeds, $limit);
        $warehouse_feeds = self::prepare_data_api_feeds($publishers);
        $summary = array(
            'checked' => 0,
            'created' => 0,
            'published' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'recovered' => 0,
            'storage' => 'warehouse',
            'total_feeds' => count($feeds),
            'batch_size' => $limit,
            'items_per_feed' => max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10)))),
            'expected_max_items' => count($publishers) * max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10)))),
            'expected_urls' => array_map(array(__CLASS__, 'feed_log_item'), $publishers),
            'cursor' => (int) get_option('rifnote_smart_rss_cursor', 0),
            'feeds' => array_map(array(__CLASS__, 'feed_log_item'), $publishers),
            'ran_at' => gmdate(DATE_ATOM),
            'forced' => (bool) $force,
            'interval_minutes' => self::interval_minutes(),
        );

        if (empty($feeds)) {
            $summary['empty_queue'] = true;
            $summary['empty_queue_message'] = __('No RSS feeds are configured. Add Smart RSS feed URLs or verified publisher RSS feeds before running ingestion.', 'rifnote-search');
        }

        try {
            if (!class_exists('Rifnote_Search_Data_API')) {
                $warehouse = array('ok' => false, 'message' => __('Data API bridge is not loaded, so warehouse RSS cannot run.', 'rifnote-search'));
            } elseif (!Rifnote_Search_Data_API::enabled()) {
                $warehouse = array('ok' => false, 'message' => __('Data API bridge is disabled. Enable it before running warehouse RSS.', 'rifnote-search'));
            } elseif (empty($warehouse_feeds)) {
                $warehouse = array('ok' => false, 'message' => $summary['empty_queue_message'] ?? __('No valid RSS feeds were prepared for the Data API.', 'rifnote-search'));
            } else {
                $warehouse = Rifnote_Search_Data_API::ingest_rss_batch($warehouse_feeds);
            }

            $summary['warehouse'] = $warehouse;
            $summary['checked'] = (int) ($warehouse['checked'] ?? count($warehouse_feeds));
            $summary['created'] = (int) ($warehouse['inserted'] ?? $warehouse['created'] ?? 0);
            $summary['duplicates'] = (int) ($warehouse['duplicates'] ?? 0);
            $summary['errors'] = (int) ($warehouse['errors'] ?? (empty($warehouse['ok']) ? 1 : 0));
        } catch (Throwable $error) {
            $summary['errors']++;
            $summary['fatal_error'] = $error->getMessage();
            $summary['warehouse'] = array('ok' => false, 'message' => $error->getMessage());
        } finally {
            update_option('rifnote_data_api_last_rss_ingest', $summary['warehouse'] ?? array(), false);
            update_option('rifnote_search_ingestion_last_run', $summary, false);
            update_option('rifnote_smart_rss_last_run_at', time(), false);
            update_option('rifnote_smart_rss_next_due_at', time() + self::interval_seconds(), false);

            $warehouse = is_array($summary['warehouse'] ?? null) ? $summary['warehouse'] : array();
            self::append_log(array(
                'status' => !empty($summary['fatal_error']) ? 'error' : (!empty($warehouse['ok']) ? 'warehouse' : 'warning'),
                'message' => !empty($summary['fatal_error'])
                    ? sprintf(__('RSS warehouse run stopped unexpectedly: %s', 'rifnote-search'), $summary['fatal_error'])
                    : (!empty($warehouse['ok'])
                        ? sprintf(
                            __('Warehouse checked %1$d feed(s), inserted %2$d, skipped %3$d duplicate(s).', 'rifnote-search'),
                            (int) $summary['checked'],
                            (int) $summary['created'],
                            (int) $summary['duplicates']
                        )
                        : sprintf(__('Warehouse RSS push failed: %s', 'rifnote-search'), (string) ($warehouse['message'] ?? $warehouse['error'] ?? __('Unknown error', 'rifnote-search')))),
                'summary' => $summary,
            ));
            delete_transient('rifnote_rss_ingestion_lock');
        }

        return $summary;
    }

    private static function push_current_batch_to_warehouse($publishers) {
        if (!class_exists('Rifnote_Search_Data_API')) {
            return array('ok' => false, 'message' => __('Data API bridge is not loaded.', 'rifnote-search'));
        }

        if (!Rifnote_Search_Data_API::enabled()) {
            return array('ok' => false, 'message' => __('Data API bridge is disabled.', 'rifnote-search'));
        }

        $warehouse_feeds = self::prepare_data_api_feeds($publishers);
        if (!$warehouse_feeds) {
            return array('ok' => false, 'message' => __('No valid RSS feeds were prepared for the Data API.', 'rifnote-search'));
        }

        return Rifnote_Search_Data_API::ingest_rss_batch($warehouse_feeds);
    }

    public static function queue_preview($limit = 0) {
        $limit = $limit ? absint($limit) : absint(get_option('rifnote_smart_rss_batch_size', 25));
        $limit = max(1, min(100, $limit));
        $feeds = self::queue_feeds();
        $total = count($feeds);
        $cursor = (int) get_option('rifnote_smart_rss_cursor', 0);
        $items_per_feed = max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10))));
        $batch = self::preview_feed_batch($feeds, $limit);

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

    public static function data_api_feed_batch($limit = 0) {
        $limit = $limit ? absint($limit) : absint(get_option('rifnote_smart_rss_batch_size', 25));
        $limit = max(1, min(100, $limit));
        $feeds = self::preview_feed_batch(self::queue_feeds(), $limit);
        return self::prepare_data_api_feeds($feeds);
    }

    private static function prepare_data_api_feeds($feeds) {
        $items_per_feed = max(1, min(30, absint(get_option('rifnote_smart_rss_items_per_feed', 10))));
        $timeout = max(3, min(20, absint(get_option('rifnote_smart_rss_timeout', 8))));
        $payload = array();

        foreach ($feeds as $feed) {
            $feed_url = esc_url_raw($feed['rss_feed_url'] ?? '');
            if (!$feed_url) {
                continue;
            }

            $payload[] = array(
                'name' => Rifnote_Search_Source_Meta::normalize_text($feed['publisher_name'] ?? wp_parse_url($feed_url, PHP_URL_HOST)),
                'feed_url' => $feed_url,
                'source_url' => esc_url_raw($feed['website_url'] ?? Rifnote_Search_Source_Meta::source_home_from_url($feed_url)),
                'category' => Rifnote_Search_Source_Meta::normalize_text($feed['categories'] ?? 'News'),
                'items_per_feed' => $items_per_feed,
                'timeout' => $timeout,
                'language_code' => sanitize_key($feed['language_code'] ?? 'en'),
                'country_code' => strtoupper(sanitize_key($feed['country_code'] ?? '')),
            );
        }

        return $payload;
    }

    public static function maybe_cleanup_local_rss_posts($force = false) {
        $last = (int) get_option(self::LOCAL_CLEANUP_OPTION, 0);
        if (!$force && $last && (time() - $last) < DAY_IN_SECONDS) {
            return array('skipped' => true, 'reason' => 'recent_cleanup');
        }

        $summary = self::cleanup_local_rss_posts(self::local_retention_days());
        update_option(self::LOCAL_CLEANUP_OPTION, time(), false);

        if (empty($summary['skipped'])) {
            self::append_log(array(
                'status' => 'cleanup',
                'message' => sprintf(
                    __('Legacy WordPress RSS cleanup removed %d post(s) older than %d day(s).', 'rifnote-search'),
                    (int) ($summary['deleted'] ?? 0),
                    (int) ($summary['days'] ?? self::local_retention_days())
                ),
                'summary' => $summary,
            ));
        }

        return $summary;
    }

    public static function cleanup_local_rss_posts($days = 30, $limit = 250) {
        global $wpdb;

        $days = max(1, min(365, absint($days)));
        $limit = max(1, min(1000, absint($limit)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $markers = self::legacy_rss_post_marker_sql();

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'post'
               AND post_status IN ('publish','draft','pending','future','private','trash')
               AND post_date_gmt < %s
               AND ID IN ({$markers})
             ORDER BY post_date_gmt ASC
             LIMIT %d",
            $cutoff,
            $limit
        ));

        $deleted = 0;
        foreach ($post_ids as $post_id) {
            if (wp_delete_post((int) $post_id, true)) {
                $deleted++;
            }
        }

        return array(
            'ok' => true,
            'days' => $days,
            'cutoff_gmt' => $cutoff,
            'found' => count($post_ids),
            'deleted' => $deleted,
        );
    }

    public static function hide_legacy_rss_posts_from_admin($query) {
        if (!is_admin() || !$query->is_main_query() || !function_exists('get_current_screen')) {
            return;
        }

        global $pagenow;
        if ('edit.php' !== $pagenow || ('post' !== ($query->get('post_type') ?: 'post'))) {
            return;
        }

        if (!empty($_GET['rifnote_show_rss'])) {
            return;
        }

        $query->set('rifnote_hide_legacy_rss', 1);
    }

    public static function exclude_legacy_rss_posts_where($where, $query) {
        global $wpdb;

        if (!is_admin() || !$query->get('rifnote_hide_legacy_rss')) {
            return $where;
        }

        return $where . " AND {$wpdb->posts}.ID NOT IN (" . self::legacy_rss_post_marker_sql() . ")";
    }

    private static function legacy_rss_post_marker_sql() {
        global $wpdb;

        return $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE (meta_key = %s AND meta_value = %s)
                OR (meta_key = %s AND meta_value = %s)
                OR (meta_key = %s AND meta_value = %s)",
            'source_type',
            'rss',
            'rifnote_origin_channel',
            'rss',
            'rifnote_origin_model',
            'RSS Feed'
        );
    }

    private static function queue_feeds() {
        $feeds = self::approved_publishers_with_feeds();

        if (get_option('rifnote_smart_rss_enabled', true)) {
            $feeds = array_merge($feeds, self::smart_feeds());
        }

        return array_values($feeds);
    }

    private static function preview_feed_batch($feeds, $limit) {
        $total = count($feeds);
        if (!$total) {
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

        return $batch;
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

        return array_slice($logs, 0, max(1, min(200, absint($limit))));
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
        update_option(self::LOG_OPTION, array_slice($logs, 0, 250), false);
        self::persist_log_file($entry);
    }

    private static function persist_log_file($entry) {
        if (!defined('WP_CONTENT_DIR') || !function_exists('wp_mkdir_p')) {
            return;
        }

        $timestamp = !empty($entry['created_at']) ? strtotime($entry['created_at'] . ' UTC') : time();
        $timestamp = $timestamp ? $timestamp : time();
        $month = gmdate('Y-m', $timestamp);
        $day = gmdate('Y-m-d', $timestamp);
        $dir = trailingslashit(WP_CONTENT_DIR) . 'rifnote-rss-warehouse/' . $month;

        if (!wp_mkdir_p($dir) || !is_writable($dir)) {
            return;
        }

        $file = trailingslashit($dir) . 'rss-' . $day . '.json';
        $entries = array();

        if (file_exists($file) && is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $entries = is_array($decoded) ? $decoded : array();
        }

        $entries[] = $entry;
        $entries = array_slice($entries, -1000);
        file_put_contents($file, wp_json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
