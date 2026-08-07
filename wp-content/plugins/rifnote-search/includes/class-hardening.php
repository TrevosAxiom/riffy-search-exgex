<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Hardening {
    public static function error_logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_error_logs';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::error_logs_table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            severity VARCHAR(30) NOT NULL DEFAULT 'warning',
            area VARCHAR(80) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY severity (severity),
            KEY area (area),
            KEY created_at (created_at)
        ) {$charset_collate};");

        if ('' === (string) get_option('rifnote_search_backup_plan', '')) {
            update_option('rifnote_search_backup_plan', self::default_backup_plan(), false);
        }

        update_option('rifnote_search_hardening_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::error_logs_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_hardening_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function default_backup_plan() {
        return "Before launch: export database, copy wp-content/plugins/rifnote-search, confirm Local/cloud host backup, and keep rollback notes for plugin deactivation.\nDaily after launch: verify host backups completed, export publisher/legal tables, and review error logs.\nRollback: deactivate Rifnote Search plugin, restore prior database snapshot if content tables changed, then clear caches.";
    }

    public static function client_key() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            $ip = trim($forwarded[0]);
        }

        return md5($ip . '|' . wp_salt('nonce'));
    }

    public static function rate_limit($bucket, $limit, $window) {
        $bucket = sanitize_key($bucket);
        $key = 'rifnote_rl_' . $bucket . '_' . self::client_key();
        $state = get_transient($key);

        if (!is_array($state)) {
            $state = array('count' => 0, 'reset' => time() + (int) $window);
        }

        if (time() > (int) $state['reset']) {
            $state = array('count' => 0, 'reset' => time() + (int) $window);
        }

        $state['count']++;
        set_transient($key, $state, max(60, (int) $window));

        if ((int) $state['count'] > (int) $limit) {
            return new WP_Error('rifnote_rate_limited', __('Too many requests. Please wait a moment and try again.', 'rifnote-search'), array('status' => 429));
        }

        return true;
    }

    public static function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function set_rest_cache_headers($response, $server, $request) {
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }

        $route = $request instanceof WP_REST_Request ? $request->get_route() : '';

        if (0 !== strpos($route, '/rifnote/v1/')) {
            return $response;
        }

        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (false !== strpos($route, '/search') || false !== strpos($route, '/trending') || false !== strpos($route, '/settings')) {
            $response->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');
            return $response;
        }

        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public static function log_error($area, $message, $context = array(), $severity = 'warning') {
        global $wpdb;

        self::maybe_install();

        return (bool) $wpdb->insert(self::error_logs_table(), array(
            'severity' => sanitize_key($severity),
            'area' => sanitize_key($area),
            'message' => sanitize_textarea_field($message),
            'context' => wp_json_encode(is_array($context) ? $context : array()),
            'created_at' => current_time('mysql', true),
        ));
    }

    public static function recent_errors($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::error_logs_table() . ' ORDER BY created_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    public static function clear_errors() {
        global $wpdb;

        self::maybe_install();
        return $wpdb->query('TRUNCATE TABLE ' . self::error_logs_table());
    }

    public static function launch_report() {
        $pages = array('search', 'submit-news', 'publisher-dashboard', 'football', 'dmca', 'publisher-opt-out');
        $missing_pages = array();

        foreach ($pages as $slug) {
            if (!get_page_by_path($slug, OBJECT, 'page')) {
                $missing_pages[] = $slug;
            }
        }

        $css = Rifnote_Search_Plugin::asset('*.css');
        $js = Rifnote_Search_Plugin::asset('*.js');
        $errors = self::recent_errors(5);
        $backup_plan = trim((string) get_option('rifnote_search_backup_plan', ''));

        return array(
            array('label' => __('Core plugin pages', 'rifnote-search'), 'status' => empty($missing_pages) ? 'pass' : 'fail', 'detail' => empty($missing_pages) ? __('Required plugin pages exist.', 'rifnote-search') : sprintf(__('Missing pages: %s', 'rifnote-search'), implode(', ', $missing_pages))),
            array('label' => __('Production assets', 'rifnote-search'), 'status' => ($css && $js) ? 'pass' : 'fail', 'detail' => ($css && $js) ? __('Built Vite assets are available.', 'rifnote-search') : __('Run npm run build before launch.', 'rifnote-search')),
            array('label' => __('PWA assets', 'rifnote-search'), 'status' => file_exists(RIFNOTE_SEARCH_DIR . 'public/icon.svg') ? 'pass' : 'fail', 'detail' => __('Manifest, service worker and icon are served by the plugin.', 'rifnote-search')),
            array('label' => __('Security controls', 'rifnote-search'), 'status' => 'pass', 'detail' => __('Admin routes require manage_options, public write endpoints are rate-limited, and security headers are sent.', 'rifnote-search')),
            array('label' => __('Recent error logs', 'rifnote-search'), 'status' => empty($errors) ? 'pass' : 'warn', 'detail' => empty($errors) ? __('No recent plugin errors logged.', 'rifnote-search') : sprintf(_n('%d recent issue logged.', '%d recent issues logged.', count($errors), 'rifnote-search'), count($errors))),
            array('label' => __('Backup plan', 'rifnote-search'), 'status' => $backup_plan ? 'pass' : 'warn', 'detail' => $backup_plan ? __('Backup and rollback notes are saved.', 'rifnote-search') : __('Add a launch backup and rollback plan.', 'rifnote-search')),
        );
    }
}
