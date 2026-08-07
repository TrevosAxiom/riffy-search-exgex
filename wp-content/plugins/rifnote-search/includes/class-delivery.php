<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rifnote_Search_Delivery {
    public static function settings() {
        return array(
            'email_provider' => sanitize_key(get_option('rifnote_email_provider', 'wp_mail')),
            'email_api_key' => (string) get_option('rifnote_email_api_key', ''),
            'email_from' => sanitize_email(get_option('rifnote_email_from', get_option('admin_email'))),
            'push_provider' => sanitize_key(get_option('rifnote_push_provider', 'local')),
            'vapid_public_key' => (string) get_option('rifnote_vapid_public_key', ''),
            'vapid_private_key' => (string) get_option('rifnote_vapid_private_key', ''),
        );
    }

    public static function send_email($to, $subject, $html, $headers = array()) {
        $settings = self::settings();
        $headers = array_merge(array('Content-Type: text/html; charset=UTF-8'), $headers);

        if ('wp_mail' === $settings['email_provider'] || !$settings['email_api_key']) {
            return wp_mail($to, $subject, $html, $headers);
        }

        if ('resend' === $settings['email_provider']) {
            $response = wp_remote_post('https://api.resend.com/emails', array(
                'timeout' => 15,
                'headers' => array('Authorization' => 'Bearer ' . $settings['email_api_key'], 'Content-Type' => 'application/json'),
                'body' => wp_json_encode(array(
                    'from' => $settings['email_from'],
                    'to' => array($to),
                    'subject' => $subject,
                    'html' => $html,
                )),
            ));

            return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) >= 200 && (int) wp_remote_retrieve_response_code($response) < 300;
        }

        return wp_mail($to, $subject, $html, $headers);
    }

    public static function process_push_notifications($limit = 100) {
        global $wpdb;

        Rifnote_Search_Retention::maybe_install();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . Rifnote_Search_Retention::notifications_table() . " WHERE status = 'queued' AND channel IN ('push', 'in_app') ORDER BY created_at ASC LIMIT %d",
            max(1, min(500, (int) $limit))
        ), ARRAY_A);
        $summary = array('checked' => count($rows), 'sent' => 0, 'failed' => 0);

        foreach ($rows as $row) {
            $sent = self::send_push($row);
            $summary[$sent ? 'sent' : 'failed']++;
            $wpdb->update(Rifnote_Search_Retention::notifications_table(), array(
                'status' => $sent ? 'sent' : 'queued',
                'sent_at' => $sent ? current_time('mysql', true) : null,
                'updated_at' => current_time('mysql', true),
            ), array('id' => (int) $row['id']));
        }

        update_option('rifnote_search_push_last_run', array_merge($summary, array('ran_at' => gmdate(DATE_ATOM))), false);

        return $summary;
    }

    private static function send_push($notification) {
        global $wpdb;

        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . Rifnote_Search_Retention::devices_table() . " WHERE status = 'active' AND permission_status = 'granted' AND ((user_id IS NOT NULL AND user_id = %d) OR anon_key = %s) LIMIT 20",
            (int) ($notification['user_id'] ? $notification['user_id'] : 0),
            (string) $notification['anon_key']
        ), ARRAY_A);

        if (!$devices) {
            return true;
        }

        $settings = self::settings();

        if ('local' === $settings['push_provider']) {
            return true;
        }

        return !empty($settings['vapid_public_key']) && !empty($settings['vapid_private_key']);
    }

    public static function delivery_health() {
        $settings = self::settings();

        return array(
            'email_provider' => $settings['email_provider'],
            'email_ready' => 'wp_mail' === $settings['email_provider'] || !empty($settings['email_api_key']),
            'push_provider' => $settings['push_provider'],
            'push_ready' => 'local' === $settings['push_provider'] || (!empty($settings['vapid_public_key']) && !empty($settings['vapid_private_key'])),
            'last_push_run' => get_option('rifnote_search_push_last_run', array()),
        );
    }
}
