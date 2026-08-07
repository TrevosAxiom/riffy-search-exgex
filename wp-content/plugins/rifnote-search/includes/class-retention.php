<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Retention {
    const CRON_HOOK = 'rifnote_search_process_alerts';
    const NEWSLETTER_CRON_HOOK = 'rifnote_search_send_newsletter_digest';

    public static function preferences_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_user_preferences';
    }

    public static function alerts_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_alert_subscriptions';
    }

    public static function notifications_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_notifications';
    }

    public static function newsletters_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_newsletter_subscribers';
    }

    public static function devices_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_devices';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $preferences = self::preferences_table();
        $alerts = self::alerts_table();
        $notifications = self::notifications_table();
        $newsletters = self::newsletters_table();
        $devices = self::devices_table();

        dbDelta("CREATE TABLE {$preferences} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            anon_key VARCHAR(64) NULL,
            preference_type VARCHAR(50) NOT NULL,
            preference_value VARCHAR(190) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY anon_key (anon_key),
            KEY preference_type (preference_type),
            KEY preference_value (preference_value),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$alerts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            anon_key VARCHAR(64) NULL,
            email VARCHAR(190) NULL,
            alert_type VARCHAR(50) NOT NULL,
            alert_value VARCHAR(190) NOT NULL,
            channel VARCHAR(50) NOT NULL DEFAULT 'email',
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            last_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY anon_key (anon_key),
            KEY alert_type (alert_type),
            KEY alert_value (alert_value),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$notifications} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            anon_key VARCHAR(64) NULL,
            channel VARCHAR(50) NOT NULL DEFAULT 'in_app',
            title VARCHAR(190) NOT NULL,
            message TEXT NOT NULL,
            target_url TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'queued',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY anon_key (anon_key),
            KEY channel (channel),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$newsletters} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            topics TEXT NULL,
            unsubscribe_token VARCHAR(64) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            source VARCHAR(100) NULL,
            last_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY unsubscribe_token (unsubscribe_token),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$devices} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            anon_key VARCHAR(64) NULL,
            device_id VARCHAR(128) NOT NULL,
            platform VARCHAR(50) NOT NULL DEFAULT 'web',
            permission_status VARCHAR(50) NOT NULL DEFAULT 'default',
            push_token_hash VARCHAR(64) NULL,
            push_endpoint TEXT NULL,
            app_version VARCHAR(50) NULL,
            user_agent TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY device_id (device_id),
            KEY user_id (user_id),
            KEY anon_key (anon_key),
            KEY platform (platform),
            KEY permission_status (permission_status),
            KEY status (status)
        ) {$charset_collate};");

        update_option('rifnote_search_retention_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::preferences_table();
        $devices = self::devices_table();
        $newsletters = self::newsletters_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $devices_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $devices));
        $newsletters_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $newsletters));
        $unsubscribe_column = $newsletters_exists === $newsletters ? $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::newsletters_table() . " LIKE %s", 'unsubscribe_token')) : '';

        if (get_option('rifnote_search_retention_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table || $devices_exists !== $devices || $newsletters_exists !== $newsletters || !$unsubscribe_column) {
            self::install();
        }
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }

        if (!wp_next_scheduled(self::NEWSLETTER_CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 08:00'), 'daily', self::NEWSLETTER_CRON_HOOK);
        }
    }

    public static function anon_key($provided = '') {
        $provided = sanitize_text_field($provided);

        if ($provided) {
            return substr(hash('sha256', $provided . wp_salt('nonce')), 0, 64);
        }

        return Rifnote_Search_Hardening::client_key();
    }

    public static function identity($anon_key = '') {
        return array(
            'user_id' => get_current_user_id() ? get_current_user_id() : null,
            'anon_key' => self::anon_key($anon_key),
        );
    }

    public static function save_preference($data) {
        global $wpdb;

        self::maybe_install();

        $type = sanitize_key($data['preference_type'] ?? '');
        $value = sanitize_text_field($data['preference_value'] ?? '');
        $allowed = array('topic', 'publisher', 'team', 'player', 'category', 'saved_search', 'saved_story');

        if (!in_array($type, $allowed, true) || !$value) {
            return new WP_Error('rifnote_invalid_preference', __('Choose a valid preference type and value.', 'rifnote-search'), array('status' => 400));
        }

        $identity = self::identity($data['anon_key'] ?? '');
        $now = current_time('mysql', true);
        $table = self::preferences_table();
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE preference_type = %s AND preference_value = %s AND ((user_id IS NOT NULL AND user_id = %d) OR anon_key = %s) LIMIT 1',
            $type,
            $value,
            (int) ($identity['user_id'] ? $identity['user_id'] : 0),
            $identity['anon_key']
        ));

        $payload = array(
            'user_id' => $identity['user_id'],
            'anon_key' => $identity['anon_key'],
            'preference_type' => $type,
            'preference_value' => $value,
            'status' => sanitize_key($data['status'] ?? 'active'),
            'metadata' => !empty($data['metadata']) && is_array($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update($table, $payload, array('id' => (int) $existing));
            return array('success' => true, 'preference_id' => (int) $existing);
        }

        $payload['created_at'] = $now;
        $wpdb->insert($table, $payload);

        return array('success' => true, 'preference_id' => (int) $wpdb->insert_id);
    }

    public static function preferences($anon_key = '') {
        global $wpdb;

        self::maybe_install();

        $identity = self::identity($anon_key);

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::preferences_table() . ' WHERE status = %s AND ((user_id IS NOT NULL AND user_id = %d) OR anon_key = %s) ORDER BY updated_at DESC LIMIT 200',
            'active',
            (int) ($identity['user_id'] ? $identity['user_id'] : 0),
            $identity['anon_key']
        ), ARRAY_A);
    }

    public static function for_you_feed($anon_key = '', $limit = 12) {
        $preferences = self::preferences($anon_key);
        $queries = array_values(array_unique(array_filter(array_map(function ($row) {
            return in_array($row['preference_type'], array('topic', 'team', 'player', 'publisher', 'category', 'saved_search'), true) ? $row['preference_value'] : '';
        }, $preferences))));
        $results = array();

        foreach (array_slice($queries, 0, 8) as $query) {
            $payload = Rifnote_Search_Engine::payload(array('query' => $query, 'category' => '', 'date_range' => '30d', 'sort' => 'relevance'), 1, 3);
            $results = array_merge($results, $payload['results']);
        }

        if (!$results) {
            $payload = Rifnote_Search_Engine::payload(array('query' => '', 'category' => '', 'date_range' => '7d', 'sort' => 'relevance'), 1, $limit);
            $results = $payload['results'];
        }

        $unique = array();

        foreach ($results as $story) {
            $unique[$story['id']] = $story;
        }

        return array(
            'preferences' => $preferences,
            'saved_stories' => self::saved_stories($anon_key, 10),
            'notifications' => self::notifications($anon_key, 8),
            'results' => array_slice(array_values($unique), 0, max(1, min(30, (int) $limit))),
        );
    }

    public static function saved_stories($anon_key = '', $limit = 20) {
        $preferences = array_filter(self::preferences($anon_key), function ($row) {
            return 'saved_story' === $row['preference_type'];
        });
        $stories = array();

        foreach (array_slice($preferences, 0, max(1, min(50, (int) $limit))) as $preference) {
            $metadata = json_decode((string) $preference['metadata'], true);
            $post_id = absint($preference['preference_value']);
            $post = $post_id ? get_post($post_id) : null;

            if ($post && 'publish' === $post->post_status) {
                $stories[] = Rifnote_Search_Engine::result_payload($post_id, array('query' => '', 'category' => '', 'date_range' => 'all', 'sort' => 'relevance'));
                continue;
            }

            if (is_array($metadata) && !empty($metadata['headline'])) {
                $stories[] = array(
                    'id' => $post_id,
                    'headline' => sanitize_text_field($metadata['headline']),
                    'read_full_story_url' => esc_url_raw($metadata['url'] ?? ''),
                    'story_url' => esc_url_raw($metadata['url'] ?? ''),
                    'source_name' => sanitize_text_field($metadata['source_name'] ?? ''),
                );
            }
        }

        return array_values(array_filter($stories));
    }

    public static function save_alert($data) {
        global $wpdb;

        self::maybe_install();

        $type = sanitize_key($data['alert_type'] ?? 'search');
        $value = sanitize_text_field($data['alert_value'] ?? '');
        $email = !empty($data['email']) ? sanitize_email($data['email']) : '';

        if (!$value || ($email && !is_email($email))) {
            return new WP_Error('rifnote_invalid_alert', __('Alert value and valid email are required.', 'rifnote-search'), array('status' => 400));
        }

        $identity = self::identity($data['anon_key'] ?? '');
        $now = current_time('mysql', true);

        $wpdb->insert(self::alerts_table(), array(
            'user_id' => $identity['user_id'],
            'anon_key' => $identity['anon_key'],
            'email' => $email,
            'alert_type' => $type,
            'alert_value' => $value,
            'channel' => sanitize_key($data['channel'] ?? ($email ? 'email' : 'push')),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return array('success' => true, 'alert_id' => (int) $wpdb->insert_id, 'message' => __('Alert saved.', 'rifnote-search'));
    }

    public static function subscribe_newsletter($data) {
        global $wpdb;

        self::maybe_install();

        $email = sanitize_email($data['email'] ?? '');

        if (!$email || !is_email($email)) {
            return new WP_Error('rifnote_invalid_newsletter_email', __('Enter a valid email address.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->replace(self::newsletters_table(), array(
            'email' => $email,
            'topics' => sanitize_text_field($data['topics'] ?? ''),
            'unsubscribe_token' => self::unsubscribe_token($email),
            'status' => 'active',
            'source' => sanitize_key($data['source'] ?? 'site'),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return array('success' => true, 'message' => __('Newsletter signup saved.', 'rifnote-search'));
    }

    public static function unsubscribe_token($email) {
        return substr(hash('sha256', strtolower((string) $email) . '|' . wp_salt('auth')), 0, 64);
    }

    public static function unsubscribe_newsletter($token) {
        global $wpdb;

        self::maybe_install();

        $token = sanitize_text_field($token);

        if (!$token) {
            return new WP_Error('rifnote_invalid_unsubscribe', __('Unsubscribe token is missing.', 'rifnote-search'), array('status' => 400));
        }

        $updated = $wpdb->update(self::newsletters_table(), array(
            'status' => 'unsubscribed',
            'updated_at' => current_time('mysql', true),
        ), array('unsubscribe_token' => $token));

        return array('success' => (bool) $updated, 'message' => __('Newsletter preferences updated.', 'rifnote-search'));
    }

    public static function send_newsletter_digest($limit = 100) {
        global $wpdb;

        self::maybe_install();

        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::newsletters_table() . " WHERE status = 'active' ORDER BY COALESCE(last_sent_at, created_at) ASC LIMIT %d",
            max(1, min(500, (int) $limit))
        ), ARRAY_A);
        $briefing = Rifnote_Search_Operations::daily_briefing(8);
        $summary = array('subscribers' => count($subscribers), 'sent' => 0, 'failed' => 0, 'stories' => count($briefing['stories'] ?? array()));

        foreach ($subscribers as $subscriber) {
            $topics = sanitize_text_field($subscriber['topics'] ?? '');
            $stories = self::newsletter_stories($briefing, $topics);

            if (!$stories) {
                continue;
            }

            $sent = Rifnote_Search_Delivery::send_email(
                sanitize_email($subscriber['email']),
                sprintf(__('Rifnote Daily Briefing - %s', 'rifnote-search'), wp_date('M j')),
                self::newsletter_html($subscriber, $stories, $briefing),
                array('Content-Type: text/html; charset=UTF-8')
            );

            if ($sent) {
                $summary['sent']++;
                $wpdb->update(self::newsletters_table(), array(
                    'last_sent_at' => current_time('mysql', true),
                    'updated_at' => current_time('mysql', true),
                ), array('id' => (int) $subscriber['id']));
            } else {
                $summary['failed']++;
            }
        }

        update_option('rifnote_search_newsletter_last_run', array_merge($summary, array('ran_at' => gmdate(DATE_ATOM))), false);

        return $summary;
    }

    private static function newsletter_stories($briefing, $topics = '') {
        $stories = $briefing['stories'] ?? array();
        $topics = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $topics)))));

        if (!$topics) {
            return $stories;
        }

        $matched = array_filter($stories, function ($story) use ($topics) {
            $haystack = strtolower(($story['headline'] ?? '') . ' ' . ($story['source_name'] ?? '') . ' ' . ($story['category'] ?? ''));

            foreach ($topics as $topic) {
                if ($topic && false !== strpos($haystack, $topic)) {
                    return true;
                }
            }

            return false;
        });

        return $matched ? array_values($matched) : $stories;
    }

    private static function newsletter_html($subscriber, $stories, $briefing) {
        $unsubscribe_url = add_query_arg('token', rawurlencode($subscriber['unsubscribe_token']), home_url('/wp-json/rifnote/v1/newsletter/unsubscribe'));
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#111827">';
        $html .= '<h1>' . esc_html($briefing['title'] ?? __('Rifnote Daily Briefing', 'rifnote-search')) . '</h1>';
        $html .= '<p>' . esc_html($briefing['intro'] ?? __('Source-backed stories from Rifnote Search.', 'rifnote-search')) . '</p>';

        foreach ($stories as $story) {
            $url = esc_url($story['story_url'] ?? ($story['read_full_story_url'] ?? ''));
            $html .= '<article style="border-top:1px solid #dfe4ec;padding:14px 0">';
            $html .= '<h2 style="font-size:18px;margin:0 0 6px"><a href="' . $url . '">' . esc_html($story['headline'] ?? '') . '</a></h2>';
            $html .= '<p style="margin:0;color:#667085">' . esc_html(($story['source_name'] ?? 'Rifnote') . ' · score ' . number_format_i18n((float) ($story['score'] ?? 0), 2)) . '</p>';
            $html .= '</article>';
        }

        $html .= '<p style="border-top:1px solid #dfe4ec;padding-top:14px"><a href="' . esc_url(home_url('/daily-briefing/')) . '">' . esc_html__('Open full briefing', 'rifnote-search') . '</a> · <a href="' . esc_url($unsubscribe_url) . '">' . esc_html__('Unsubscribe', 'rifnote-search') . '</a></p>';
        $html .= '</div>';

        return $html;
    }

    public static function queue_notification($data) {
        self::maybe_install();

        $identity = self::identity($data['anon_key'] ?? '');

        return self::insert_notification($identity, $data);
    }

    private static function insert_notification($identity, $data) {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->insert(self::notifications_table(), array(
            'user_id' => $identity['user_id'],
            'anon_key' => $identity['anon_key'],
            'channel' => sanitize_key($data['channel'] ?? 'in_app'),
            'title' => sanitize_text_field($data['title'] ?? ''),
            'message' => sanitize_textarea_field($data['message'] ?? ''),
            'target_url' => esc_url_raw($data['target_url'] ?? ''),
            'status' => 'queued',
            'scheduled_at' => !empty($data['scheduled_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['scheduled_at'])) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function process_alerts($limit = 100) {
        global $wpdb;

        self::maybe_install();

        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::alerts_table() . " WHERE status = 'active' ORDER BY COALESCE(last_sent_at, created_at) ASC LIMIT %d",
            max(1, min(500, (int) $limit))
        ), ARRAY_A);
        $summary = array('checked' => 0, 'matched' => 0, 'queued' => 0, 'skipped_duplicates' => 0);

        foreach ($alerts as $alert) {
            $summary['checked']++;
            $matches = self::matches_for_alert($alert);

            if (!$matches) {
                continue;
            }

            $summary['matched']++;

            foreach (array_slice($matches, 0, 3) as $story) {
                $identity = array(
                    'user_id' => !empty($alert['user_id']) ? (int) $alert['user_id'] : null,
                    'anon_key' => $alert['anon_key'],
                );
                $target_url = !empty($story['story_url']) ? $story['story_url'] : ($story['read_full_story_url'] ?? '');
                $title = sprintf(__('New Rifnote alert: %s', 'rifnote-search'), $alert['alert_value']);

                if (self::notification_exists($identity, $title, $target_url)) {
                    $summary['skipped_duplicates']++;
                    continue;
                }

                $summary['queued'] += self::insert_notification($identity, array(
                    'channel' => $alert['channel'],
                    'title' => $title,
                    'message' => wp_trim_words($story['headline'] . ' - ' . ($story['excerpt'] ?? ''), 28),
                    'target_url' => $target_url,
                )) ? 1 : 0;
            }

            $wpdb->update(self::alerts_table(), array(
                'last_sent_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ), array('id' => (int) $alert['id']));
        }

        update_option('rifnote_search_alerts_last_run', array_merge($summary, array('ran_at' => gmdate(DATE_ATOM))), false);

        return $summary;
    }

    private static function matches_for_alert($alert) {
        $type = sanitize_key($alert['alert_type'] ?? 'search');
        $value = sanitize_text_field($alert['alert_value'] ?? '');

        if (!$value) {
            return array();
        }

        $args = array('query' => $value, 'category' => '', 'date_range' => '24h', 'sort' => 'latest');

        if ('category' === $type) {
            $args['query'] = '';
            $args['category'] = sanitize_title($value);
        }

        $payload = Rifnote_Search_Engine::payload($args, 1, 5);
        $last_sent = !empty($alert['last_sent_at']) ? strtotime($alert['last_sent_at']) : 0;

        return array_values(array_filter($payload['results'] ?? array(), function ($story) use ($last_sent) {
            if (!$last_sent || empty($story['published_at'])) {
                return true;
            }

            $published = strtotime($story['published_at']);

            return !$published || $published > $last_sent;
        }));
    }

    private static function notification_exists($identity, $title, $target_url) {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::notifications_table() . ' WHERE title = %s AND target_url = %s AND ((user_id IS NOT NULL AND user_id = %d) OR anon_key = %s) LIMIT 1',
            sanitize_text_field($title),
            esc_url_raw($target_url),
            (int) (!empty($identity['user_id']) ? $identity['user_id'] : 0),
            $identity['anon_key']
        ));
    }

    public static function register_device($data) {
        global $wpdb;

        self::maybe_install();

        $identity = self::identity($data['anon_key'] ?? '');
        $device_id = sanitize_text_field($data['device_id'] ?? '');

        if (!$device_id) {
            $device_id = substr(hash('sha256', $identity['anon_key'] . '|' . sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 64);
        }

        $push_token = sanitize_text_field($data['push_token'] ?? '');
        $endpoint = esc_url_raw($data['push_endpoint'] ?? '');
        $now = current_time('mysql', true);
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::devices_table() . ' WHERE device_id = %s LIMIT 1', $device_id));
        $payload = array(
            'user_id' => $identity['user_id'],
            'anon_key' => $identity['anon_key'],
            'device_id' => $device_id,
            'platform' => sanitize_key($data['platform'] ?? 'web'),
            'permission_status' => sanitize_key($data['permission_status'] ?? 'default'),
            'push_token_hash' => $push_token ? hash('sha256', $push_token . wp_salt('auth')) : '',
            'push_endpoint' => $endpoint,
            'app_version' => sanitize_text_field($data['app_version'] ?? ''),
            'user_agent' => sanitize_textarea_field($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'status' => 'active',
            'last_seen_at' => $now,
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update(self::devices_table(), $payload, array('id' => (int) $existing));
            return array('success' => true, 'device_id' => $device_id, 'registered' => 'updated');
        }

        $payload['created_at'] = $now;
        $wpdb->insert(self::devices_table(), $payload);

        return array('success' => true, 'device_id' => $device_id, 'registered' => 'created');
    }

    public static function notifications($anon_key = '', $limit = 20) {
        global $wpdb;

        self::maybe_install();

        $identity = self::identity($anon_key);

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::notifications_table() . ' WHERE ((user_id IS NOT NULL AND user_id = %d) OR anon_key = %s OR (user_id IS NULL AND (anon_key IS NULL OR anon_key = %s))) ORDER BY created_at DESC LIMIT %d',
            (int) ($identity['user_id'] ? $identity['user_id'] : 0),
            $identity['anon_key'],
            '',
            max(1, min(50, (int) $limit))
        ), ARRAY_A);
    }

    public static function update_notification_status($id, $status = 'read') {
        global $wpdb;

        self::maybe_install();

        $allowed = array('queued', 'sent', 'read', 'dismissed');
        $status = in_array($status, $allowed, true) ? $status : 'read';

        return (bool) $wpdb->update(self::notifications_table(), array(
            'status' => $status,
            'updated_at' => current_time('mysql', true),
            'sent_at' => 'read' === $status ? current_time('mysql', true) : null,
        ), array('id' => absint($id)));
    }

    public static function admin_summary() {
        global $wpdb;

        self::maybe_install();

        return array(
            'preferences' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::preferences_table()),
            'alerts' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::alerts_table() . " WHERE status = 'active'"),
            'queued_notifications' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::notifications_table() . " WHERE status = 'queued'"),
            'newsletter_subscribers' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::newsletters_table() . " WHERE status = 'active'"),
            'devices' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::devices_table() . " WHERE status = 'active'"),
        );
    }
}
