<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_RSS_Warehouse {
    const MENU_SLUG = 'rifnote-rss-warehouse';

    public static function register_menu() {
        add_menu_page(
            __('Rifnote RSS', 'rifnote-search'),
            __('Rifnote RSS', 'rifnote-search'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_page'),
            'dashicons-rss',
            59
        );

        foreach (self::pages() as $slug => $page) {
            add_submenu_page(
                self::MENU_SLUG,
                $page['title'],
                $page['menu'],
                'manage_options',
                $slug,
                array(__CLASS__, 'render_page')
            );
        }
    }

    private static function pages() {
        return array(
            self::MENU_SLUG => array('title' => __('RSS Warehouse', 'rifnote-search'), 'menu' => __('Dashboard', 'rifnote-search'), 'section' => 'dashboard'),
            'rifnote-rss-feeds' => array('title' => __('RSS Feed Inventory', 'rifnote-search'), 'menu' => __('Feed Inventory', 'rifnote-search'), 'section' => 'feeds'),
            'rifnote-rss-queue' => array('title' => __('RSS Queue Planner', 'rifnote-search'), 'menu' => __('Queue Planner', 'rifnote-search'), 'section' => 'queue'),
            'rifnote-rss-logs' => array('title' => __('RSS Logs', 'rifnote-search'), 'menu' => __('Logs', 'rifnote-search'), 'section' => 'logs'),
            'rifnote-rss-health' => array('title' => __('RSS Health', 'rifnote-search'), 'menu' => __('Health', 'rifnote-search'), 'section' => 'health'),
            'rifnote-rss-settings' => array('title' => __('RSS Settings', 'rifnote-search'), 'menu' => __('Settings', 'rifnote-search'), 'section' => 'settings'),
        );
    }

    private static function current_section() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::MENU_SLUG;
        $pages = self::pages();

        return isset($pages[$page]) ? $pages[$page]['section'] : 'dashboard';
    }

    private static function tabs() {
        $current = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::MENU_SLUG;
        echo '<nav class="nav-tab-wrapper" style="margin:18px 0 22px;">';
        foreach (self::pages() as $slug => $page) {
            echo '<a class="nav-tab' . esc_attr($slug === $current ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($page['menu']) . '</a>';
        }
        echo '</nav>';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage RSS ingestion.', 'rifnote-search'));
        }

        self::handle_actions();
        Rifnote_Search_Ingestion::repair_schedule(true);

        $section = self::current_section();
        echo '<div class="wrap rifnote-rss-warehouse">';
        echo '<h1>' . esc_html__('Rifnote RSS Warehouse', 'rifnote-search') . '</h1>';
        echo '<p>' . esc_html__('A standalone control room for feed inventory, queue rotation, ingestion logs, source health and schedule repair.', 'rifnote-search') . '</p>';
        self::tabs();

        if ('feeds' === $section) {
            self::render_feeds();
        } elseif ('queue' === $section) {
            self::render_queue();
        } elseif ('logs' === $section) {
            self::render_logs();
        } elseif ('health' === $section) {
            self::render_health();
        } elseif ('settings' === $section) {
            self::render_settings();
        } else {
            self::render_dashboard();
        }

        echo '</div>';
    }

    private static function handle_actions() {
        if (empty($_POST['rifnote_rss_action'])) {
            return;
        }

        if (!isset($_POST['rifnote_rss_warehouse_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rifnote_rss_warehouse_nonce'])), 'rifnote_rss_warehouse_action')) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('RSS action failed security check.', 'rifnote-search') . '</p></div>';
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['rifnote_rss_action']));

        if ('run_now' === $action) {
            $summary = Rifnote_Search_Ingestion::run_once(0, true);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                __('RSS run finished: checked %1$d, created %2$d, published %3$d, duplicates %4$d, errors %5$d.', 'rifnote-search'),
                (int) ($summary['checked'] ?? 0),
                (int) ($summary['created'] ?? 0),
                (int) ($summary['published'] ?? 0),
                (int) ($summary['duplicates'] ?? 0),
                (int) ($summary['errors'] ?? 0)
            )) . '</p></div>';
            return;
        }

        if ('repair_schedule' === $action) {
            $repaired = Rifnote_Search_Ingestion::repair_schedule(true);
            echo '<div class="notice notice-' . esc_attr($repaired ? 'success' : 'info') . ' is-dismissible"><p>' . esc_html($repaired ? __('RSS schedule repaired and queued for the next minute.', 'rifnote-search') : __('RSS schedule is already queued in the future.', 'rifnote-search')) . '</p></div>';
            return;
        }

        if ('clear_lock' === $action) {
            delete_transient('rifnote_rss_ingestion_lock');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('RSS ingestion lock cleared.', 'rifnote-search') . '</p></div>';
            return;
        }

        if ('reset_cursor' === $action) {
            update_option('rifnote_smart_rss_cursor', 0, false);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('RSS queue cursor reset to the first feed.', 'rifnote-search') . '</p></div>';
            return;
        }

        if ('diagnose_feed' === $action) {
            $url = isset($_POST['rifnote_rss_diagnose_url']) ? esc_url_raw(wp_unslash($_POST['rifnote_rss_diagnose_url'])) : '';
            $diagnostic = self::diagnose_feed($url);
            set_transient('rifnote_rss_last_diagnostic', $diagnostic, 10 * MINUTE_IN_SECONDS);
            echo '<div class="notice notice-' . esc_attr(!empty($diagnostic['ok']) ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($diagnostic['message']) . '</p></div>';
        }
    }

    private static function summary() {
        $preview = Rifnote_Search_Ingestion::queue_preview();
        $logs = Rifnote_Search_Ingestion::recent_logs(1);
        $last = $logs ? $logs[0] : array();
        $feeds = self::all_feeds();
        $health = self::health_rows();
        $bad = 0;

        foreach ($health as $row) {
            if (!empty($row['status']) && !in_array($row['status'], array('ok', 'robots_warning'), true)) {
                $bad++;
            }
        }

        return array(
            'preview' => $preview,
            'feeds' => $feeds,
            'total_feeds' => count($feeds),
            'smart_feeds' => count(Rifnote_Search_Ingestion::smart_feeds()),
            'publisher_feeds' => count(Rifnote_Search_Ingestion::approved_publishers_with_feeds()),
            'bad_feeds' => $bad,
            'last_log' => $last,
        );
    }

    private static function render_dashboard() {
        $summary = self::summary();
        $schedule = $summary['preview']['schedule'] ?? array();
        $last_summary = is_array($summary['last_log']['summary'] ?? null) ? $summary['last_log']['summary'] : array();

        echo '<div style="display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:14px;max-width:1220px;">';
        self::metric_card(__('Total RSS feeds', 'rifnote-search'), number_format_i18n($summary['total_feeds']), __('Smart + approved publisher feeds', 'rifnote-search'));
        self::metric_card(__('Next run', 'rifnote-search'), !empty($schedule['next_run_local']) ? $schedule['next_run_local'] : __('Not scheduled', 'rifnote-search'), self::format_cron_status($schedule));
        self::metric_card(__('Next pass load', 'rifnote-search'), number_format_i18n((int) ($summary['preview']['expected_max_items'] ?? 0)), sprintf(__('%d feeds in the next batch', 'rifnote-search'), count($summary['preview']['feeds'] ?? array())));
        self::metric_card(__('Feeds needing attention', 'rifnote-search'), number_format_i18n($summary['bad_feeds']), __('Errors, blocked feeds or stale checks', 'rifnote-search'));
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,420px);gap:18px;max-width:1220px;margin-top:18px;">';
        echo '<div class="card" style="max-width:none;"><h2>' . esc_html__('Warehouse controls', 'rifnote-search') . '</h2>';
        echo '<p>' . esc_html__('Run a controlled batch, repair only the RSS hook, clear a stuck lock, or restart queue rotation without touching other cron jobs.', 'rifnote-search') . '</p>';
        self::action_button('run_now', __('Run next RSS batch now', 'rifnote-search'), 'primary');
        self::action_button('repair_schedule', __('Repair RSS schedule', 'rifnote-search'), 'secondary');
        self::action_button('clear_lock', __('Clear ingestion lock', 'rifnote-search'), 'secondary');
        self::action_button('reset_cursor', __('Reset queue cursor', 'rifnote-search'), 'secondary');
        echo '</div>';

        echo '<div class="card" style="max-width:none;"><h2>' . esc_html__('Last warehouse run', 'rifnote-search') . '</h2>';
        if ($summary['last_log']) {
            echo '<p><strong>' . esc_html($summary['last_log']['status'] ?? 'info') . '</strong> ' . esc_html($summary['last_log']['message'] ?? '') . '</p>';
            echo '<p>' . esc_html(sprintf(
                __('Checked %1$d · Created %2$d · Published %3$d · Duplicates %4$d · Errors %5$d', 'rifnote-search'),
                (int) ($last_summary['checked'] ?? 0),
                (int) ($last_summary['created'] ?? 0),
                (int) ($last_summary['published'] ?? 0),
                (int) ($last_summary['duplicates'] ?? 0),
                (int) ($last_summary['errors'] ?? 0)
            )) . '</p>';
        } else {
            echo '<p>' . esc_html__('No RSS warehouse run has been logged yet.', 'rifnote-search') . '</p>';
        }
        echo '</div></div>';

        self::render_queue_table(array_slice($summary['preview']['feeds'] ?? array(), 0, 16), __('Feeds expected in the next pass', 'rifnote-search'));
    }

    private static function render_feeds() {
        echo '<div class="card" style="max-width:1220px;"><h2>' . esc_html__('Smart RSS feed list', 'rifnote-search') . '</h2>';
        echo '<p>' . esc_html__('Add one feed per line. Format: Source name | Feed URL | Category | publish/review.', 'rifnote-search') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('rifnote_search_settings');
        echo '<textarea class="large-text code" rows="16" name="rifnote_smart_rss_list" placeholder="Punch | https://punchng.com/feed/ | News | publish">' . esc_textarea(get_option('rifnote_smart_rss_list', '')) . '</textarea>';
        echo '<p><label><input type="hidden" name="rifnote_smart_rss_enabled" value="0" /><input type="checkbox" name="rifnote_smart_rss_enabled" value="1" ' . checked((bool) get_option('rifnote_smart_rss_enabled', true), true, false) . ' /> ' . esc_html__('Enable Smart RSS warehouse feeds', 'rifnote-search') . '</label></p>';
        submit_button(__('Save RSS feed list', 'rifnote-search'));
        echo '</form></div>';

        self::render_inventory_table(self::all_feeds());
    }

    private static function render_queue() {
        $preview = Rifnote_Search_Ingestion::queue_preview();
        echo '<div class="card" style="max-width:1220px;"><h2>' . esc_html__('Queue planner', 'rifnote-search') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            __('Cursor %1$d · Batch size %2$d · Items per feed %3$d · Expected max items %4$d.', 'rifnote-search'),
            (int) ($preview['cursor'] ?? 0),
            (int) ($preview['batch_size'] ?? 0),
            (int) ($preview['items_per_feed'] ?? 0),
            (int) ($preview['expected_max_items'] ?? 0)
        )) . '</p>';
        echo '<p><strong>' . esc_html__('Schedule:', 'rifnote-search') . '</strong> ' . esc_html(self::format_cron_status($preview['schedule'] ?? array())) . '</p>';
        self::action_button('run_now', __('Run this batch now', 'rifnote-search'), 'primary');
        self::action_button('reset_cursor', __('Reset cursor', 'rifnote-search'), 'secondary');
        echo '</div>';

        self::render_queue_table($preview['feeds'] ?? array(), __('Full next batch', 'rifnote-search'));
    }

    private static function render_logs() {
        $logs = Rifnote_Search_Ingestion::recent_logs(50);
        echo '<div class="card" style="max-width:1220px;"><h2>' . esc_html__('RSS run logs', 'rifnote-search') . '</h2>';
        echo '<p>' . esc_html__('Every warehouse run records expected URLs, checked feeds, created stories, duplicates, errors and recovered items.', 'rifnote-search') . '</p></div>';

        echo '<table class="widefat striped" style="max-width:1220px;"><thead><tr><th>' . esc_html__('Time', 'rifnote-search') . '</th><th>' . esc_html__('Status', 'rifnote-search') . '</th><th>' . esc_html__('Run summary', 'rifnote-search') . '</th><th>' . esc_html__('Expected URLs', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$logs) {
            echo '<tr><td colspan="4">' . esc_html__('No RSS logs yet.', 'rifnote-search') . '</td></tr>';
        }
        foreach ($logs as $log) {
            $summary = is_array($log['summary'] ?? null) ? $log['summary'] : array();
            echo '<tr>';
            echo '<td>' . esc_html(!empty($log['created_at']) ? get_date_from_gmt($log['created_at'], 'M j, Y H:i') : '') . '</td>';
            echo '<td><strong>' . esc_html($log['status'] ?? 'info') . '</strong></td>';
            echo '<td><strong>' . esc_html($log['message'] ?? '') . '</strong><br /><small>' . esc_html(sprintf(
                __('Checked %1$d · Created %2$d · Published %3$d · Recovered %4$d · Duplicates %5$d · Errors %6$d · Expected max %7$d', 'rifnote-search'),
                (int) ($summary['checked'] ?? 0),
                (int) ($summary['created'] ?? 0),
                (int) ($summary['published'] ?? 0),
                (int) ($summary['recovered'] ?? 0),
                (int) ($summary['duplicates'] ?? 0),
                (int) ($summary['errors'] ?? 0),
                (int) ($summary['expected_max_items'] ?? 0)
            )) . '</small></td>';
            echo '<td>';
            foreach (array_slice($summary['expected_urls'] ?? array(), 0, 8) as $expected) {
                echo '<code style="display:block;margin-bottom:4px;">' . esc_html($expected['feed_url'] ?? '') . '</code>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_health() {
        $diagnostic = get_transient('rifnote_rss_last_diagnostic');
        echo '<div class="card" style="max-width:1220px;"><h2>' . esc_html__('Feed diagnostics', 'rifnote-search') . '</h2>';
        echo '<form method="post" style="display:flex;gap:10px;align-items:center;max-width:900px;">';
        wp_nonce_field('rifnote_rss_warehouse_action', 'rifnote_rss_warehouse_nonce');
        echo '<input type="hidden" name="rifnote_rss_action" value="diagnose_feed" />';
        echo '<input class="regular-text" style="flex:1;" type="url" name="rifnote_rss_diagnose_url" placeholder="https://example.com/feed/" />';
        submit_button(__('Test feed', 'rifnote-search'), 'secondary', 'submit', false);
        echo '</form>';
        if (is_array($diagnostic)) {
            echo '<pre style="background:#111827;color:#e5e7eb;padding:14px;border-radius:8px;white-space:pre-wrap;overflow:auto;">' . esc_html(wp_json_encode($diagnostic, JSON_PRETTY_PRINT)) . '</pre>';
        }
        echo '</div>';

        self::render_health_table(self::health_rows());
    }

    private static function render_settings() {
        echo '<div class="card" style="max-width:900px;"><h2>' . esc_html__('RSS warehouse settings', 'rifnote-search') . '</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('rifnote_search_settings');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::settings_number_row('rifnote_smart_rss_interval_minutes', __('Run frequency', 'rifnote-search'), 1, 1440, __('Minutes between RSS ingestion passes. Your server cron can run every minute; Rifnote RSS will only run when this interval is due.', 'rifnote-search'));
        self::settings_number_row('rifnote_smart_rss_batch_size', __('Feeds per run', 'rifnote-search'), 1, 100, __('Keep this low for cheap shared hosting; raise it on a dedicated server.', 'rifnote-search'));
        self::settings_number_row('rifnote_smart_rss_items_per_feed', __('Items per feed', 'rifnote-search'), 1, 30, __('Maximum stories pulled from each source per pass.', 'rifnote-search'));
        self::settings_number_row('rifnote_smart_rss_timeout', __('HTTP timeout', 'rifnote-search'), 3, 20, __('Seconds before a slow feed is skipped.', 'rifnote-search'));
        echo '<tr><th scope="row">' . esc_html__('Default handling', 'rifnote-search') . '</th><td><label><input type="hidden" name="rifnote_smart_rss_auto_publish" value="0" /><input type="checkbox" name="rifnote_smart_rss_auto_publish" value="1" ' . checked((bool) get_option('rifnote_smart_rss_auto_publish', true), true, false) . ' /> ' . esc_html__('Auto-publish RSS items unless a feed line says review.', 'rifnote-search') . '</label></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save RSS settings', 'rifnote-search'));
        echo '</form></div>';
    }

    private static function settings_number_row($option, $label, $min, $max, $description) {
        $defaults = array(
            'rifnote_smart_rss_interval_minutes' => 5,
            'rifnote_smart_rss_batch_size' => 25,
            'rifnote_smart_rss_items_per_feed' => 10,
            'rifnote_smart_rss_timeout' => 8,
        );
        echo '<tr><th scope="row"><label for="' . esc_attr($option) . '">' . esc_html($label) . '</label></th><td><input id="' . esc_attr($option) . '" type="number" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" name="' . esc_attr($option) . '" value="' . esc_attr(get_option($option, $defaults[$option] ?? '')) . '" /><p class="description">' . esc_html($description) . '</p></td></tr>';
    }

    private static function action_button($action, $label, $type = 'secondary') {
        echo '<form method="post" style="display:inline-block;margin:0 8px 8px 0;">';
        wp_nonce_field('rifnote_rss_warehouse_action', 'rifnote_rss_warehouse_nonce');
        echo '<input type="hidden" name="rifnote_rss_action" value="' . esc_attr($action) . '" />';
        submit_button($label, $type, 'submit', false);
        echo '</form>';
    }

    private static function metric_card($label, $value, $hint) {
        echo '<div class="card" style="max-width:none;"><p style="margin:0 0 8px;color:#646970;">' . esc_html($label) . '</p><h2 style="font-size:30px;margin:0 0 8px;">' . esc_html($value) . '</h2><p style="margin:0;color:#646970;">' . esc_html($hint) . '</p></div>';
    }

    private static function all_feeds() {
        $smart = array_map(function ($feed) {
            $feed['feed_origin'] = __('Smart RSS', 'rifnote-search');
            return $feed;
        }, Rifnote_Search_Ingestion::smart_feeds());

        $publisher = array_map(function ($feed) {
            $feed['feed_origin'] = __('Publisher', 'rifnote-search');
            return $feed;
        }, Rifnote_Search_Ingestion::approved_publishers_with_feeds());

        return array_merge($smart, $publisher);
    }

    private static function health_rows() {
        $rows = array();

        foreach (Rifnote_Search_Ingestion::feed_health_summary(100) as $feed) {
            $rows[] = array(
                'name' => $feed['publisher_name'] ?? '',
                'url' => $feed['rss_feed_url'] ?? '',
                'origin' => __('Publisher', 'rifnote-search'),
                'status' => $feed['feed_status'] ?: 'pending',
                'last_checked' => $feed['feed_last_checked'] ?? '',
                'items' => (int) ($feed['feed_items_indexed'] ?? 0),
                'error' => $feed['feed_last_error'] ?? '',
            );
        }

        foreach (Rifnote_Search_Ingestion::smart_statuses(100) as $url => $status) {
            $rows[] = array(
                'name' => $status['name'] ?? wp_parse_url($url, PHP_URL_HOST),
                'url' => $url,
                'origin' => __('Smart RSS', 'rifnote-search'),
                'status' => $status['status'] ?? 'pending',
                'last_checked' => $status['last_checked'] ?? '',
                'items' => (int) ($status['items_indexed'] ?? 0),
                'error' => $status['last_error'] ?? '',
            );
        }

        return $rows;
    }

    private static function render_inventory_table($feeds) {
        echo '<h2>' . esc_html__('Warehouse inventory', 'rifnote-search') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1220px;"><thead><tr><th>' . esc_html__('Source', 'rifnote-search') . '</th><th>' . esc_html__('Origin', 'rifnote-search') . '</th><th>' . esc_html__('Feed URL', 'rifnote-search') . '</th><th>' . esc_html__('Category', 'rifnote-search') . '</th><th>' . esc_html__('Mode', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$feeds) {
            echo '<tr><td colspan="5">' . esc_html__('No RSS feeds are configured yet.', 'rifnote-search') . '</td></tr>';
        }
        foreach ($feeds as $feed) {
            echo '<tr><td><strong>' . esc_html($feed['publisher_name'] ?? '') . '</strong></td><td>' . esc_html($feed['feed_origin'] ?? '') . '</td><td><code>' . esc_html($feed['rss_feed_url'] ?? '') . '</code></td><td>' . esc_html($feed['categories'] ?? '') . '</td><td>' . esc_html(!empty($feed['auto_approve']) ? __('Publish', 'rifnote-search') : __('Review', 'rifnote-search')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_queue_table($feeds, $title) {
        echo '<h2 style="margin-top:22px;">' . esc_html($title) . '</h2>';
        echo '<table class="widefat striped" style="max-width:1220px;"><thead><tr><th>' . esc_html__('Expected source', 'rifnote-search') . '</th><th>' . esc_html__('Expected URL to pull', 'rifnote-search') . '</th><th>' . esc_html__('Category', 'rifnote-search') . '</th><th>' . esc_html__('Mode', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$feeds) {
            echo '<tr><td colspan="4">' . esc_html__('No feeds are queued for the next pass.', 'rifnote-search') . '</td></tr>';
        }
        foreach ($feeds as $feed) {
            echo '<tr><td><strong>' . esc_html($feed['name'] ?? '') . '</strong></td><td><code>' . esc_html($feed['feed_url'] ?? '') . '</code></td><td>' . esc_html($feed['category'] ?? '') . '</td><td>' . esc_html($feed['mode'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_health_table($rows) {
        echo '<h2>' . esc_html__('Source health ledger', 'rifnote-search') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1220px;"><thead><tr><th>' . esc_html__('Source', 'rifnote-search') . '</th><th>' . esc_html__('Origin', 'rifnote-search') . '</th><th>' . esc_html__('Status', 'rifnote-search') . '</th><th>' . esc_html__('Last checked', 'rifnote-search') . '</th><th>' . esc_html__('Indexed last pass', 'rifnote-search') . '</th><th>' . esc_html__('Last error', 'rifnote-search') . '</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="6">' . esc_html__('No feed health records yet. Run the warehouse once to populate this ledger.', 'rifnote-search') . '</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td><strong>' . esc_html($row['name']) . '</strong><br /><small><code>' . esc_html($row['url']) . '</code></small></td><td>' . esc_html($row['origin']) . '</td><td><strong>' . esc_html($row['status']) . '</strong></td><td>' . esc_html(!empty($row['last_checked']) ? get_date_from_gmt($row['last_checked'], 'M j, Y H:i') : __('Never', 'rifnote-search')) . '</td><td>' . esc_html(number_format_i18n((int) $row['items'])) . '</td><td>' . esc_html(wp_trim_words($row['error'], 18)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function diagnose_feed($url) {
        if (!$url || !preg_match('#^https?://#i', $url)) {
            return array('ok' => false, 'message' => __('Enter a valid feed URL.', 'rifnote-search'));
        }

        $started = microtime(true);
        $response = wp_remote_get($url, array(
            'timeout' => max(3, min(20, absint(get_option('rifnote_smart_rss_timeout', 8)))),
            'redirection' => 3,
            'user-agent' => 'RifnoteBot/1.0; +' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            return array(
                'ok' => false,
                'url' => $url,
                'message' => $response->get_error_message(),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            );
        }

        $body = (string) wp_remote_retrieve_body($response);
        $items = Rifnote_Search_Ingestion::parse_feed_xml($body);
        $item_count = is_wp_error($items) ? 0 : count($items);

        return array(
            'ok' => !is_wp_error($items),
            'url' => $url,
            'http_status' => (int) wp_remote_retrieve_response_code($response),
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'body_bytes' => strlen($body),
            'item_count' => $item_count,
            'first_items' => is_wp_error($items) ? array() : array_slice(array_map(function ($item) {
                return array(
                    'title' => $item['title'] ?? '',
                    'link' => $item['link'] ?? '',
                    'published_at' => $item['published_at'] ?? '',
                );
            }, $items), 0, 5),
            'message' => is_wp_error($items) ? $items->get_error_message() : sprintf(__('Feed parsed successfully with %d item(s).', 'rifnote-search'), $item_count),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        );
    }

    private static function format_cron_status($status) {
        if (empty($status['next_run'])) {
            return __('Not scheduled', 'rifnote-search');
        }

        if (!empty($status['is_overdue'])) {
            return sprintf(__('Overdue by %s', 'rifnote-search'), $status['overdue_label']);
        }

        return sprintf(__('Next %s · %s', 'rifnote-search'), $status['next_run_local'], $status['schedule'] ?: __('unknown schedule', 'rifnote-search'));
    }
}
