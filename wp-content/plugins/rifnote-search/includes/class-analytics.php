<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Analytics {
    public static function logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_search_logs';
    }

    public static function clicks_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_source_clicks';
    }

    public static function visitors_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_audience_visitors';
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_audience_sessions';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $logs = self::logs_table();
        $clicks = self::clicks_table();
        $visitors = self::visitors_table();
        $sessions = self::sessions_table();

        dbDelta("CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(60) NOT NULL,
            visitor_id VARCHAR(80) NULL,
            session_id VARCHAR(80) NULL,
            visitor_type VARCHAR(30) NULL,
            is_returning TINYINT(1) DEFAULT 0,
            device_type VARCHAR(30) NULL,
            query_text TEXT NULL,
            category VARCHAR(100) NULL,
            date_range VARCHAR(30) NULL,
            sort_order VARCHAR(30) NULL,
            result_count INT DEFAULT 0,
            no_results TINYINT(1) DEFAULT 0,
            post_id BIGINT UNSIGNED NULL,
            publisher_id BIGINT UNSIGNED NULL,
            source_name VARCHAR(190) NULL,
            target_url TEXT NULL,
            referrer TEXT NULL,
            ip_hash VARCHAR(64) NULL,
            user_agent TEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY publisher_id (publisher_id),
            KEY post_id (post_id),
            KEY visitor_id (visitor_id),
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$clicks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(80) NULL,
            session_id VARCHAR(80) NULL,
            visitor_type VARCHAR(30) NULL,
            is_returning TINYINT(1) DEFAULT 0,
            device_type VARCHAR(30) NULL,
            post_id BIGINT UNSIGNED NULL,
            publisher_id BIGINT UNSIGNED NULL,
            source_name VARCHAR(190) NULL,
            target_url TEXT NOT NULL,
            click_type VARCHAR(60) DEFAULT 'source_click',
            query_text TEXT NULL,
            referrer TEXT NULL,
            ip_hash VARCHAR(64) NULL,
            user_agent TEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY publisher_id (publisher_id),
            KEY post_id (post_id),
            KEY click_type (click_type),
            KEY visitor_id (visitor_id),
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$visitors} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            visitor_type VARCHAR(30) NOT NULL DEFAULT 'guest',
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            visit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            search_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            click_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ad_impression_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ad_click_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            top_category VARCHAR(100) NULL,
            interest_tags LONGTEXT NULL,
            country VARCHAR(100) NULL,
            region VARCHAR(100) NULL,
            device_type VARCHAR(30) NULL,
            referrer_domain VARCHAR(190) NULL,
            opted_out TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY visitor_id (visitor_id),
            KEY user_id (user_id),
            KEY visitor_type (visitor_type),
            KEY last_seen (last_seen)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$sessions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(80) NOT NULL,
            visitor_id VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            visitor_type VARCHAR(30) NOT NULL DEFAULT 'guest',
            started_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pageview_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            referrer TEXT NULL,
            landing_url TEXT NULL,
            device_type VARCHAR(30) NULL,
            country VARCHAR(100) NULL,
            region VARCHAR(100) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY visitor_id (visitor_id),
            KEY last_seen (last_seen)
        ) {$charset_collate};");

        update_option('rifnote_search_analytics_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $logs = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::logs_table()));
        $clicks = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::clicks_table()));
        $visitors = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::visitors_table()));
        $sessions = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::sessions_table()));

        if (get_option('rifnote_search_analytics_db_version') !== RIFNOTE_SEARCH_VERSION || !$logs || !$clicks || !$visitors || !$sessions) {
            self::install();
        }

        self::ensure_audience_columns();
    }

    private static function ensure_audience_columns() {
        global $wpdb;

        $defs = array(
            self::logs_table() => array(
                'visitor_id' => 'ADD visitor_id VARCHAR(80) NULL AFTER event_type',
                'session_id' => 'ADD session_id VARCHAR(80) NULL AFTER visitor_id',
                'visitor_type' => 'ADD visitor_type VARCHAR(30) NULL AFTER session_id',
                'is_returning' => 'ADD is_returning TINYINT(1) DEFAULT 0 AFTER visitor_type',
                'device_type' => 'ADD device_type VARCHAR(30) NULL AFTER is_returning',
            ),
            self::clicks_table() => array(
                'visitor_id' => 'ADD visitor_id VARCHAR(80) NULL AFTER id',
                'session_id' => 'ADD session_id VARCHAR(80) NULL AFTER visitor_id',
                'visitor_type' => 'ADD visitor_type VARCHAR(30) NULL AFTER session_id',
                'is_returning' => 'ADD is_returning TINYINT(1) DEFAULT 0 AFTER visitor_type',
                'device_type' => 'ADD device_type VARCHAR(30) NULL AFTER is_returning',
            ),
        );

        foreach ($defs as $table => $columns) {
            $existing = $wpdb->get_col("DESC {$table}", 0);
            if (!is_array($existing)) {
                continue;
            }
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    $wpdb->query("ALTER TABLE {$table} {$definition}");
                }
            }
        }
    }

    public static function ip_hash() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return $ip ? hash('sha256', $ip . wp_salt('nonce')) : '';
    }

    public static function request_context() {
        return array(
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'ip_hash' => self::ip_hash(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        );
    }

    private static function sanitize_visitor_id($value) {
        $value = sanitize_text_field((string) $value);
        return preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $value) ? $value : '';
    }

    private static function device_type($metadata = array()) {
        $device = sanitize_key($metadata['device_type'] ?? '');

        if (in_array($device, array('mobile', 'tablet', 'desktop'), true)) {
            return $device;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';

        if (false !== strpos($ua, 'ipad') || false !== strpos($ua, 'tablet')) {
            return 'tablet';
        }

        if (false !== strpos($ua, 'mobile') || false !== strpos($ua, 'iphone') || false !== strpos($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    private static function referrer_domain($referrer) {
        $host = wp_parse_url($referrer, PHP_URL_HOST);
        return $host ? sanitize_text_field($host) : '';
    }

    private static function interest_tags($event_type, $data) {
        $tags = array();
        $category = sanitize_text_field($data['category'] ?? '');
        $query = sanitize_text_field($data['query_text'] ?? '');
        $source = sanitize_text_field($data['source_name'] ?? '');
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();

        if ($category && 'All News' !== $category && 'All' !== $category) {
            $tags[] = $category;
        }

        foreach (array($query, $source, sanitize_text_field($metadata['page_type'] ?? ''), sanitize_text_field($metadata['team'] ?? ''), sanitize_text_field($metadata['player'] ?? '')) as $text) {
            $text = strtolower($text);
            if (!$text) {
                continue;
            }
            if (preg_match('/football|score|fixture|transfer|player|team|league|goal|match|osimhen|mbappe|messi|arsenal|chelsea|madrid/', $text)) {
                $tags[] = 'Football';
            }
            if (preg_match('/politic|election|senate|president|governor|trump|tinubu/', $text)) {
                $tags[] = 'Politics';
            }
            if (preg_match('/business|market|stock|oil|naira|usd|economy|bank|fintech/', $text)) {
                $tags[] = 'Business';
            }
            if (preg_match('/tech|ai|startup|app|gadget|software/', $text)) {
                $tags[] = 'Tech';
            }
            if (preg_match('/nigeria|lagos|abuja|kano|africa/', $text)) {
                $tags[] = 'Nigeria';
            }
            if (preg_match('/music|movie|celebrity|entertainment|bbnaija|nollywood/', $text)) {
                $tags[] = 'Entertainment';
            }
        }

        if (in_array($event_type, array('ad_impression', 'sponsored_click', 'ad_click', 'ad_conversion'), true)) {
            $tags[] = 'Ad responsive';
        }

        return array_values(array_unique(array_filter($tags)));
    }

    private static function audience_context($data) {
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();
        $visitor_id = self::sanitize_visitor_id($data['visitor_id'] ?? ($metadata['visitor_id'] ?? ''));
        $session_id = self::sanitize_visitor_id($data['session_id'] ?? ($metadata['session_id'] ?? ''));
        $user_id = get_current_user_id();
        $visitor_type = $user_id ? 'registered' : 'guest';

        if (!$visitor_id) {
            $visitor_id = 'srv_' . substr(hash('sha256', self::ip_hash() . ($_SERVER['HTTP_USER_AGENT'] ?? '') . wp_salt('nonce')), 0, 32);
        }

        if (!$session_id) {
            $session_id = 'sess_' . substr(hash('sha256', $visitor_id . gmdate('YmdH') . wp_salt('nonce')), 0, 32);
        }

        return array(
            'visitor_id' => $visitor_id,
            'session_id' => $session_id,
            'visitor_type' => $visitor_type,
            'user_id' => $user_id,
            'device_type' => self::device_type($metadata),
            'country' => sanitize_text_field($metadata['country'] ?? ''),
            'region' => sanitize_text_field($metadata['region'] ?? ''),
        );
    }

    private static function record_audience($event_type, $data) {
        global $wpdb;

        $audience = self::audience_context($data);
        $now = current_time('mysql', true);
        $visitors = self::visitors_table();
        $sessions = self::sessions_table();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$visitors} WHERE visitor_id = %s", $audience['visitor_id']), ARRAY_A);
        $is_returning = $existing && (int) ($existing['visit_count'] ?? 0) > 0 ? 1 : 0;
        $tags = self::interest_tags($event_type, $data);
        $current_tags = $existing && !empty($existing['interest_tags']) ? json_decode($existing['interest_tags'], true) : array();
        $merged_tags = array_values(array_unique(array_merge(is_array($current_tags) ? $current_tags : array(), $tags)));
        $category = sanitize_text_field($data['category'] ?? '');
        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();

        $visitor_row = array(
            'user_id' => $audience['user_id'] ?: null,
            'visitor_type' => $audience['visitor_type'],
            'last_seen' => $now,
            'event_count' => (int) ($existing['event_count'] ?? 0) + 1,
            'search_count' => (int) ($existing['search_count'] ?? 0) + ('search' === $event_type ? 1 : 0),
            'click_count' => (int) ($existing['click_count'] ?? 0) + (false !== strpos($event_type, 'click') ? 1 : 0),
            'ad_impression_count' => (int) ($existing['ad_impression_count'] ?? 0) + ('ad_impression' === $event_type ? 1 : 0),
            'ad_click_count' => (int) ($existing['ad_click_count'] ?? 0) + (in_array($event_type, array('ad_click', 'sponsored_click', 'ad_conversion'), true) ? 1 : 0),
            'top_category' => $category ?: ($existing['top_category'] ?? ''),
            'interest_tags' => wp_json_encode(array_slice($merged_tags, 0, 24)),
            'country' => $audience['country'] ?: ($existing['country'] ?? ''),
            'region' => $audience['region'] ?: ($existing['region'] ?? ''),
            'device_type' => $audience['device_type'],
            'referrer_domain' => self::referrer_domain($referrer) ?: ($existing['referrer_domain'] ?? ''),
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update($visitors, $visitor_row, array('visitor_id' => $audience['visitor_id']));
        } else {
            $wpdb->insert($visitors, array_merge($visitor_row, array(
                'visitor_id' => $audience['visitor_id'],
                'first_seen' => $now,
                'visit_count' => 1,
                'created_at' => $now,
            )));
        }

        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions} WHERE session_id = %s", $audience['session_id']), ARRAY_A);
        $session_row = array(
            'visitor_id' => $audience['visitor_id'],
            'user_id' => $audience['user_id'] ?: null,
            'visitor_type' => $audience['visitor_type'],
            'last_seen' => $now,
            'event_count' => (int) ($session['event_count'] ?? 0) + 1,
            'pageview_count' => (int) ($session['pageview_count'] ?? 0) + ('page_view' === $event_type ? 1 : 0),
            'referrer' => $referrer,
            'landing_url' => esc_url_raw($metadata['url'] ?? ($session['landing_url'] ?? '')),
            'device_type' => $audience['device_type'],
            'country' => $audience['country'],
            'region' => $audience['region'],
        );

        if ($session) {
            $wpdb->update($sessions, $session_row, array('session_id' => $audience['session_id']));
        } else {
            $wpdb->insert($sessions, array_merge($session_row, array(
                'session_id' => $audience['session_id'],
                'started_at' => $now,
            )));
        }

        $audience['is_returning'] = $is_returning;
        return $audience;
    }

    public static function log_search($args, $payload) {
        $pagination = isset($payload['pagination']) && is_array($payload['pagination']) ? $payload['pagination'] : array();
        $result_count = isset($pagination['total']) ? (int) $pagination['total'] : 0;

        if (!empty($payload['results']) && is_array($payload['results'])) {
            foreach ($payload['results'] as $position => $result) {
                self::log_event('result_impression', array(
                    'query_text' => sanitize_text_field($args['query'] ?? ''),
                    'category' => sanitize_text_field($args['category'] ?? ''),
                    'visitor_id' => sanitize_text_field($args['visitor_id'] ?? ''),
                    'session_id' => sanitize_text_field($args['session_id'] ?? ''),
                    'post_id' => isset($result['id']) ? (int) $result['id'] : 0,
                    'publisher_id' => isset($result['publisher_id']) ? (int) $result['publisher_id'] : 0,
                    'source_name' => sanitize_text_field($result['source_name'] ?? ''),
                    'target_url' => esc_url_raw($result['read_full_story_url'] ?? ($result['original_url'] ?? '')),
                    'metadata' => array_merge(isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : array(), array('position' => (int) $position + 1, 'score' => isset($result['score']) ? (float) $result['score'] : 0)),
                ));
            }
        }

        if ($result_count <= 0 && !empty($args['query'])) {
            Rifnote_Search_Platform_Insights::record_no_result($args['query'], $args['category'] ?? '');
        }

        return self::log_event('search', array(
            'query_text' => sanitize_text_field($args['query'] ?? ''),
            'category' => sanitize_text_field($args['category'] ?? ''),
            'date_range' => sanitize_key($args['date_range'] ?? 'all'),
            'sort_order' => sanitize_key($args['sort'] ?? 'relevance'),
            'visitor_id' => sanitize_text_field($args['visitor_id'] ?? ''),
            'session_id' => sanitize_text_field($args['session_id'] ?? ''),
            'result_count' => $result_count,
            'no_results' => $result_count <= 0 ? 1 : 0,
            'metadata' => isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : array(),
        ));
    }

    public static function log_event($event_type, $data = array()) {
        global $wpdb;

        self::maybe_install();

        $context = self::request_context();
        $event_type = sanitize_key($event_type);

        if (!$event_type) {
            return false;
        }

        $audience = self::record_audience($event_type, $data);

        return (bool) $wpdb->insert(self::logs_table(), array_merge($context, array(
            'event_type' => $event_type,
            'visitor_id' => $audience['visitor_id'],
            'session_id' => $audience['session_id'],
            'visitor_type' => $audience['visitor_type'],
            'is_returning' => (int) $audience['is_returning'],
            'device_type' => $audience['device_type'],
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
            'category' => sanitize_text_field($data['category'] ?? ''),
            'date_range' => sanitize_key($data['date_range'] ?? ''),
            'sort_order' => sanitize_key($data['sort_order'] ?? ''),
            'result_count' => isset($data['result_count']) ? (int) $data['result_count'] : 0,
            'no_results' => !empty($data['no_results']) ? 1 : 0,
            'post_id' => isset($data['post_id']) ? (int) $data['post_id'] : null,
            'publisher_id' => isset($data['publisher_id']) ? (int) $data['publisher_id'] : null,
            'source_name' => sanitize_text_field($data['source_name'] ?? ''),
            'target_url' => esc_url_raw($data['target_url'] ?? ''),
            'metadata' => !empty($data['metadata']) ? wp_json_encode($data['metadata']) : null,
        )));
    }

    public static function log_click($data) {
        global $wpdb;

        self::maybe_install();

        $post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
        $publisher_id = isset($data['publisher_id']) ? (int) $data['publisher_id'] : 0;
        $target_url = esc_url_raw($data['target_url'] ?? '');
        $click_type = sanitize_key($data['click_type'] ?? 'source_click');

        if (!$target_url || !preg_match('#^https?://#', $target_url)) {
            return new WP_Error('rifnote_invalid_click_url', __('Click target URL is invalid.', 'rifnote-search'), array('status' => 400));
        }

        if (!$publisher_id && $post_id) {
            $publisher_id = (int) get_post_meta($post_id, 'publisher_id', true);
        }

        $source_name = sanitize_text_field($data['source_name'] ?? '');

        if (!$source_name && $post_id) {
            $source_name = sanitize_text_field(get_post_meta($post_id, 'source_name', true));
        }

        $context = self::request_context();
        $audience = self::audience_context($data);
        $visitor = $wpdb->get_row($wpdb->prepare('SELECT visit_count FROM ' . self::visitors_table() . ' WHERE visitor_id = %s', $audience['visitor_id']), ARRAY_A);
        $audience['is_returning'] = $visitor && (int) ($visitor['visit_count'] ?? 0) > 0 ? 1 : 0;
        $wpdb->insert(self::clicks_table(), array_merge($context, array(
            'visitor_id' => $audience['visitor_id'],
            'session_id' => $audience['session_id'],
            'visitor_type' => $audience['visitor_type'],
            'is_returning' => (int) $audience['is_returning'],
            'device_type' => $audience['device_type'],
            'post_id' => $post_id ? $post_id : null,
            'publisher_id' => $publisher_id ? $publisher_id : null,
            'source_name' => $source_name,
            'target_url' => $target_url,
            'click_type' => $click_type ? $click_type : 'source_click',
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
        )));

        self::log_event($click_type ? $click_type : 'source_click', array(
            'post_id' => $post_id,
            'publisher_id' => $publisher_id,
            'source_name' => $source_name,
            'target_url' => $target_url,
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
        ));

        return array('success' => true);
    }

    public static function summary($days = 7) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(90, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $logs = self::logs_table();
        $clicks = self::clicks_table();

        return array(
            'searches' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'search' AND created_at >= %s", $since)),
            'no_result_searches' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'search' AND no_results = 1 AND created_at >= %s", $since)),
            'source_clicks' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks} WHERE created_at >= %s", $since)),
            'top_queries' => self::top_rows("SELECT query_text AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'search' AND query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 8", $since),
            'no_result_queries' => self::top_rows("SELECT query_text AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'search' AND no_results = 1 AND query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 8", $since),
            'top_sources' => self::top_rows("SELECT source_name AS label, COUNT(*) AS total FROM {$clicks} WHERE source_name <> '' AND created_at >= %s GROUP BY source_name ORDER BY total DESC LIMIT 8", $since),
        );
    }

    public static function app_usage_summary($days = 30) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $logs = self::logs_table();
        $clicks = self::clicks_table();
        $sessions = self::sessions_table();
        $visitors = self::visitors_table();

        return array(
            'days' => $days,
            'events' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE created_at >= %s", $since)),
            'page_views' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'page_view' AND created_at >= %s", $since)),
            'sessions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sessions} WHERE last_seen >= %s", $since)),
            'visitors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE last_seen >= %s", $since)),
            'active_now' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sessions} WHERE last_seen >= %s", gmdate('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS))),
            'searches' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'search' AND created_at >= %s", $since)),
            'source_clicks' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks} WHERE created_at >= %s", $since)),
            'ad_impressions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'ad_impression' AND created_at >= %s", $since)),
            'ad_clicks' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type IN ('sponsored_click','ad_click') AND created_at >= %s", $since)),
            'ad_conversions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'ad_conversion' AND created_at >= %s", $since)),
            'top_events' => self::top_rows("SELECT event_type AS label, COUNT(*) AS total FROM {$logs} WHERE created_at >= %s GROUP BY event_type ORDER BY total DESC LIMIT 10", $since),
            'top_categories' => self::top_rows("SELECT category AS label, COUNT(*) AS total FROM {$logs} WHERE category <> '' AND created_at >= %s GROUP BY category ORDER BY total DESC LIMIT 10", $since),
            'top_devices' => self::top_rows("SELECT device_type AS label, COUNT(*) AS total FROM {$logs} WHERE device_type <> '' AND created_at >= %s GROUP BY device_type ORDER BY total DESC LIMIT 10", $since),
            'top_page_types' => self::top_page_types($since),
            'traffic_sources' => self::top_rows("SELECT referrer_domain AS label, COUNT(*) AS total FROM {$visitors} WHERE referrer_domain <> '' AND last_seen >= %s GROUP BY referrer_domain ORDER BY total DESC LIMIT 10", $since),
            'country_rows' => self::top_rows("SELECT country AS label, COUNT(*) AS total FROM {$visitors} WHERE country <> '' AND last_seen >= %s GROUP BY country ORDER BY total DESC LIMIT 10", $since),
            'region_rows' => self::top_rows("SELECT region AS label, COUNT(*) AS total FROM {$visitors} WHERE region <> '' AND last_seen >= %s GROUP BY region ORDER BY total DESC LIMIT 10", $since),
            'daily_series' => self::daily_series($since, $days),
        );
    }

    private static function daily_series($since, $days) {
        global $wpdb;

        $logs = self::logs_table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS label, COUNT(*) AS total, SUM(event_type = 'page_view') AS page_views, SUM(event_type = 'search') AS searches FROM {$logs} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY label ASC", $since), ARRAY_A);
        $indexed = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $indexed[$row['label']] = array(
                'label' => $row['label'],
                'total' => (int) $row['total'],
                'page_views' => (int) $row['page_views'],
                'searches' => (int) $row['searches'],
            );
        }

        $out = array();
        $window = min(30, max(7, (int) $days));
        for ($i = $window - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $out[] = $indexed[$date] ?? array('label' => $date, 'total' => 0, 'page_views' => 0, 'searches' => 0);
        }

        return $out;
    }

    private static function top_page_types($since) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT metadata FROM ' . self::logs_table() . " WHERE event_type = 'page_view' AND metadata IS NOT NULL AND created_at >= %s LIMIT 1000", $since), ARRAY_A);
        $counts = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $metadata = json_decode($row['metadata'], true);
            $page_type = sanitize_text_field($metadata['page_type'] ?? ($metadata['mode'] ?? 'unknown'));
            if (!$page_type) {
                $page_type = 'unknown';
            }
            $counts[$page_type] = ($counts[$page_type] ?? 0) + 1;
        }

        arsort($counts);
        $out = array();
        foreach (array_slice($counts, 0, 10, true) as $label => $total) {
            $out[] = array('label' => $label, 'total' => (int) $total);
        }

        return $out;
    }

    public static function recent_events($limit = 50) {
        global $wpdb;

        self::maybe_install();

        $limit = max(1, min(200, (int) $limit));
        return $wpdb->get_results($wpdb->prepare('SELECT id, event_type, visitor_id, session_id, visitor_type, is_returning, device_type, query_text, category, source_name, target_url, metadata, created_at FROM ' . self::logs_table() . ' ORDER BY created_at DESC LIMIT %d', $limit), ARRAY_A);
    }

    public static function audience_summary($days = 30) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $visitors = self::visitors_table();
        $sessions = self::sessions_table();
        $logs = self::logs_table();

        return array(
            'days' => $days,
            'visitors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE last_seen >= %s", $since)),
            'guests' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE visitor_type = 'guest' AND last_seen >= %s", $since)),
            'registered' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE visitor_type = 'registered' AND last_seen >= %s", $since)),
            'returning' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE visit_count > 1 AND last_seen >= %s", $since)),
            'sessions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sessions} WHERE last_seen >= %s", $since)),
            'events' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE created_at >= %s", $since)),
            'ad_responsive' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE ad_click_count > 0 AND last_seen >= %s", $since)),
            'device_mix' => self::top_rows("SELECT device_type AS label, COUNT(*) AS total FROM {$visitors} WHERE device_type <> '' AND last_seen >= %s GROUP BY device_type ORDER BY total DESC LIMIT 8", $since),
            'top_categories' => self::top_rows("SELECT top_category AS label, COUNT(*) AS total FROM {$visitors} WHERE top_category <> '' AND last_seen >= %s GROUP BY top_category ORDER BY total DESC LIMIT 8", $since),
            'country_rows' => self::top_rows("SELECT country AS label, COUNT(*) AS total FROM {$visitors} WHERE country <> '' AND last_seen >= %s GROUP BY country ORDER BY total DESC LIMIT 10", $since),
            'region_rows' => self::top_rows("SELECT region AS label, COUNT(*) AS total FROM {$visitors} WHERE region <> '' AND last_seen >= %s GROUP BY region ORDER BY total DESC LIMIT 10", $since),
            'traffic_sources' => self::top_rows("SELECT referrer_domain AS label, COUNT(*) AS total FROM {$visitors} WHERE referrer_domain <> '' AND last_seen >= %s GROUP BY referrer_domain ORDER BY total DESC LIMIT 10", $since),
            'top_interests' => self::audience_interest_rows($since),
            'segments' => self::audience_segments($since),
        );
    }

    public static function ad_performance_summary($days = 30) {
        global $wpdb;

        self::maybe_install();
        Rifnote_Search_Launch_Readiness::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $logs = self::logs_table();
        $placements = Rifnote_Search_Launch_Readiness::sponsored_table();
        $requests_table = Rifnote_Search_Launch_Readiness::sponsor_requests_table();
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, target_url, source_name, device_type, metadata FROM {$logs} WHERE event_type IN ('ad_impression','sponsored_click','ad_click','ad_conversion') AND created_at >= %s ORDER BY created_at DESC LIMIT 5000",
            $since
        ), ARRAY_A);
        $placement_rows = $wpdb->get_results("SELECT id, title, sponsor_name, campaign_request_id, placement, category, query_match, priority, status, impressions, clicks FROM {$placements} ORDER BY updated_at DESC LIMIT 500", ARRAY_A);
        $campaign_rows = $wpdb->get_results("SELECT id, sponsor_name, campaign_title, budget, estimated_price, placement_summary, status, starts_at, ends_at, campaign_payload FROM {$requests_table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);
        $placement_index = array();
        $placement_perf = array();
        $sponsor_perf = array();
        $device_perf = array();
        $geo_perf = array();
        $risk_counters = array(
            'clicks_by_visitor' => array(),
            'conversions_by_visitor' => array(),
        );

        foreach (is_array($placement_rows) ? $placement_rows : array() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $placement_index[$id] = $row;
            $placement_perf[$id] = array(
                'id' => $id,
                'campaign_request_id' => (int) ($row['campaign_request_id'] ?? 0),
                'label' => $row['title'],
                'sponsor' => $row['sponsor_name'],
                'placement' => $row['placement'],
                'status' => $row['status'],
                'priority' => (int) ($row['priority'] ?? 50),
                'stored_impressions' => (int) ($row['impressions'] ?? 0),
                'stored_clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0,
                'conversion_value' => 0,
                'ctr' => 0,
                'conversion_rate' => 0,
            );
        }

        foreach (is_array($events) ? $events : array() as $event) {
            $metadata = !empty($event['metadata']) ? json_decode($event['metadata'], true) : array();
            $placement_id = isset($metadata['placement_id']) ? (int) $metadata['placement_id'] : 0;
            $placement_key = sanitize_key($metadata['placement'] ?? '');
            $is_click = in_array($event['event_type'], array('sponsored_click', 'ad_click'), true);
            $is_conversion = 'ad_conversion' === $event['event_type'];
            $sponsor = sanitize_text_field($metadata['sponsor_name'] ?? ($event['source_name'] ?? 'Unknown sponsor'));
            $device = sanitize_key($event['device_type'] ?? ($metadata['device_type'] ?? 'unknown'));
            $geo = sanitize_text_field($metadata['region'] ?? ($metadata['country'] ?? 'Unknown'));
            $conversion_value = isset($metadata['conversion_value']) ? (float) $metadata['conversion_value'] : 0;

            if ($placement_id && !isset($placement_perf[$placement_id])) {
                $placement_perf[$placement_id] = array(
                    'id' => $placement_id,
                    'campaign_request_id' => 0,
                    'label' => sprintf(__('Placement #%d', 'rifnote-search'), $placement_id),
                    'sponsor' => $sponsor,
                    'placement' => $placement_key ?: 'unknown',
                    'status' => '',
                    'priority' => 0,
                    'stored_impressions' => 0,
                    'stored_clicks' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => 0,
                    'conversion_value' => 0,
                    'ctr' => 0,
                    'conversion_rate' => 0,
                );
            }

            if ($placement_id) {
                if ($is_conversion) {
                    $placement_perf[$placement_id]['conversions']++;
                    $placement_perf[$placement_id]['conversion_value'] += $conversion_value;
                    $visitor_key = sanitize_text_field($metadata['visitor_id'] ?? '') . ':' . $placement_id . ':' . sanitize_key($metadata['conversion_event'] ?? 'conversion');
                    if ($visitor_key && ':' !== $visitor_key) {
                        $risk_counters['conversions_by_visitor'][$visitor_key] = ($risk_counters['conversions_by_visitor'][$visitor_key] ?? 0) + 1;
                    }
                } else {
                    $placement_perf[$placement_id][$is_click ? 'clicks' : 'impressions']++;
                    if ($is_click) {
                        $visitor_key = sanitize_text_field($metadata['visitor_id'] ?? '') . ':' . $placement_id;
                        if ($visitor_key && ':' !== $visitor_key) {
                            $risk_counters['clicks_by_visitor'][$visitor_key] = ($risk_counters['clicks_by_visitor'][$visitor_key] ?? 0) + 1;
                        }
                    }
                }
            }

            self::increment_ad_bucket($sponsor_perf, $sponsor, $is_click, $is_conversion, $conversion_value);
            self::increment_ad_bucket($device_perf, $device ?: 'unknown', $is_click, $is_conversion, $conversion_value);
            self::increment_ad_bucket($geo_perf, $geo ?: 'Unknown', $is_click, $is_conversion, $conversion_value);
        }

        foreach ($placement_perf as &$row) {
            $row['impressions'] = max((int) $row['impressions'], (int) $row['stored_impressions']);
            $row['clicks'] = max((int) $row['clicks'], (int) $row['stored_clicks']);
            $row['ctr'] = $row['impressions'] > 0 ? round(($row['clicks'] / $row['impressions']) * 100, 2) : 0;
            $row['conversion_rate'] = $row['clicks'] > 0 ? round(((int) $row['conversions'] / (int) $row['clicks']) * 100, 2) : 0;
        }
        unset($row);

        $placement_perf = array_values($placement_perf);
        usort($placement_perf, function ($a, $b) {
            return ($b['impressions'] <=> $a['impressions']) ?: ($b['clicks'] <=> $a['clicks']);
        });

        return array(
            'days' => $days,
            'placements' => $placement_perf,
            'sponsors' => self::finalize_ad_buckets($sponsor_perf),
            'devices' => self::finalize_ad_buckets($device_perf),
            'geo' => self::finalize_ad_buckets($geo_perf),
            'weak_slots' => array_values(array_filter($placement_perf, function ($row) {
                return (int) ($row['impressions'] ?? 0) >= 20 && (float) ($row['ctr'] ?? 0) < 0.5;
            })),
            'top_slots' => array_slice($placement_perf, 0, 8),
            'campaign_pacing' => self::ad_campaign_pacing($campaign_rows, $placement_perf),
            'risk_alerts' => self::ad_risk_alerts($placement_perf, $risk_counters),
        );
    }

    public static function ad_revenue_report($days = 30) {
        global $wpdb;

        self::maybe_install();
        Rifnote_Search_Launch_Readiness::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $requests = Rifnote_Search_Launch_Readiness::sponsor_requests_table();
        $placements = Rifnote_Search_Launch_Readiness::sponsored_table();

        $status_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status AS label, COUNT(*) AS campaigns, SUM(estimated_price) AS value, SUM(payment_amount) AS paid_value FROM {$requests} WHERE created_at >= %s GROUP BY status ORDER BY value DESC",
            $since
        ), ARRAY_A);
        $daily_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS label, COUNT(*) AS campaigns, SUM(estimated_price) AS value, SUM(payment_amount) AS paid_value FROM {$requests} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY label DESC LIMIT 45",
            $since
        ), ARRAY_A);

        $summary = array(
            'pipeline' => (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(estimated_price),0) FROM {$requests} WHERE status IN ('new','reviewing','needs_changes','approved','payment_review') AND created_at >= %s", $since)),
            'booked' => (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(estimated_price),0) FROM {$requests} WHERE status IN ('paid','active','completed') AND created_at >= %s", $since)),
            'paid' => (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM {$requests} WHERE payment_amount > 0 AND created_at >= %s", $since)),
            'completed' => (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(estimated_price),0) FROM {$requests} WHERE status = 'completed' AND created_at >= %s", $since)),
            'active_campaigns' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$placements} WHERE status = 'active'"),
            'unsold_inventory' => max(0, count(Rifnote_Search_Launch_Readiness::ad_inventory()['placements']) - (int) $wpdb->get_var("SELECT COUNT(DISTINCT placement) FROM {$placements} WHERE status = 'active'")),
        );

        return array(
            'days' => $days,
            'summary' => $summary,
            'status_rows' => array_map(function ($row) {
                return array(
                    'label' => sanitize_key($row['label'] ?? ''),
                    'campaigns' => (int) ($row['campaigns'] ?? 0),
                    'value' => (float) ($row['value'] ?? 0),
                    'paid_value' => (float) ($row['paid_value'] ?? 0),
                );
            }, is_array($status_rows) ? $status_rows : array()),
            'daily_rows' => array_map(function ($row) {
                return array(
                    'label' => sanitize_text_field($row['label'] ?? ''),
                    'campaigns' => (int) ($row['campaigns'] ?? 0),
                    'value' => (float) ($row['value'] ?? 0),
                    'paid_value' => (float) ($row['paid_value'] ?? 0),
                );
            }, is_array($daily_rows) ? $daily_rows : array()),
        );
    }

    public static function source_performance_report($days = 30) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $logs = self::logs_table();
        $clicks = self::clicks_table();

        $source_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS source_name, COUNT(*) AS posts, MAX(p.post_date_gmt) AS latest_post
             FROM {$posts} p
             INNER JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'source_name'
             WHERE p.post_type = 'post' AND p.post_status IN ('publish','future') AND p.post_date_gmt >= %s AND pm.meta_value <> ''
             GROUP BY pm.meta_value
             ORDER BY posts DESC
             LIMIT 100",
            $since
        ), ARRAY_A);

        $impressions = self::keyed_counts($wpdb->get_results($wpdb->prepare(
            "SELECT source_name AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'result_impression' AND source_name <> '' AND created_at >= %s GROUP BY source_name",
            $since
        ), ARRAY_A));
        $click_rows = self::keyed_counts($wpdb->get_results($wpdb->prepare(
            "SELECT source_name AS label, COUNT(*) AS total FROM {$clicks} WHERE source_name <> '' AND created_at >= %s GROUP BY source_name",
            $since
        ), ARRAY_A));

        $rows = array();
        foreach (is_array($source_posts) ? $source_posts : array() as $row) {
            $label = Rifnote_Search_Source_Meta::normalize_text($row['source_name'] ?? '');
            if (!$label) {
                continue;
            }
            $views = (int) ($impressions[$label] ?? 0);
            $click_total = (int) ($click_rows[$label] ?? 0);
            $rows[$label] = array(
                'source_name' => $label,
                'posts' => (int) ($row['posts'] ?? 0),
                'latest_post' => sanitize_text_field($row['latest_post'] ?? ''),
                'impressions' => $views,
                'clicks' => $click_total,
                'ctr' => $views > 0 ? round(($click_total / $views) * 100, 2) : 0,
            );
        }

        foreach ($impressions as $label => $views) {
            if (isset($rows[$label])) {
                continue;
            }
            $click_total = (int) ($click_rows[$label] ?? 0);
            $rows[$label] = array(
                'source_name' => $label,
                'posts' => 0,
                'latest_post' => '',
                'impressions' => (int) $views,
                'clicks' => $click_total,
                'ctr' => $views > 0 ? round(($click_total / $views) * 100, 2) : 0,
            );
        }

        usort($rows, function ($a, $b) {
            return ($b['clicks'] <=> $a['clicks']) ?: ($b['impressions'] <=> $a['impressions']) ?: ($b['posts'] <=> $a['posts']);
        });

        return array(
            'days' => $days,
            'rows' => array_slice($rows, 0, 100),
        );
    }

    public static function content_performance_summary($days = 30) {
        global $wpdb;

        self::maybe_install();

        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $logs = self::logs_table();
        $clicks = self::clicks_table();

        $top_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT l.post_id, COUNT(*) AS impressions
             FROM {$logs} l
             WHERE l.event_type = 'result_impression' AND l.post_id IS NOT NULL AND l.created_at >= %s
             GROUP BY l.post_id
             ORDER BY impressions DESC
             LIMIT 30",
            $since
        ), ARRAY_A);
        $click_counts = self::keyed_int_counts($wpdb->get_results($wpdb->prepare(
            "SELECT post_id AS label, COUNT(*) AS total FROM {$clicks} WHERE post_id IS NOT NULL AND created_at >= %s GROUP BY post_id",
            $since
        ), ARRAY_A));

        $rows = array();
        foreach (is_array($top_posts) ? $top_posts : array() as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if (!$post_id) {
                continue;
            }
            $impressions = (int) ($row['impressions'] ?? 0);
            $click_total = (int) ($click_counts[$post_id] ?? 0);
            $rows[] = array(
                'post_id' => $post_id,
                'title' => get_the_title($post_id) ?: sprintf(__('Post #%d', 'rifnote-search'), $post_id),
                'source_name' => Rifnote_Search_Source_Meta::normalize_text(get_post_meta($post_id, 'source_name', true)),
                'category' => implode(', ', wp_get_post_categories($post_id, array('fields' => 'names'))),
                'impressions' => $impressions,
                'clicks' => $click_total,
                'ctr' => $impressions > 0 ? round(($click_total / $impressions) * 100, 2) : 0,
            );
        }

        $origin_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(pm.meta_value,''),'Unknown') AS label, COUNT(*) AS total
             FROM {$posts} p
             LEFT JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'rifnote_origin_model'
             WHERE p.post_type = 'post' AND p.post_status IN ('publish','future') AND p.post_date_gmt >= %s
             GROUP BY label ORDER BY total DESC LIMIT 10",
            $since
        ), ARRAY_A);

        return array(
            'days' => $days,
            'published' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt >= %s", $since)),
            'indexed_sources' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) FROM {$postmeta} WHERE meta_key = 'source_name' AND meta_value <> ''"),
            'top_posts' => $rows,
            'origin_rows' => array_map(function ($row) {
                return array('label' => Rifnote_Search_Source_Meta::normalize_text($row['label'] ?? 'Unknown'), 'total' => (int) ($row['total'] ?? 0));
            }, is_array($origin_rows) ? $origin_rows : array()),
        );
    }

    public static function football_analytics_summary($days = 7) {
        global $wpdb;

        self::maybe_install();
        Rifnote_Search_Football_API::maybe_install();

        $days = max(1, min(90, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $fixtures = Rifnote_Search_Football_API::fixtures_table();
        $usage = Rifnote_Search_Football_API::usage_table();
        $now = current_time('mysql', true);

        return array(
            'days' => $days,
            'live' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fixtures} WHERE status_short IN ('1H','2H','HT','ET','BT','P','SUSP','INT','LIVE')"),
            'upcoming_24h' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$fixtures} WHERE fixture_date >= %s AND fixture_date <= %s AND status_short IN ('NS','TBD')", $now, gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS))),
            'finished' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$fixtures} WHERE fixture_date >= %s AND status_short IN ('FT','AET','PEN')", $since)),
            'top_competitions' => self::top_rows("SELECT league_name AS label, COUNT(*) AS total FROM {$fixtures} WHERE league_name <> '' AND fixture_date >= %s GROUP BY league_name ORDER BY total DESC LIMIT 10", $since),
            'team_rows' => self::football_team_rows($fixtures, $since),
            'api_calls' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$usage} WHERE created_at >= %s", $since)),
            'cache_hits' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$usage} WHERE cache_hit = 1 AND created_at >= %s", $since)),
            'errors' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$usage} WHERE http_status >= 400 AND created_at >= %s", $since)),
            'endpoint_rows' => self::top_rows("SELECT endpoint AS label, COUNT(*) AS total FROM {$usage} WHERE created_at >= %s GROUP BY endpoint ORDER BY total DESC LIMIT 10", $since),
        );
    }

    private static function football_team_rows($fixtures, $since) {
        global $wpdb;
        $home = $wpdb->get_results($wpdb->prepare("SELECT home_team_name AS label, COUNT(*) AS total FROM {$fixtures} WHERE home_team_name <> '' AND fixture_date >= %s GROUP BY home_team_name", $since), ARRAY_A);
        $away = $wpdb->get_results($wpdb->prepare("SELECT away_team_name AS label, COUNT(*) AS total FROM {$fixtures} WHERE away_team_name <> '' AND fixture_date >= %s GROUP BY away_team_name", $since), ARRAY_A);
        $counts = array();
        foreach (array_merge(is_array($home) ? $home : array(), is_array($away) ? $away : array()) as $row) {
            $label = sanitize_text_field($row['label'] ?? '');
            if ($label) {
                $counts[$label] = ($counts[$label] ?? 0) + (int) ($row['total'] ?? 0);
            }
        }
        arsort($counts);
        $out = array();
        foreach (array_slice($counts, 0, 10, true) as $label => $total) {
            $out[] = array('label' => $label, 'total' => (int) $total);
        }
        return $out;
    }

    private static function keyed_int_counts($rows) {
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[(int) ($row['label'] ?? 0)] = (int) ($row['total'] ?? 0);
        }
        return $out;
    }

    private static function keyed_counts($rows) {
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $label = Rifnote_Search_Source_Meta::normalize_text($row['label'] ?? '');
            if ($label) {
                $out[$label] = (int) ($row['total'] ?? 0);
            }
        }
        return $out;
    }

    private static function ad_campaign_pacing($campaign_rows, $placement_perf) {
        $by_request = array();
        foreach ($placement_perf as $placement) {
            $request_id = (int) ($placement['campaign_request_id'] ?? 0);
            if (!$request_id) {
                continue;
            }
            if (!isset($by_request[$request_id])) {
                $by_request[$request_id] = array('impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0);
            }
            $by_request[$request_id]['impressions'] += (int) ($placement['impressions'] ?? 0);
            $by_request[$request_id]['clicks'] += (int) ($placement['clicks'] ?? 0);
            $by_request[$request_id]['conversions'] += (int) ($placement['conversions'] ?? 0);
            $by_request[$request_id]['conversion_value'] += (float) ($placement['conversion_value'] ?? 0);
        }

        $rows = array();
        $now = time();
        foreach (is_array($campaign_rows) ? $campaign_rows : array() as $campaign) {
            $id = (int) ($campaign['id'] ?? 0);
            $start = !empty($campaign['starts_at']) ? strtotime($campaign['starts_at'] . ' UTC') : 0;
            $end = !empty($campaign['ends_at']) ? strtotime($campaign['ends_at'] . ' UTC') : 0;
            $progress = 0;
            if ($start && $end && $end > $start) {
                $progress = min(100, max(0, round((($now - $start) / ($end - $start)) * 100)));
            } elseif ('active' === ($campaign['status'] ?? '')) {
                $progress = 50;
            }
            $stats = $by_request[$id] ?? array('impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0);
            $payload = !empty($campaign['campaign_payload']) ? json_decode($campaign['campaign_payload'], true) : array();
            $estimated = (int) ($payload['estimate']['estimated_impressions'] ?? 0);
            $delivery = $estimated > 0 ? min(200, round(($stats['impressions'] / $estimated) * 100)) : 0;
            $pace_delta = $delivery - $progress;
            $rows[] = array(
                'id' => $id,
                'label' => sanitize_text_field($campaign['campaign_title'] ?? ''),
                'sponsor' => sanitize_text_field($campaign['sponsor_name'] ?? ''),
                'status' => sanitize_key($campaign['status'] ?? ''),
                'budget' => (float) ($campaign['estimated_price'] ?: ($campaign['budget'] ?? 0)),
                'placements' => sanitize_text_field($campaign['placement_summary'] ?? ''),
                'progress' => $progress,
                'delivery' => $delivery,
                'pace_delta' => $pace_delta,
                'impressions' => $stats['impressions'],
                'clicks' => $stats['clicks'],
                'conversions' => $stats['conversions'],
                'conversion_value' => $stats['conversion_value'],
                'signal' => self::ad_pacing_signal($pace_delta, $stats['impressions'], $estimated),
            );
        }

        return array_slice($rows, 0, 30);
    }

    private static function ad_pacing_signal($pace_delta, $impressions, $estimated) {
        if (!$estimated && !$impressions) {
            return __('Setup waiting for delivery data.', 'rifnote-search');
        }
        if ($pace_delta > 35) {
            return __('Overserving. Watch frequency and creative fatigue.', 'rifnote-search');
        }
        if ($pace_delta < -25) {
            return __('Underserving. Boost priority or widen targeting.', 'rifnote-search');
        }
        return __('On pace.', 'rifnote-search');
    }

    private static function ad_risk_alerts($placements, $risk_counters) {
        $alerts = array();

        foreach ($placements as $placement) {
            $impressions = (int) ($placement['impressions'] ?? 0);
            $clicks = (int) ($placement['clicks'] ?? 0);
            $ctr = (float) ($placement['ctr'] ?? 0);

            if ($impressions >= 30 && $ctr > 20) {
                $alerts[] = array(
                    'level' => 'high',
                    'title' => __('Unusual CTR spike', 'rifnote-search'),
                    'message' => sprintf(__('%1$s is showing %2$s%% CTR after %3$s views. Check traffic quality before billing it as premium.', 'rifnote-search'), $placement['label'], number_format_i18n($ctr, 2), number_format_i18n($impressions)),
                );
            }

            if ($clicks >= 20 && $impressions > 0 && $ctr < .2) {
                $alerts[] = array(
                    'level' => 'medium',
                    'title' => __('Low-intent clicks', 'rifnote-search'),
                    'message' => sprintf(__('%s has lots of clicks but very weak CTR. Review placement context and creative promise.', 'rifnote-search'), $placement['label']),
                );
            }
        }

        foreach ($risk_counters['clicks_by_visitor'] ?? array() as $key => $count) {
            if ($count >= 5) {
                $alerts[] = array(
                    'level' => 'high',
                    'title' => __('Repeat click pattern', 'rifnote-search'),
                    'message' => sprintf(__('%d sponsored clicks came from the same visitor-slot pair. Consider discounting or investigating.', 'rifnote-search'), (int) $count),
                );
            }
        }

        foreach ($risk_counters['conversions_by_visitor'] ?? array() as $key => $count) {
            if ($count >= 3) {
                $alerts[] = array(
                    'level' => 'medium',
                    'title' => __('Duplicate conversion pattern', 'rifnote-search'),
                    'message' => sprintf(__('%d conversion events came from the same visitor-slot-event pair.', 'rifnote-search'), (int) $count),
                );
            }
        }

        return array_slice($alerts, 0, 12);
    }

    private static function increment_ad_bucket(&$bucket, $label, $is_click, $is_conversion = false, $conversion_value = 0) {
        $label = sanitize_text_field($label ?: __('Unknown', 'rifnote-search'));

        if (!isset($bucket[$label])) {
            $bucket[$label] = array('label' => $label, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'conversion_value' => 0, 'ctr' => 0, 'conversion_rate' => 0);
        }

        if ($is_conversion) {
            $bucket[$label]['conversions']++;
            $bucket[$label]['conversion_value'] += (float) $conversion_value;
            return;
        }

        $bucket[$label][$is_click ? 'clicks' : 'impressions']++;
    }

    private static function finalize_ad_buckets($bucket) {
        foreach ($bucket as &$row) {
            $row['ctr'] = (int) $row['impressions'] > 0 ? round(((int) $row['clicks'] / (int) $row['impressions']) * 100, 2) : 0;
            $row['conversion_rate'] = (int) $row['clicks'] > 0 ? round(((int) $row['conversions'] / (int) $row['clicks']) * 100, 2) : 0;
            $row['total'] = (int) $row['impressions'];
        }
        unset($row);

        $rows = array_values($bucket);
        usort($rows, function ($a, $b) {
            return ($b['impressions'] <=> $a['impressions']) ?: ($b['clicks'] <=> $a['clicks']);
        });

        return array_slice($rows, 0, 12);
    }

    private static function audience_interest_rows($since) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT interest_tags FROM ' . self::visitors_table() . ' WHERE interest_tags IS NOT NULL AND last_seen >= %s LIMIT 500', $since), ARRAY_A);
        $counts = array();

        foreach (is_array($rows) ? $rows : array() as $row) {
            $tags = json_decode($row['interest_tags'], true);
            foreach (is_array($tags) ? $tags : array() as $tag) {
                $tag = sanitize_text_field($tag);
                if (!$tag) {
                    continue;
                }
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);
        $out = array();
        foreach (array_slice($counts, 0, 10, true) as $label => $total) {
            $out[] = array('label' => $label, 'total' => (int) $total);
        }

        return $out;
    }

    private static function audience_segments($since) {
        global $wpdb;

        $visitors = self::visitors_table();
        $segments = array(
            array('label' => __('Football fans', 'rifnote-search'), 'where' => "interest_tags LIKE '%Football%'"),
            array('label' => __('Politics readers', 'rifnote-search'), 'where' => "interest_tags LIKE '%Politics%'"),
            array('label' => __('Business crowd', 'rifnote-search'), 'where' => "interest_tags LIKE '%Business%'"),
            array('label' => __('Tech curious', 'rifnote-search'), 'where' => "interest_tags LIKE '%Tech%'"),
            array('label' => __('Returning guests', 'rifnote-search'), 'where' => "visitor_type = 'guest' AND visit_count > 1"),
            array('label' => __('Ad clickers', 'rifnote-search'), 'where' => "ad_click_count > 0"),
            array('label' => __('Mobile audience', 'rifnote-search'), 'where' => "device_type = 'mobile'"),
        );

        return array_map(function ($segment) use ($wpdb, $visitors, $since) {
            return array(
                'label' => $segment['label'],
                'total' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$visitors} WHERE {$segment['where']} AND last_seen >= %s", $since)),
            );
        }, $segments);
    }

    private static function top_rows($sql, $since) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A);

        return array_map(function ($row) {
            return array('label' => $row['label'], 'total' => (int) $row['total']);
        }, is_array($rows) ? $rows : array());
    }

    public static function publisher_summary($publisher_id, $days = 30) {
        global $wpdb;

        self::maybe_install();

        $publisher_id = (int) $publisher_id;
        $days = max(1, min(365, (int) $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $logs = self::logs_table();
        $clicks = self::clicks_table();
        $impressions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE event_type = 'result_impression' AND publisher_id = %d AND created_at >= %s", $publisher_id, $since));
        $click_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks} WHERE publisher_id = %d AND created_at >= %s", $publisher_id, $since));

        return array(
            'days' => $days,
            'impressions' => $impressions,
            'clicks_sent' => $click_total,
            'ctr' => $impressions > 0 ? round(($click_total / $impressions) * 100, 2) : 0,
            'top_queries' => self::publisher_top_rows("SELECT query_text AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'result_impression' AND publisher_id = %d AND query_text <> '' AND created_at >= %s GROUP BY query_text ORDER BY total DESC LIMIT 8", $publisher_id, $since),
            'top_stories' => self::publisher_top_rows("SELECT post_id AS label, COUNT(*) AS total FROM {$logs} WHERE event_type = 'result_impression' AND publisher_id = %d AND post_id IS NOT NULL AND created_at >= %s GROUP BY post_id ORDER BY total DESC LIMIT 8", $publisher_id, $since, true),
            'top_clicked_stories' => self::publisher_top_rows("SELECT post_id AS label, COUNT(*) AS total FROM {$clicks} WHERE publisher_id = %d AND post_id IS NOT NULL AND created_at >= %s GROUP BY post_id ORDER BY total DESC LIMIT 8", $publisher_id, $since, true),
            'export_rows' => self::publisher_export_rows($publisher_id, $since),
        );
    }

    private static function publisher_top_rows($sql, $publisher_id, $since, $post_labels = false) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $publisher_id, $since), ARRAY_A);

        return array_map(function ($row) use ($post_labels) {
            $label = $row['label'];

            if ($post_labels) {
                $title = get_the_title((int) $label);
                $label = $title ? $title : sprintf(__('Post #%d', 'rifnote-search'), (int) $row['label']);
            }

            return array('label' => $label, 'total' => (int) $row['total']);
        }, is_array($rows) ? $rows : array());
    }

    private static function publisher_export_rows($publisher_id, $since) {
        global $wpdb;

        $logs = self::logs_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS date, COUNT(*) AS impressions FROM {$logs} WHERE event_type = 'result_impression' AND publisher_id = %d AND created_at >= %s GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30",
            (int) $publisher_id,
            $since
        ), ARRAY_A);
    }
}
