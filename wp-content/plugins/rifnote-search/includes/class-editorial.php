<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Editorial {
    public static function audit_table() {
        global $wpdb;
        return $wpdb->prefix . 'rifnote_editorial_audit';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::audit_table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(80) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY object_ref (object_type, object_id),
            KEY action (action),
            KEY user_id (user_id)
        ) {$charset_collate};");

        update_option('rifnote_search_editorial_db_version', RIFNOTE_SEARCH_VERSION);
    }

    public static function maybe_install() {
        global $wpdb;

        $table = self::audit_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (get_option('rifnote_search_editorial_db_version') !== RIFNOTE_SEARCH_VERSION || $exists !== $table) {
            self::install();
        }
    }

    public static function log($object_type, $object_id, $action, $notes = '', $metadata = array()) {
        global $wpdb;

        self::maybe_install();

        $wpdb->insert(self::audit_table(), array(
            'object_type' => sanitize_key($object_type),
            'object_id' => absint($object_id),
            'action' => sanitize_key($action),
            'user_id' => get_current_user_id() ? get_current_user_id() : null,
            'notes' => sanitize_textarea_field($notes),
            'metadata' => wp_json_encode(is_array($metadata) ? $metadata : array()),
            'created_at' => current_time('mysql', true),
        ));

        return (int) $wpdb->insert_id;
    }

    public static function assign_submission($submission_id, $user_id, $notes = '') {
        return self::log('submission', $submission_id, 'assigned', $notes, array('assignee' => absint($user_id)));
    }

    public static function recent_audit($limit = 30) {
        global $wpdb;

        self::maybe_install();

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::audit_table() . ' ORDER BY created_at DESC LIMIT %d', max(1, min(100, (int) $limit))), ARRAY_A);
    }
}
