<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Legal {
    public static function requests_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_legal_requests';
    }

    public static function blocked_domains_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_blocked_domains';
    }

    public static function audit_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_audit_log';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $requests = self::requests_table();
        $blocked = self::blocked_domains_table();
        $audit = self::audit_table();

        dbDelta("CREATE TABLE {$requests} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_type VARCHAR(50) NOT NULL DEFAULT 'dmca',
            requester_name VARCHAR(190) NOT NULL,
            requester_email VARCHAR(190) NOT NULL,
            organization VARCHAR(190) NULL,
            domain VARCHAR(190) NULL,
            url TEXT NULL,
            publisher_id BIGINT UNSIGNED NULL,
            details TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            admin_notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY request_type (request_type),
            KEY requester_email (requester_email),
            KEY domain (domain),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$blocked} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            domain VARCHAR(190) NOT NULL,
            reason TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain (domain),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$audit} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(100) NOT NULL,
            object_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            summary TEXT NOT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY action (action),
            KEY object_type (object_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) {$charset_collate};");

        update_option('rifnote_search_legal_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::requests_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_legal_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function normalize_domain($url_or_domain) {
        $value = trim(strtolower((string) $url_or_domain));
        $value = preg_replace('/^\s*mailto:/', '', $value);

        if (!$value) {
            return '';
        }

        if (false === strpos($value, '://')) {
            $value = 'https://' . $value;
        }

        $host = wp_parse_url($value, PHP_URL_HOST);
        $host = $host ? strtolower($host) : '';
        $host = preg_replace('/^www\./', '', $host);

        return sanitize_text_field($host);
    }

    public static function is_domain_blocked($url_or_domain) {
        global $wpdb;

        self::maybe_install();

        $domain = self::normalize_domain($url_or_domain);

        if (!$domain) {
            return false;
        }

        $blocked = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::blocked_domains_table() . ' WHERE domain = %s AND status = %s LIMIT 1',
            $domain,
            'active'
        ));

        return !empty($blocked);
    }

    public static function blocked_domains($include_inactive = false) {
        global $wpdb;

        self::maybe_install();

        $table = self::blocked_domains_table();

        if ($include_inactive) {
            return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A);
        }

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT 100", 'active'), ARRAY_A);
    }

    public static function block_domain($domain, $reason = '') {
        global $wpdb;

        self::maybe_install();

        $domain = self::normalize_domain($domain);

        if (!$domain) {
            return new WP_Error('rifnote_invalid_domain', __('Enter a valid domain to block.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $data = array(
            'domain' => $domain,
            'reason' => sanitize_textarea_field($reason),
            'status' => 'active',
            'created_by' => get_current_user_id(),
            'updated_at' => $now,
        );

        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::blocked_domains_table() . ' WHERE domain = %s LIMIT 1', $domain));

        if ($existing) {
            $wpdb->update(self::blocked_domains_table(), $data, array('id' => (int) $existing));
            self::audit('domain_blocked', 'blocked_domain', (int) $existing, sprintf('Reactivated blocked domain %s.', $domain), array('domain' => $domain));
            return (int) $existing;
        }

        $data['created_at'] = $now;
        $wpdb->insert(self::blocked_domains_table(), $data);
        $id = (int) $wpdb->insert_id;
        self::audit('domain_blocked', 'blocked_domain', $id, sprintf('Blocked domain %s.', $domain), array('domain' => $domain));

        return $id;
    }

    public static function set_blocked_domain_status($id, $status) {
        global $wpdb;

        $status = sanitize_key($status);

        if (!in_array($status, array('active', 'inactive'), true)) {
            return false;
        }

        $updated = $wpdb->update(self::blocked_domains_table(), array(
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));

        if (false !== $updated) {
            self::audit('blocked_domain_status_changed', 'blocked_domain', (int) $id, sprintf('Blocked domain status changed to %s.', $status), array('status' => $status));
        }

        return false !== $updated;
    }

    public static function submit_request($data) {
        global $wpdb;

        self::maybe_install();

        $type = sanitize_key($data['request_type'] ?? 'dmca');
        $allowed = array('dmca', 'opt_out', 'correction', 'other');

        if (!in_array($type, $allowed, true)) {
            $type = 'dmca';
        }

        $name = sanitize_text_field($data['requester_name'] ?? '');
        $email = sanitize_email($data['requester_email'] ?? '');
        $details = sanitize_textarea_field($data['details'] ?? '');
        $url = !empty($data['url']) ? esc_url_raw($data['url']) : '';
        $domain = self::normalize_domain($data['domain'] ?? ($url ? $url : ''));

        if (!$name || !$email || !is_email($email) || !$details) {
            return new WP_Error('rifnote_legal_missing_fields', __('Name, valid email and request details are required.', 'rifnote-search'), array('status' => 400));
        }

        if ('opt_out' === $type && !$domain) {
            return new WP_Error('rifnote_legal_domain_required', __('Publisher opt-out requests require a domain.', 'rifnote-search'), array('status' => 400));
        }

        if ('dmca' === $type && !$url && !$domain) {
            return new WP_Error('rifnote_legal_url_required', __('DMCA requests require a URL or domain.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::requests_table(), array(
            'request_type' => $type,
            'requester_name' => $name,
            'requester_email' => $email,
            'organization' => sanitize_text_field($data['organization'] ?? ''),
            'domain' => $domain,
            'url' => $url,
            'publisher_id' => isset($data['publisher_id']) ? absint($data['publisher_id']) : null,
            'details' => $details,
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        $id = (int) $wpdb->insert_id;
        self::audit('legal_request_submitted', 'legal_request', $id, sprintf('New %s request submitted for %s.', $type, $domain ? $domain : $url), array('request_type' => $type, 'domain' => $domain, 'url' => $url));

        return array(
            'success' => true,
            'request_id' => $id,
            'status' => 'new',
            'message' => __('Request received. Rifnote will review it and follow up by email.', 'rifnote-search'),
        );
    }

    public static function recent_requests($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::requests_table() . ' ORDER BY created_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    public static function update_request_status($id, $status, $notes = '') {
        global $wpdb;

        $status = sanitize_key($status);

        if (!in_array($status, array('new', 'reviewing', 'resolved', 'rejected'), true)) {
            return false;
        }

        $updated = $wpdb->update(self::requests_table(), array(
            'status' => $status,
            'admin_notes' => sanitize_textarea_field($notes),
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));

        if (false !== $updated) {
            self::audit('legal_request_status_changed', 'legal_request', (int) $id, sprintf('Legal request status changed to %s.', $status), array('status' => $status));
        }

        return false !== $updated;
    }

    public static function robots_allowed($url, $user_agent = 'RifnoteBot') {
        $url = esc_url_raw((string) $url);
        $host = wp_parse_url($url, PHP_URL_HOST);
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $path = wp_parse_url($url, PHP_URL_PATH);

        if (!$host || !in_array($scheme, array('http', 'https'), true)) {
            return false;
        }

        $robots_url = $scheme . '://' . $host . '/robots.txt';
        $response = wp_remote_get($robots_url, array(
            'timeout' => 8,
            'redirection' => 2,
            'user-agent' => $user_agent . '/1.0; +' . home_url('/'),
        ));

        if (is_wp_error($response)) {
            return true;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if (404 === $status || 410 === $status) {
            return true;
        }

        if ($status < 200 || $status >= 300) {
            return true;
        }

        return self::robots_rules_allow((string) wp_remote_retrieve_body($response), $path ? $path : '/', $user_agent);
    }

    private static function robots_rules_allow($robots, $path, $user_agent) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $robots);
        $agent = strtolower($user_agent);
        $active = false;
        $rules = array();

        foreach ($lines as $line) {
            $line = preg_replace('/#.*/', '', $line);
            $line = trim((string) $line);

            if (!$line || false === strpos($line, ':')) {
                continue;
            }

            list($key, $value) = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            $value = trim($value);

            if ('user-agent' === $key) {
                $value = strtolower($value);
                $active = ('*' === $value || false !== strpos($agent, $value) || false !== strpos($value, $agent));
                continue;
            }

            if ($active && in_array($key, array('allow', 'disallow'), true)) {
                $rules[] = array('type' => $key, 'path' => $value);
            }
        }

        $winner = null;

        foreach ($rules as $rule) {
            if ('' === $rule['path']) {
                continue;
            }

            if (0 === strpos($path, $rule['path'])) {
                if (!$winner || strlen($rule['path']) > strlen($winner['path']) || (strlen($rule['path']) === strlen($winner['path']) && 'allow' === $rule['type'])) {
                    $winner = $rule;
                }
            }
        }

        return !$winner || 'disallow' !== $winner['type'];
    }

    public static function audit($action, $object_type, $object_id, $summary, $metadata = array()) {
        global $wpdb;

        self::maybe_install();

        return (bool) $wpdb->insert(self::audit_table(), array(
            'action' => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id' => $object_id ? (int) $object_id : null,
            'actor_user_id' => get_current_user_id(),
            'summary' => sanitize_textarea_field($summary),
            'metadata' => wp_json_encode(is_array($metadata) ? $metadata : array()),
            'created_at' => current_time('mysql', true),
        ));
    }
}
