<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Publishers {
    public static function publishers_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_publishers';
    }

    public static function submissions_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_submissions';
    }

    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_publisher_events';
    }

    public static function webhooks_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_publisher_webhooks';
    }

    public static function webhook_deliveries_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_publisher_webhook_deliveries';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $publishers = self::publishers_table();
        $submissions = self::submissions_table();
        $events = self::events_table();
        $webhooks = self::webhooks_table();
        $deliveries = self::webhook_deliveries_table();

        dbDelta("CREATE TABLE {$publishers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            publisher_name VARCHAR(190) NOT NULL,
            website_url TEXT NOT NULL,
            contact_email VARCHAR(190) NOT NULL,
            logo_url TEXT NULL,
            country VARCHAR(100) NULL,
            categories TEXT NULL,
            rss_feed_url TEXT NULL,
            sitemap_url TEXT NULL,
            api_key_hash VARCHAR(255) NULL,
            verification_token VARCHAR(190) NULL,
            verified_at DATETIME NULL,
            verification_status VARCHAR(50) DEFAULT 'pending',
            approval_status VARCHAR(50) DEFAULT 'pending',
            source_authority_score DECIMAL(5,2) DEFAULT 50.00,
            auto_approve TINYINT(1) DEFAULT 0,
            feed_status VARCHAR(50) DEFAULT 'pending',
            feed_last_checked DATETIME NULL,
            feed_last_error TEXT NULL,
            feed_items_indexed INT DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY contact_email (contact_email),
            KEY approval_status (approval_status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$submissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            publisher_id BIGINT UNSIGNED NULL,
            publisher_name VARCHAR(190) NOT NULL,
            website_url TEXT NULL,
            contact_email VARCHAR(190) NOT NULL,
            headline TEXT NOT NULL,
            original_url TEXT NOT NULL,
            excerpt TEXT NULL,
            category VARCHAR(100) NULL,
            tags TEXT NULL,
            image_url TEXT NULL,
            author_name VARCHAR(190) NULL,
            published_at DATETIME NULL,
            permission_confirmed TINYINT(1) DEFAULT 0,
            rights_confirmed TINYINT(1) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'pending',
            wp_post_id BIGINT UNSIGNED NULL,
            rejection_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY publisher_id (publisher_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            publisher_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'ok',
            message TEXT NULL,
            request_id VARCHAR(100) NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY publisher_id (publisher_id),
            KEY event_type (event_type),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$webhooks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            publisher_id BIGINT UNSIGNED NOT NULL,
            endpoint_url TEXT NOT NULL,
            events TEXT NULL,
            secret_key TEXT NOT NULL,
            secret_hash VARCHAR(64) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            last_success_at DATETIME NULL,
            last_failure_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY publisher_id (publisher_id),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$deliveries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            publisher_id BIGINT UNSIGNED NOT NULL,
            webhook_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            target_url TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            http_status INT NULL,
            response_message TEXT NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY publisher_id (publisher_id),
            KEY webhook_id (webhook_id),
            KEY event_type (event_type),
            KEY status (status)
        ) {$charset_collate};");

        update_option('rifnote_search_publishers_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $feed_status_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::publishers_table() . " LIKE %s", 'feed_status'));
        $verification_token_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::publishers_table() . " LIKE %s", 'verification_token'));
        $events_table = self::events_table();
        $events_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table));
        $webhooks_table = self::webhooks_table();
        $webhooks_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $webhooks_table));
        $webhook_secret_column = $webhooks_exists === $webhooks_table ? $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::webhooks_table() . " LIKE %s", 'secret_key')) : '';

        if (get_option('rifnote_search_publishers_db_version') !== RIFNOTE_SEARCH_VERSION || !$feed_status_column || !$verification_token_column || $events_exists !== $events_table || $webhooks_exists !== $webhooks_table || !$webhook_secret_column) {
            self::install();
        }
    }

    public static function sanitize_url_required($url) {
        $url = esc_url_raw((string) $url);
        return preg_match('#^https?://#', $url) ? $url : '';
    }

    public static function find_or_create_publisher($data) {
        global $wpdb;

        $table = self::publishers_table();
        $email = sanitize_email($data['contact_email']);
        $website_url = self::sanitize_url_required($data['website_url']);
        $publisher_name = sanitize_text_field($data['publisher_name']);

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE contact_email = %s OR website_url = %s ORDER BY id ASC LIMIT 1", $email, $website_url), ARRAY_A);
        $now = current_time('mysql', true);

        if ($existing) {
            $wpdb->update($table, array(
                'publisher_name' => $publisher_name,
                'website_url' => $website_url,
                'country' => sanitize_text_field($data['country'] ?? $existing['country']),
                'categories' => sanitize_text_field($data['categories'] ?? $existing['categories']),
                'rss_feed_url' => esc_url_raw($data['rss_feed_url'] ?? $existing['rss_feed_url']),
                'sitemap_url' => esc_url_raw($data['sitemap_url'] ?? $existing['sitemap_url']),
                'updated_at' => $now,
            ), array('id' => (int) $existing['id']));

            return (int) $existing['id'];
        }

        $wpdb->insert($table, array(
            'publisher_name' => $publisher_name,
            'website_url' => $website_url,
            'contact_email' => $email,
            'country' => sanitize_text_field($data['country'] ?? ''),
            'categories' => sanitize_text_field($data['categories'] ?? ''),
            'rss_feed_url' => esc_url_raw($data['rss_feed_url'] ?? ''),
            'sitemap_url' => esc_url_raw($data['sitemap_url'] ?? ''),
            'verification_status' => 'pending',
            'verification_token' => wp_generate_password(32, false),
            'approval_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function signup($data) {
        global $wpdb;

        self::maybe_install();

        $publisher_name = sanitize_text_field($data['publisher_name'] ?? '');
        $website_url = self::sanitize_url_required($data['website_url'] ?? '');
        $email = sanitize_email($data['contact_email'] ?? '');

        if (!$publisher_name || !$website_url || !is_email($email)) {
            return new WP_Error('rifnote_publisher_signup_missing', __('Add publisher name, website URL and a valid email.', 'rifnote-search'), array('status' => 400));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($website_url)) {
            return new WP_Error('rifnote_publisher_signup_blocked', __('This source is blocked from Rifnote Search.', 'rifnote-search'), array('status' => 403));
        }

        if (!get_role('rifnote_publisher')) {
            add_role('rifnote_publisher', __('Rifnote Publisher', 'rifnote-search'), array('read' => true));
        }

        $user_id = email_exists($email);
        $created_user = false;

        if (!$user_id) {
            $base_login = sanitize_user(current(explode('@', $email)), true);
            if (!$base_login) {
                $base_login = sanitize_user($publisher_name, true);
            }
            if (!$base_login) {
                $base_login = 'publisher';
            }

            $login = $base_login;
            $suffix = 1;
            while (username_exists($login)) {
                $suffix++;
                $login = $base_login . $suffix;
            }

            $user_id = wp_create_user($login, wp_generate_password(24, true), $email);
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            wp_update_user(array('ID' => $user_id, 'display_name' => $publisher_name, 'role' => 'rifnote_publisher'));
            wp_new_user_notification($user_id, null, 'user');
            $created_user = true;
        } else {
            $user = get_userdata($user_id);
            if ($user && !in_array('rifnote_publisher', (array) $user->roles, true)) {
                $user->add_role('rifnote_publisher');
            }
        }

        $publisher_id = self::find_or_create_publisher(array(
            'publisher_name' => $publisher_name,
            'website_url' => $website_url,
            'contact_email' => $email,
            'country' => sanitize_text_field($data['country'] ?? ''),
            'categories' => sanitize_text_field($data['categories'] ?? ''),
            'rss_feed_url' => esc_url_raw($data['rss_feed_url'] ?? ''),
            'sitemap_url' => esc_url_raw($data['sitemap_url'] ?? ''),
        ));

        if ($publisher_id && $user_id) {
            $wpdb->update(self::publishers_table(), array(
                'user_id' => (int) $user_id,
                'logo_url' => esc_url_raw($data['logo_url'] ?? ''),
                'updated_at' => current_time('mysql', true),
            ), array('id' => (int) $publisher_id));

            update_user_meta($user_id, 'rifnote_publisher_id', (int) $publisher_id);
            update_user_meta($user_id, 'rifnote_publisher_name', $publisher_name);
            update_user_meta($user_id, 'rifnote_publisher_website', $website_url);
        }

        self::log_event($publisher_id, 'publisher_signup', 'ok', __('Publisher signup received.', 'rifnote-search'), array('user_id' => (int) $user_id));

        return array(
            'success' => true,
            'publisher_id' => (int) $publisher_id,
            'user_id' => (int) $user_id,
            'account_status' => $created_user ? 'created' : 'existing',
            'dashboard_url' => home_url('/publisher-dashboard/'),
            'login_url' => wp_login_url(home_url('/publisher-dashboard/')),
            'message' => $created_user ? __('Publisher account created. Check your email to set your password.', 'rifnote-search') : __('Publisher profile linked. Sign in to open your dashboard.', 'rifnote-search'),
        );
    }

    public static function submit($data) {
        global $wpdb;

        self::maybe_install();

        $required = array('publisher_name', 'website_url', 'contact_email', 'headline', 'original_url', 'excerpt');

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('rifnote_missing_field', sprintf(__('Missing required field: %s', 'rifnote-search'), $field), array('status' => 400));
            }
        }

        if (!is_email($data['contact_email'])) {
            return new WP_Error('rifnote_invalid_email', __('Enter a valid contact email.', 'rifnote-search'), array('status' => 400));
        }

        $website_url = self::sanitize_url_required($data['website_url']);
        $original_url = self::sanitize_url_required($data['original_url']);

        if (!$website_url || !$original_url) {
            return new WP_Error('rifnote_invalid_url', __('Website URL and original story URL must be valid http(s) URLs.', 'rifnote-search'), array('status' => 400));
        }

        if (Rifnote_Search_Legal::is_domain_blocked($website_url) || Rifnote_Search_Legal::is_domain_blocked($original_url)) {
            return new WP_Error('rifnote_blocked_domain', __('This source is blocked from Rifnote Search ingestion or submissions.', 'rifnote-search'), array('status' => 403));
        }

        if (empty($data['rights_confirmed']) || empty($data['permission_confirmed'])) {
            return new WP_Error('rifnote_permission_required', __('Confirm rights and indexing permission before submitting.', 'rifnote-search'), array('status' => 400));
        }

        $publisher_id = self::find_or_create_publisher(array_merge($data, array('website_url' => $website_url)));
        $now = current_time('mysql', true);
        $published_at = !empty($data['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['published_at'])) : null;

        $wpdb->insert(self::submissions_table(), array(
            'publisher_id' => $publisher_id,
            'publisher_name' => Rifnote_Search_Source_Meta::normalize_text($data['publisher_name']),
            'website_url' => $website_url,
            'contact_email' => sanitize_email($data['contact_email']),
            'headline' => Rifnote_Search_Source_Meta::normalize_text($data['headline']),
            'original_url' => $original_url,
            'excerpt' => Rifnote_Search_Source_Meta::normalize_text($data['excerpt'], true),
            'category' => Rifnote_Search_Source_Meta::normalize_text($data['category'] ?? ''),
            'tags' => Rifnote_Search_Source_Meta::normalize_text($data['tags'] ?? ''),
            'image_url' => esc_url_raw($data['image_url'] ?? ''),
            'author_name' => Rifnote_Search_Source_Meta::normalize_text($data['author'] ?? ($data['author_name'] ?? '')),
            'published_at' => $published_at,
            'permission_confirmed' => !empty($data['permission_confirmed']) ? 1 : 0,
            'rights_confirmed' => !empty($data['rights_confirmed']) ? 1 : 0,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return array(
            'success' => true,
            'submission_id' => (int) $wpdb->insert_id,
            'publisher_id' => $publisher_id,
            'status' => 'pending',
            'message' => __('Submission received for review.', 'rifnote-search'),
        );
    }

    public static function authenticate_api_key($raw_key) {
        global $wpdb;

        self::maybe_install();

        $raw_key = trim((string) $raw_key);

        if (!$raw_key || 0 !== strpos($raw_key, 'rfs_')) {
            return new WP_Error('rifnote_invalid_api_key', __('A valid Rifnote publisher API key is required.', 'rifnote-search'), array('status' => 401));
        }

        $publishers = $wpdb->get_results("SELECT * FROM " . self::publishers_table() . " WHERE api_key_hash IS NOT NULL AND api_key_hash <> '' ORDER BY updated_at DESC LIMIT 500", ARRAY_A);

        foreach ($publishers as $publisher) {
            if (wp_check_password($raw_key, $publisher['api_key_hash'])) {
                if ('approved' !== $publisher['approval_status']) {
                    self::log_event((int) $publisher['id'], 'api_auth_denied', 'blocked', __('Publisher API key belongs to a non-approved publisher.', 'rifnote-search'));
                    return new WP_Error('rifnote_publisher_not_approved', __('Publisher must be approved before API submission.', 'rifnote-search'), array('status' => 403));
                }

                return $publisher;
            }
        }

        return new WP_Error('rifnote_invalid_api_key', __('Publisher API key was not recognized.', 'rifnote-search'), array('status' => 401));
    }

    public static function submit_via_api($publisher, $data) {
        $publisher = is_array($publisher) ? $publisher : array();

        if (empty($publisher['id'])) {
            return new WP_Error('rifnote_invalid_publisher', __('Publisher authentication is required.', 'rifnote-search'), array('status' => 401));
        }

        $payload = array_merge(is_array($data) ? $data : array(), array(
            'publisher_name' => $publisher['publisher_name'],
            'website_url' => $publisher['website_url'],
            'contact_email' => $publisher['contact_email'],
            'rights_confirmed' => true,
            'permission_confirmed' => true,
        ));
        $result = self::submit($payload);

        if (is_wp_error($result)) {
            self::log_event((int) $publisher['id'], 'api_submit_failed', 'error', $result->get_error_message(), array('code' => $result->get_error_code()));
            return $result;
        }

        self::log_event((int) $publisher['id'], 'api_submit', 'ok', __('Story submitted through publisher API.', 'rifnote-search'), array('submission_id' => $result['submission_id']));

        return array_merge($result, array(
            'api' => true,
            'publisher' => array(
                'id' => (int) $publisher['id'],
                'publisher_name' => $publisher['publisher_name'],
                'approval_status' => $publisher['approval_status'],
            ),
        ));
    }

    public static function log_event($publisher_id, $event_type, $status = 'ok', $message = '', $metadata = array()) {
        global $wpdb;

        self::maybe_install();

        $request_id = isset($_SERVER['HTTP_X_REQUEST_ID']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REQUEST_ID'])) : wp_generate_uuid4();

        $wpdb->insert(self::events_table(), array(
            'publisher_id' => $publisher_id ? (int) $publisher_id : null,
            'event_type' => sanitize_key($event_type),
            'status' => sanitize_key($status),
            'message' => sanitize_textarea_field($message),
            'request_id' => $request_id,
            'metadata' => wp_json_encode(is_array($metadata) ? $metadata : array()),
            'created_at' => current_time('mysql', true),
        ));

        $event_id = (int) $wpdb->insert_id;

        if ($publisher_id) {
            self::dispatch_webhooks((int) $publisher_id, sanitize_key($event_type), array(
                'id' => $event_id,
                'publisher_id' => (int) $publisher_id,
                'event_type' => sanitize_key($event_type),
                'status' => sanitize_key($status),
                'message' => sanitize_textarea_field($message),
                'request_id' => $request_id,
                'metadata' => is_array($metadata) ? $metadata : array(),
                'created_at' => current_time('mysql', true),
            ));
        }

        return $event_id;
    }

    public static function recent_events($publisher_id = 0, $limit = 20) {
        global $wpdb;

        self::maybe_install();

        if ($publisher_id) {
            return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::events_table() . ' WHERE publisher_id = %d ORDER BY created_at DESC LIMIT %d', (int) $publisher_id, max(1, min(100, (int) $limit))), ARRAY_A);
        }

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::events_table() . ' ORDER BY created_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function register_webhook($publisher, $data) {
        global $wpdb;

        self::maybe_install();

        $publisher_id = is_array($publisher) && !empty($publisher['id']) ? (int) $publisher['id'] : 0;
        $endpoint = esc_url_raw($data['endpoint_url'] ?? '');

        if (!$publisher_id || !$endpoint || !preg_match('#^https?://#', $endpoint)) {
            return new WP_Error('rifnote_invalid_webhook', __('A valid webhook endpoint URL is required.', 'rifnote-search'), array('status' => 400));
        }

        $events = self::normalize_webhook_events($data['events'] ?? array('api_submit', 'api_submit_failed'));
        $secret = 'rfwhsec_' . wp_generate_password(32, false);
        $now = current_time('mysql', true);
        $wpdb->insert(self::webhooks_table(), array(
            'publisher_id' => $publisher_id,
            'endpoint_url' => $endpoint,
            'events' => implode(',', $events),
            'secret_key' => $secret,
            'secret_hash' => hash('sha256', $secret . wp_salt('auth')),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        $webhook_id = (int) $wpdb->insert_id;
        self::log_event($publisher_id, 'webhook_created', 'ok', __('Publisher webhook endpoint registered.', 'rifnote-search'), array('webhook_id' => $webhook_id));

        return array(
            'success' => true,
            'webhook_id' => $webhook_id,
            'endpoint_url' => $endpoint,
            'events' => $events,
            'signing_secret' => $secret,
            'message' => __('Webhook registered. Store the signing secret now; Rifnote will not show it again.', 'rifnote-search'),
        );
    }

    public static function webhooks($publisher_id, $limit = 20) {
        global $wpdb;

        self::maybe_install();

        return array_map(array(__CLASS__, 'webhook_payload'), $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::webhooks_table() . ' WHERE publisher_id = %d ORDER BY updated_at DESC LIMIT %d',
            (int) $publisher_id,
            max(1, min(100, (int) $limit))
        ), ARRAY_A));
    }

    public static function recent_webhook_deliveries($publisher_id = 0, $limit = 20) {
        global $wpdb;

        self::maybe_install();

        if ($publisher_id) {
            return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::webhook_deliveries_table() . ' WHERE publisher_id = %d ORDER BY created_at DESC LIMIT %d', (int) $publisher_id, max(1, min(100, (int) $limit))), ARRAY_A);
        }

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::webhook_deliveries_table() . ' ORDER BY created_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }

    private static function webhook_payload($webhook) {
        return array(
            'id' => (int) $webhook['id'],
            'endpoint_url' => esc_url_raw($webhook['endpoint_url']),
            'events' => array_values(array_filter(array_map('trim', explode(',', (string) $webhook['events'])))),
            'status' => $webhook['status'],
            'last_success_at' => $webhook['last_success_at'],
            'last_failure_at' => $webhook['last_failure_at'],
            'created_at' => $webhook['created_at'],
        );
    }

    private static function normalize_webhook_events($events) {
        if (is_string($events)) {
            $events = array_map('trim', explode(',', $events));
        }

        $allowed = array('api_submit', 'api_submit_failed', 'api_auth_denied', 'submission_approved', 'submission_rejected', 'webhook_created');
        $events = array_values(array_intersect(array_map('sanitize_key', is_array($events) ? $events : array()), $allowed));

        return $events ? $events : array('api_submit', 'api_submit_failed');
    }

    public static function dispatch_webhooks($publisher_id, $event_type, $payload) {
        global $wpdb;

        self::maybe_install();

        $webhooks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::webhooks_table() . " WHERE publisher_id = %d AND status = 'active' ORDER BY id ASC LIMIT 20",
            (int) $publisher_id
        ), ARRAY_A);

        foreach ($webhooks as $webhook) {
            $events = self::normalize_webhook_events($webhook['events']);

            if (!in_array($event_type, $events, true)) {
                continue;
            }

            self::deliver_webhook($webhook, $payload);
        }
    }

    private static function deliver_webhook($webhook, $payload) {
        global $wpdb;

        $body = wp_json_encode(array(
            'type' => $payload['event_type'] ?? '',
            'created_at' => gmdate(DATE_ATOM),
            'data' => $payload,
        ));
        $signature = hash_hmac('sha256', $body, (string) $webhook['secret_key']);
        $now = current_time('mysql', true);
        $wpdb->insert(self::webhook_deliveries_table(), array(
            'publisher_id' => (int) $webhook['publisher_id'],
            'webhook_id' => (int) $webhook['id'],
            'event_id' => isset($payload['id']) ? (int) $payload['id'] : null,
            'event_type' => sanitize_key($payload['event_type'] ?? ''),
            'target_url' => esc_url_raw($webhook['endpoint_url']),
            'status' => 'pending',
            'created_at' => $now,
        ));
        $delivery_id = (int) $wpdb->insert_id;
        $response = wp_remote_post($webhook['endpoint_url'], array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Rifnote-Event' => sanitize_key($payload['event_type'] ?? ''),
                'X-Rifnote-Delivery' => (string) $delivery_id,
                'X-Rifnote-Signature' => 'sha256=' . $signature,
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            $wpdb->update(self::webhook_deliveries_table(), array(
                'status' => 'failed',
                'response_message' => $response->get_error_message(),
                'delivered_at' => current_time('mysql', true),
            ), array('id' => $delivery_id));
            $wpdb->update(self::webhooks_table(), array(
                'last_failure_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ), array('id' => (int) $webhook['id']));
            return false;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $ok = $status_code >= 200 && $status_code < 300;
        $wpdb->update(self::webhook_deliveries_table(), array(
            'status' => $ok ? 'delivered' : 'failed',
            'http_status' => $status_code,
            'response_message' => wp_trim_words(wp_remote_retrieve_body($response), 20),
            'delivered_at' => current_time('mysql', true),
        ), array('id' => $delivery_id));
        $wpdb->update(self::webhooks_table(), array(
            ($ok ? 'last_success_at' : 'last_failure_at') => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $webhook['id']));

        return $ok;
    }

    public static function recent_submissions($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::submissions_table() . " ORDER BY created_at DESC LIMIT %d", max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function recent_publishers($limit = 30) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::publishers_table() . " ORDER BY updated_at DESC LIMIT %d", max(1, min(100, (int) $limit))), ARRAY_A);
    }

    public static function update_publisher_status($id, $approval_status, $auto_approve = null) {
        global $wpdb;

        $allowed = array('pending', 'approved', 'suspended', 'rejected');
        $approval_status = sanitize_key($approval_status);

        if (!in_array($approval_status, $allowed, true)) {
            return false;
        }

        $data = array(
            'approval_status' => $approval_status,
            'updated_at' => current_time('mysql', true),
        );

        if (null !== $auto_approve) {
            $data['auto_approve'] = !empty($auto_approve) ? 1 : 0;
        }

        return (bool) $wpdb->update(self::publishers_table(), $data, array('id' => (int) $id));
    }

    public static function ensure_verification_token($publisher_id) {
        global $wpdb;

        self::maybe_install();

        $publisher = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::publishers_table() . ' WHERE id = %d', (int) $publisher_id), ARRAY_A);

        if (!$publisher) {
            return '';
        }

        if (!empty($publisher['verification_token'])) {
            return $publisher['verification_token'];
        }

        $token = wp_generate_password(32, false);
        $wpdb->update(self::publishers_table(), array(
            'verification_token' => $token,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $publisher_id));

        return $token;
    }

    public static function verification_url($publisher_id) {
        $token = self::ensure_verification_token($publisher_id);

        return $token ? add_query_arg(array('publisher_id' => (int) $publisher_id, 'token' => $token), home_url('/wp-json/rifnote/v1/publisher/verify')) : '';
    }

    public static function verify_publisher($publisher_id, $token) {
        global $wpdb;

        self::maybe_install();

        $publisher = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::publishers_table() . ' WHERE id = %d', (int) $publisher_id), ARRAY_A);

        if (!$publisher || empty($publisher['verification_token']) || !hash_equals((string) $publisher['verification_token'], (string) $token)) {
            return new WP_Error('rifnote_invalid_verification', __('Publisher verification link is invalid or expired.', 'rifnote-search'), array('status' => 403));
        }

        $wpdb->update(self::publishers_table(), array(
            'verification_status' => 'verified',
            'verified_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $publisher_id));

        return array('success' => true, 'message' => __('Publisher verified. RSS ingestion can now be enabled after approval.', 'rifnote-search'));
    }

    public static function publisher_for_dashboard($publisher_id = 0) {
        global $wpdb;

        self::maybe_install();

        if (current_user_can('manage_options') && $publisher_id) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::publishers_table() . " WHERE id = %d", (int) $publisher_id), ARRAY_A);
        }

        $user = wp_get_current_user();

        if (!$user || empty($user->user_email)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::publishers_table() . " WHERE contact_email = %s ORDER BY id ASC LIMIT 1", sanitize_email($user->user_email)), ARRAY_A);
    }

    public static function dashboard_stats($publisher_id = 0) {
        global $wpdb;

        $publisher = self::publisher_for_dashboard($publisher_id);

        if (!$publisher) {
            return new WP_Error('rifnote_invalid_publisher', __('Publisher account not found for this user.', 'rifnote-search'), array('status' => 403));
        }

        $submissions_table = self::submissions_table();
        $submitted_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE publisher_id = %d", (int) $publisher['id']));
        $pending_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE publisher_id = %d AND status = 'pending'", (int) $publisher['id']));
        $approved_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE publisher_id = %d AND status = 'approved'", (int) $publisher['id']));
        $rejected_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$submissions_table} WHERE publisher_id = %d AND status = 'rejected'", (int) $publisher['id']));
        $submissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$submissions_table} WHERE publisher_id = %d ORDER BY created_at DESC LIMIT 12", (int) $publisher['id']), ARRAY_A);
        $indexed_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(array('key' => 'publisher_id', 'value' => (int) $publisher['id'], 'compare' => '=')),
        ));
        $indexed_total = (int) (new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'meta_query' => array(array('key' => 'publisher_id', 'value' => (int) $publisher['id'], 'compare' => '=')),
        )))->found_posts;

        $clicks = self::publisher_clicks((int) $publisher['id']);
        $publisher_analytics = Rifnote_Search_Analytics::publisher_summary((int) $publisher['id'], 30);

        return array(
            'success' => true,
            'profile' => array(
                'id' => (int) $publisher['id'],
                'publisher_name' => $publisher['publisher_name'],
                'website_url' => esc_url_raw($publisher['website_url']),
                'contact_email' => $publisher['contact_email'],
                'country' => $publisher['country'],
                'categories' => $publisher['categories'],
                'rss_feed_url' => esc_url_raw($publisher['rss_feed_url']),
                'sitemap_url' => esc_url_raw($publisher['sitemap_url']),
                'approval_status' => $publisher['approval_status'],
                'verification_status' => $publisher['verification_status'],
                'verification_url' => self::verification_url((int) $publisher['id']),
                'verified_at' => $publisher['verified_at'] ?? '',
                'source_authority_score' => (float) $publisher['source_authority_score'],
                'auto_approve' => (bool) $publisher['auto_approve'],
                'feed_status' => $publisher['feed_status'] ? $publisher['feed_status'] : 'pending',
                'feed_last_checked' => $publisher['feed_last_checked'],
                'feed_last_error' => $publisher['feed_last_error'],
            ),
            'stats' => array(
                'submitted_posts' => $submitted_total,
                'pending_posts' => $pending_total,
                'approved_posts' => $approved_total,
                'rejected_posts' => $rejected_total,
                'indexed_posts' => $indexed_total,
                'clicks_sent' => $clicks['total'],
                'analytics_ready' => $clicks['ready'],
                'impressions' => (int) $publisher_analytics['impressions'],
                'ctr' => (float) $publisher_analytics['ctr'],
            ),
            'analytics' => $publisher_analytics,
            'submissions' => array_map(array(__CLASS__, 'submission_payload'), $submissions),
            'indexed_posts' => array_map(array(__CLASS__, 'indexed_post_payload'), $indexed_posts),
            'api_events' => self::recent_events((int) $publisher['id'], 12),
            'webhooks' => self::webhooks((int) $publisher['id'], 10),
            'webhook_deliveries' => self::recent_webhook_deliveries((int) $publisher['id'], 12),
            'generated_at' => gmdate(DATE_ATOM),
        );
    }

    public static function update_self_service_profile($publisher, $data) {
        global $wpdb;

        self::maybe_install();

        if (empty($publisher['id'])) {
            return new WP_Error('rifnote_invalid_publisher', __('Publisher authentication is required.', 'rifnote-search'), array('status' => 401));
        }

        $allowed = array();
        foreach (array('publisher_name', 'logo_url', 'country', 'categories', 'rss_feed_url', 'sitemap_url') as $field) {
            if (isset($data[$field])) {
                $allowed[$field] = in_array($field, array('logo_url', 'rss_feed_url', 'sitemap_url'), true) ? esc_url_raw($data[$field]) : sanitize_text_field($data[$field]);
            }
        }

        if (!$allowed) {
            return new WP_Error('rifnote_no_profile_updates', __('No supported publisher profile fields were supplied.', 'rifnote-search'), array('status' => 400));
        }

        $allowed['updated_at'] = current_time('mysql', true);
        $wpdb->update(self::publishers_table(), $allowed, array('id' => (int) $publisher['id']));
        self::log_event((int) $publisher['id'], 'publisher_profile_updated', 'ok', __('Publisher updated profile settings.', 'rifnote-search'), array('fields' => array_keys($allowed)));

        return array('success' => true, 'publisher_id' => (int) $publisher['id'], 'updated_fields' => array_keys($allowed));
    }

    private static function publisher_clicks($publisher_id) {
        global $wpdb;

        $table = Rifnote_Search_Analytics::clicks_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (!$exists) {
            return array('ready' => false, 'total' => 0);
        }

        return array(
            'ready' => true,
            'total' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE publisher_id = %d", (int) $publisher_id)),
        );
    }

    private static function submission_payload($submission) {
        return array(
            'id' => (int) $submission['id'],
            'headline' => $submission['headline'],
            'original_url' => esc_url_raw($submission['original_url']),
            'status' => $submission['status'],
            'category' => $submission['category'],
            'created_at' => $submission['created_at'],
            'published_at' => $submission['published_at'],
            'wp_post_id' => (int) $submission['wp_post_id'],
        );
    }

    private static function indexed_post_payload($post) {
        global $wpdb;

        $clicks_table = Rifnote_Search_Analytics::clicks_table();
        $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks_table} WHERE post_id = %d", (int) $post->ID));

        return array(
            'id' => (int) $post->ID,
            'headline' => get_the_title($post),
            'url' => get_permalink($post),
            'original_url' => esc_url_raw(get_post_meta($post->ID, 'original_url', true)),
            'read_full_story_url' => esc_url_raw(get_post_meta($post->ID, 'read_full_story_url', true)),
            'source_type' => get_post_meta($post->ID, 'source_type', true),
            'published_at' => get_post_time(DATE_ATOM, true, $post),
            'clicks_sent' => $clicks,
        );
    }

    public static function get_submission($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::submissions_table() . " WHERE id = %d", (int) $id), ARRAY_A);
    }

    public static function approve_submission($id, $post_status = 'draft', $source_type = 'submitted') {
        global $wpdb;

        $submission = self::get_submission($id);

        if (!$submission) {
            return new WP_Error('rifnote_submission_missing', __('Submission not found.', 'rifnote-search'));
        }

        if (!empty($submission['wp_post_id'])) {
            $post_id = (int) $submission['wp_post_id'];
            $target_status = in_array($post_status, array('draft', 'publish', 'pending'), true) ? $post_status : 'draft';

            if ($target_status !== get_post_status($post_id)) {
                $updated_post_id = wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => $target_status,
                ), true);

                if (is_wp_error($updated_post_id)) {
                    return $updated_post_id;
                }
            }
        } else {
            $post_id = wp_insert_post(array(
                'post_type' => 'post',
                'post_status' => in_array($post_status, array('draft', 'publish', 'pending'), true) ? $post_status : 'draft',
                'post_title' => Rifnote_Search_Source_Meta::normalize_text($submission['headline']),
                'post_excerpt' => Rifnote_Search_Source_Meta::normalize_text($submission['excerpt'], true),
                'post_content' => Rifnote_Search_Source_Meta::normalize_text($submission['excerpt'], true),
                'post_date_gmt' => !empty($submission['published_at']) ? $submission['published_at'] : current_time('mysql', true),
                'post_date' => !empty($submission['published_at']) ? get_date_from_gmt($submission['published_at']) : current_time('mysql'),
            ), true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }
        }

        update_post_meta($post_id, 'source_name', Rifnote_Search_Source_Meta::normalize_text($submission['publisher_name']));
        update_post_meta($post_id, 'source_url', Rifnote_Search_Source_Meta::source_home_from_url($submission['original_url']));
        update_post_meta($post_id, 'original_url', $submission['original_url']);
        update_post_meta($post_id, 'read_full_story_url', $submission['original_url']);
        update_post_meta($post_id, 'canonical_url', $submission['original_url']);
        update_post_meta($post_id, 'publisher_id', (int) $submission['publisher_id']);
        update_post_meta($post_id, 'source_type', Rifnote_Search_Source_Meta::sanitize_source_type($source_type));
        update_post_meta($post_id, 'normalized_headline', Rifnote_Search_Source_Meta::normalize_headline($submission['headline']));
        update_post_meta($post_id, 'content_hash', hash('sha256', wp_strip_all_tags($submission['excerpt'] ? Rifnote_Search_Source_Meta::normalize_text($submission['excerpt'], true) : Rifnote_Search_Source_Meta::normalize_text($submission['headline']))));
        Rifnote_Search_Source_Meta::stamp_origin(
            $post_id,
            'rss' === $source_type ? 'RSS Feed' : 'Publisher Submission',
            $submission['publisher_name'],
            'rss' === $source_type ? 'rss' : 'publisher'
        );

        if (!empty($submission['image_url'])) {
            update_post_meta($post_id, 'rifnote_source_image_url', esc_url_raw($submission['image_url']));
        }

        Rifnote_Search_Aggregation::assign_category($post_id, $submission['category'] ?? '', array(
            $submission['headline'] ?? '',
            $submission['excerpt'] ?? '',
            $submission['publisher_name'] ?? '',
        ));

        if (!empty($submission['tags'])) {
            wp_set_post_terms($post_id, array_map('trim', explode(',', $submission['tags'])), 'post_tag', false);
        }

        if ('publish' === get_post_status($post_id)) {
            Rifnote_Search_Clustering::assign_post_cluster($post_id, get_post($post_id), true);
            Rifnote_Search_Index::index_post($post_id);
        }

        $wpdb->update(self::submissions_table(), array(
            'status' => 'approved',
            'wp_post_id' => $post_id,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
        Rifnote_Search_Editorial::log('submission', (int) $id, 'approved', '', array('post_id' => $post_id));
        if (!empty($submission['publisher_id'])) {
            self::log_event((int) $submission['publisher_id'], 'submission_approved', 'ok', __('Submission approved by editorial.', 'rifnote-search'), array('submission_id' => (int) $id, 'post_id' => $post_id));
        }

        return $post_id;
    }

    public static function reject_submission($id, $reason = '') {
        global $wpdb;

        $updated = (bool) $wpdb->update(self::submissions_table(), array(
            'status' => 'rejected',
            'rejection_reason' => sanitize_textarea_field($reason),
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
        if ($updated) {
            $submission = self::get_submission($id);
            Rifnote_Search_Editorial::log('submission', (int) $id, 'rejected', $reason);
            if (!empty($submission['publisher_id'])) {
                self::log_event((int) $submission['publisher_id'], 'submission_rejected', 'ok', __('Submission rejected by editorial.', 'rifnote-search'), array('submission_id' => (int) $id));
            }
        }

        return $updated;
    }
}
