<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Platform_Insights {
    public static function no_result_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_no_result_topics';
    }

    public static function timeline_notes_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_timeline_notes';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $no_results = self::no_result_table();
        $timeline_notes = self::timeline_notes_table();

        dbDelta("CREATE TABLE {$no_results} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            query_text VARCHAR(190) NOT NULL,
            category VARCHAR(100) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            notify_email VARCHAR(190) NULL,
            converted_topic VARCHAR(190) NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY query_text (query_text),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$timeline_notes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cluster_id VARCHAR(190) NOT NULL,
            note_time DATETIME NULL,
            label TEXT NOT NULL,
            source_name VARCHAR(190) NULL,
            source_url TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY cluster_id (cluster_id),
            KEY status (status)
        ) {$charset_collate};");

        update_option('rifnote_search_platform_insights_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::no_result_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_platform_insights_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function record_no_result($query, $category = '') {
        global $wpdb;

        self::maybe_install();

        $query = sanitize_text_field($query);

        if (!$query) {
            return false;
        }

        $table = self::no_result_table();
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE query_text = %s AND category = %s LIMIT 1', $query, sanitize_text_field($category)), ARRAY_A);
        $now = current_time('mysql', true);

        if ($existing) {
            return false !== $wpdb->update($table, array(
                'hit_count' => (int) $existing['hit_count'] + 1,
                'updated_at' => $now,
            ), array('id' => (int) $existing['id']));
        }

        return (bool) $wpdb->insert($table, array(
            'query_text' => $query,
            'category' => sanitize_text_field($category),
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public static function no_result_insights($query, $category = '') {
        $suggestions = Rifnote_Search_Index::suggestions($query, 6);
        $tokens = array_values(array_filter(explode(' ', Rifnote_Search_Source_Meta::normalize_headline($query))));
        $related = array();

        foreach ($tokens as $token) {
            $related = array_merge($related, Rifnote_Search_Index::suggestions($token, 3));
        }

        return array(
            'query' => sanitize_text_field($query),
            'category' => sanitize_text_field($category),
            'suggestions' => array_values(array_slice($suggestions, 0, 6)),
            'related_topics' => array_values(array_slice($related, 0, 6)),
            'can_notify' => true,
            'message' => __('Nothing solid yet. Try a nearby topic or leave an alert and we’ll let you know when something lands.', 'rifnote-search'),
        );
    }

    public static function subscribe_no_result($query, $email, $category = '') {
        global $wpdb;

        self::maybe_install();

        $query = sanitize_text_field($query);
        $email = sanitize_email($email);

        if (!$query || !$email || !is_email($email)) {
            return new WP_Error('rifnote_invalid_no_result_subscription', __('Enter a valid query and email.', 'rifnote-search'), array('status' => 400));
        }

        self::record_no_result($query, $category);

        $wpdb->update(self::no_result_table(), array(
            'notify_email' => $email,
            'status' => 'notify_requested',
            'updated_at' => current_time('mysql', true),
        ), array('query_text' => $query, 'category' => sanitize_text_field($category)));

        return array('success' => true, 'message' => __('Alert request saved for this no-result query.', 'rifnote-search'));
    }

    public static function recent_no_results($limit = 20) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::no_result_table() . ' ORDER BY hit_count DESC, updated_at DESC LIMIT %d',
            max(1, min(100, (int) $limit))
        ), ARRAY_A);
    }

    public static function convert_no_result($id, $topic = '') {
        global $wpdb;

        self::maybe_install();

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::no_result_table() . ' WHERE id = %d', (int) $id), ARRAY_A);

        if (!$row) {
            return false;
        }

        $topic = $topic ? sanitize_text_field($topic) : $row['query_text'];
        Rifnote_Search_Trending::upsert_topic($topic, array('score' => max(1, (int) $row['hit_count']), 'source' => 'no_result'));

        return false !== $wpdb->update(self::no_result_table(), array(
            'status' => 'converted',
            'converted_topic' => $topic,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
    }

    public static function add_timeline_note($data) {
        global $wpdb;

        self::maybe_install();

        $cluster_id = sanitize_text_field($data['cluster_id'] ?? '');
        $label = sanitize_textarea_field($data['label'] ?? '');

        if (!$cluster_id || !$label) {
            return new WP_Error('rifnote_invalid_timeline_note', __('Cluster ID and note text are required.', 'rifnote-search'), array('status' => 400));
        }

        $note_time = !empty($data['note_time']) ? gmdate('Y-m-d H:i:s', strtotime($data['note_time'])) : current_time('mysql', true);
        $now = current_time('mysql', true);

        $wpdb->insert(self::timeline_notes_table(), array(
            'cluster_id' => $cluster_id,
            'note_time' => $note_time,
            'label' => $label,
            'source_name' => sanitize_text_field($data['source_name'] ?? __('Editorial note', 'rifnote-search')),
            'source_url' => esc_url_raw($data['source_url'] ?? ''),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return (int) $wpdb->insert_id;
    }

    public static function timeline_notes($cluster_id) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::timeline_notes_table() . ' WHERE cluster_id = %s AND status = %s ORDER BY note_time ASC LIMIT 50',
            sanitize_text_field($cluster_id),
            'active'
        ), ARRAY_A);
    }
}
