<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Beta {
    public static function feedback_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_beta_feedback';
    }

    public static function ranking_rules_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_ranking_rules';
    }

    public static function onboarding_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_publisher_onboarding';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $feedback = self::feedback_table();
        $rules = self::ranking_rules_table();
        $onboarding = self::onboarding_table();

        dbDelta("CREATE TABLE {$feedback} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feedback_type VARCHAR(50) NOT NULL DEFAULT 'general',
            rating TINYINT UNSIGNED NULL,
            requester_email VARCHAR(190) NULL,
            message TEXT NOT NULL,
            context_url TEXT NULL,
            query_text TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY feedback_type (feedback_type),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type VARCHAR(50) NOT NULL,
            target VARCHAR(190) NOT NULL,
            boost DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY rule_type (rule_type),
            KEY target (target),
            KEY status (status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$onboarding} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            publisher_name VARCHAR(190) NOT NULL,
            website_url TEXT NULL,
            contact_email VARCHAR(190) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'invited',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY contact_email (contact_email),
            KEY status (status)
        ) {$charset_collate};");

        update_option('rifnote_search_beta_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::feedback_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_beta_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function submit_feedback($data) {
        global $wpdb;

        self::maybe_install();

        $type = sanitize_key($data['feedback_type'] ?? 'general');
        $allowed = array('general', 'bug', 'ranking', 'publisher', 'mobile', 'ai');

        if (!in_array($type, $allowed, true)) {
            $type = 'general';
        }

        $message = sanitize_textarea_field($data['message'] ?? '');
        $email = !empty($data['requester_email']) ? sanitize_email($data['requester_email']) : '';
        $rating = isset($data['rating']) ? max(1, min(5, absint($data['rating']))) : null;

        if (!$message) {
            return new WP_Error('rifnote_feedback_required', __('Please add a short feedback note.', 'rifnote-search'), array('status' => 400));
        }

        if ($email && !is_email($email)) {
            return new WP_Error('rifnote_feedback_email_invalid', __('Enter a valid email or leave it blank.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::feedback_table(), array(
            'feedback_type' => $type,
            'rating' => $rating,
            'requester_email' => $email,
            'message' => $message,
            'context_url' => esc_url_raw($data['context_url'] ?? ''),
            'query_text' => sanitize_text_field($data['query_text'] ?? ''),
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return array(
            'success' => true,
            'feedback_id' => (int) $wpdb->insert_id,
            'message' => __('Thanks. Your beta feedback has been logged.', 'rifnote-search'),
        );
    }

    public static function recent_feedback($limit = 30) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::feedback_table() . ' ORDER BY created_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    public static function update_feedback_status($id, $status) {
        global $wpdb;

        $status = sanitize_key($status);

        if (!in_array($status, array('new', 'reviewing', 'done', 'dismissed'), true)) {
            return false;
        }

        return false !== $wpdb->update(self::feedback_table(), array(
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
    }

    public static function add_ranking_rule($data) {
        global $wpdb;

        self::maybe_install();

        $type = sanitize_key($data['rule_type'] ?? '');
        $allowed = array('keyword', 'category', 'source_domain', 'source_name');
        $target = sanitize_text_field($data['target'] ?? '');
        $boost = max(-0.5, min(0.5, (float) ($data['boost'] ?? 0)));

        if (!in_array($type, $allowed, true) || !$target || 0.0 === $boost) {
            return new WP_Error('rifnote_invalid_ranking_rule', __('Choose a rule type, target and non-zero boost.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::ranking_rules_table(), array(
            'rule_type' => $type,
            'target' => $target,
            'boost' => $boost,
            'status' => 'active',
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        self::bump_ranking_version();

        return (int) $wpdb->insert_id;
    }

    public static function set_ranking_rule_status($id, $status) {
        global $wpdb;

        $status = sanitize_key($status);

        if (!in_array($status, array('active', 'inactive'), true)) {
            return false;
        }

        $updated = $wpdb->update(self::ranking_rules_table(), array(
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));

        if (false !== $updated) {
            self::bump_ranking_version();
        }

        return false !== $updated;
    }

    public static function active_ranking_rules() {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::ranking_rules_table() . ' WHERE status = %s ORDER BY updated_at DESC LIMIT 100',
            'active'
        ), ARRAY_A);
    }

    public static function ranking_rules($include_inactive = true) {
        global $wpdb;

        self::maybe_install();

        $table = self::ranking_rules_table();

        if ($include_inactive) {
            return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A);
        }

        return self::active_ranking_rules();
    }

    public static function score_adjustment($result, $request_args) {
        $adjustment = 0.0;
        $query = strtolower((string) ($request_args['query'] ?? ''));
        $category = strtolower((string) ($result['category_slug'] ?? $result['category'] ?? ''));
        $source_domain = strtolower((string) ($result['source_domain'] ?? ''));
        $source_name = strtolower((string) ($result['source_name'] ?? ''));

        foreach (self::active_ranking_rules() as $rule) {
            $target = strtolower((string) $rule['target']);
            $boost = (float) $rule['boost'];

            if ('keyword' === $rule['rule_type'] && $query && false !== strpos($query, $target)) {
                $adjustment += $boost;
            }

            if ('category' === $rule['rule_type'] && $target === $category) {
                $adjustment += $boost;
            }

            if ('source_domain' === $rule['rule_type'] && $source_domain && false !== strpos($source_domain, $target)) {
                $adjustment += $boost;
            }

            if ('source_name' === $rule['rule_type'] && $source_name && false !== strpos($source_name, $target)) {
                $adjustment += $boost;
            }
        }

        return max(-0.5, min(0.5, $adjustment));
    }

    public static function add_publisher_target($data) {
        global $wpdb;

        self::maybe_install();

        $name = sanitize_text_field($data['publisher_name'] ?? '');
        $email = sanitize_email($data['contact_email'] ?? '');

        if (!$name || !$email || !is_email($email)) {
            return new WP_Error('rifnote_invalid_beta_publisher', __('Publisher name and valid email are required.', 'rifnote-search'), array('status' => 400));
        }

        $now = current_time('mysql', true);
        $wpdb->insert(self::onboarding_table(), array(
            'publisher_name' => $name,
            'website_url' => esc_url_raw($data['website_url'] ?? ''),
            'contact_email' => $email,
            'status' => sanitize_key($data['status'] ?? 'invited'),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function update_publisher_target_status($id, $status) {
        global $wpdb;

        $status = sanitize_key($status);

        if (!in_array($status, array('invited', 'onboarding', 'live', 'paused'), true)) {
            return false;
        }

        return false !== $wpdb->update(self::onboarding_table(), array(
            'status' => $status,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
    }

    public static function publisher_targets($limit = 30) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::onboarding_table() . ' ORDER BY updated_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    public static function summary() {
        global $wpdb;

        self::maybe_install();

        return array(
            'feedback_total' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::feedback_table()),
            'new_feedback' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::feedback_table() . ' WHERE status = %s', 'new')),
            'active_rules' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::ranking_rules_table() . ' WHERE status = %s', 'active')),
            'publisher_targets' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::onboarding_table()),
        );
    }

    public static function bump_ranking_version() {
        update_option('rifnote_search_ranking_version', (string) time(), false);
    }

    public static function ranking_version() {
        return (string) get_option('rifnote_search_ranking_version', '1');
    }
}
